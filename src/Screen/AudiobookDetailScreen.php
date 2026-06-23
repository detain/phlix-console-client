<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Audiobook;
use Phlix\Console\Api\Dto\AudiobookChapter;
use Phlix\Console\Api\Dto\AudiobookProgress;
use Phlix\Console\Msg\AudiobookChaptersLoadedMsg;
use Phlix\Console\Msg\AudiobookFailedMsg;
use Phlix\Console\Msg\AudiobookLoadedMsg;
use Phlix\Console\Msg\AudiobookProgressLoadedMsg;
use Phlix\Console\Msg\AudiobookTickMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Store\AudiobooksStore;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\Table;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\Util\Width;
use SugarCraft\Reel\AudioPlayer;

/**
 * A single audiobook's detail: a metadata header (by author · narrated by
 * narrator, then series · duration · language) above a borderless chapter
 * table (# · Chapter · Duration) rendered via {@see Table} with reverse-video
 * row selection. Audiobooks have no usable cover server-side (the `cover_url`
 * is a raw filesystem path), so the screen is text-forward.
 *
 * The detail ({@see AudiobooksStore::audiobook}), the chapter list
 * ({@see AudiobooksStore::chapters}) and the saved progress
 * ({@see AudiobooksStore::progress}) are fetched concurrently in {@see init()}.
 * A non-auth chapters error degrades gracefully to an empty chapter list (the
 * metadata still shows); a non-auth progress error is swallowed (no resume
 * offered); an auth failure on any of the three surfaces as a session expiry so
 * the App can re-authenticate.
 *
 * Enter plays the selected chapter over the audiobook's ONE signed `stream_url`
 * (already on the loaded detail) — so play is SYNCHRONOUS (no per-item fetch):
 * chapters are seek markers into that single stream, and {@see AudioPlayer}'s
 * second ctor argument is a start offset in MILLISECONDS, so chapter-seek and
 * resume are just a rebuild of the player at a `startMs`. Playback is
 * screen-local: it plays while the audiobook is open and stops on leave (Esc/q,
 * a stack pop, or Ctrl-C), because the screen is {@see Teardownable} and the App
 * tears down a popped frame. AudioPlayer exposes no playhead clock, so the
 * elapsed position is ESTIMATED by counting 1-second {@see AudiobookTickMsg}s
 * while playing, and progress is reported (POSTed) throttled off that count.
 *
 * ↑/↓ move the chapter selection (independent of where playback is); Enter
 * plays the selected chapter (or toggles pause when it is the playing one); `r`
 * resumes from the saved position; Space toggles pause; Esc/q go back. Stable
 * collaborators are readonly; mutable view state is private and copied via
 * clone-mutate — only {@see teardown()} and {@see stopPlaybackInPlace()} mutate
 * `$this` in place (the player lifecycle, exactly like AlbumScreen).
 */
final class AudiobookDetailScreen implements Breadcrumbed, Teardownable, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const PLAY_FAILED = 'Cannot play this audiobook';
    private const HINT = '↑↓  select   ⏎  play   r  resume   space  pause   Esc  back';
    private const NUM_WIDTH = 5;
    private const DURATION_WIDTH = 10;
    private const PART_SEPARATOR = '   ·   ';

    private ?Audiobook $audiobook = null;
    private bool $loaded = false;
    /** @var list<AudiobookChapter> */
    private array $chapters = [];
    private bool $chaptersLoaded = false;
    private int $selected = 0;
    private ?string $error = null;
    /** @var list<string> */
    private array $crumbs = [];

    /** True while a chapter/stream is playing. */
    private bool $playing = false;
    private bool $paused = false;
    /** Estimated absolute position in MILLISECONDS (counted from 1s ticks). */
    private int $positionMs = 0;
    private ?AudioPlayer $audio = null;
    /**
     * The current heartbeat generation. Bumped on every (re)start of playback
     * (play/chapter-seek/resume/finish) so a tick armed by a superseded chain is
     * dropped as stale — guarding against two heartbeats running at once.
     */
    private int $audioEpoch = 0;
    /** The saved resume position in ms, or null when there is none to resume. */
    private ?int $resumeMs = null;
    /** Ticks since the last throttled progress report (resets every ~10). */
    private int $ticksSinceReport = 0;
    private bool $tornDown = false;

    /**
     * @param \Closure(string $url, ?int $startMs): AudioPlayer $audioFactory
     *        Builds the audio player for a resolved URL + start offset (injected
     *        so tests use a recording fake instead of spawning ffplay/mpv).
     */
    public function __construct(
        private readonly AudiobooksStore $store,
        private readonly string $baseUrl,
        private readonly \Closure $audioFactory,
        private readonly string $id,
        private readonly string $title,
        private int $cols = 80,
        private int $rows = 24,
    ) {
    }

    /**
     * The real factory: a sugar-reel {@see AudioPlayer} over the resolved stream
     * URL, seeking to `$startMs` (it spawns ffplay/mpv on start(), or silently
     * no-ops if neither is installed).
     *
     * @return \Closure(string $url, ?int $startMs): AudioPlayer
     */
    public static function productionAudioFactory(): \Closure
    {
        return static fn (string $url, ?int $startMs): AudioPlayer => new AudioPlayer($url, $startMs);
    }

    public function init(): ?\Closure
    {
        // Three concurrent fetches: the detail (author/narrator/series/duration +
        // the signed stream URL), the chapter list, and the saved progress. The
        // chapter fetch degrades to empty on a non-auth error; the progress fetch
        // is swallowed on a non-auth error (no resume offered).
        return Cmd::batch($this->fetchDetail(), $this->fetchChapters(), $this->fetchProgress());
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [$this->resizedTo($msg->cols, $msg->rows), null];
        }
        if ($msg instanceof AudiobookLoadedMsg) {
            return [$this->withAudiobook($msg->audiobook), null];
        }
        if ($msg instanceof AudiobookChaptersLoadedMsg) {
            return [$this->withChapters($msg->chapters), null];
        }
        if ($msg instanceof AudiobookProgressLoadedMsg) {
            return [$this->withProgress($msg->progress), null];
        }
        if ($msg instanceof AudiobookFailedMsg) {
            return [$this->withError($msg->reason), null];
        }
        if ($msg instanceof AudiobookTickMsg) {
            return $this->onAudiobookTick($msg->epoch);
        }
        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }

        return [$this, null];
    }

    public function view(): string
    {
        if ($this->error !== null) {
            return Chrome::frame($this->headerTitle(), "\n  {$this->error}", self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
        }
        if (!$this->loaded || $this->audiobook === null) {
            return Chrome::frame($this->headerTitle(), "\n  Loading…", self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
        }

        return Chrome::frame($this->headerTitle(), $this->body($this->audiobook), self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
    }

    // ---- data ----------------------------------------------------------

    private function fetchDetail(): \Closure
    {
        return Cmd::promise(fn () => $this->store->audiobook($this->id)->then(
            static fn (Audiobook $audiobook): Msg => new AudiobookLoadedMsg($audiobook),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AudiobookFailedMsg('Could not load this audiobook.'),
        ));
    }

    private function fetchChapters(): \Closure
    {
        return Cmd::promise(fn () => $this->store->chapters($this->id)->then(
            static fn (array $chapters): Msg => new AudiobookChaptersLoadedMsg($chapters),
            // A non-auth chapters error degrades to an empty chapter list (the
            // meta still shows); an auth failure still re-authenticates.
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AudiobookChaptersLoadedMsg([]),
        ));
    }

    /**
     * Resolve the saved progress → {@see AudiobookProgressLoadedMsg}. An auth
     * failure surfaces as a session expiry; ANY other error is swallowed
     * (returns null → no Msg dispatched, the established swallow idiom), so a
     * progress hiccup simply means no resume is offered — never a screen error.
     */
    private function fetchProgress(): \Closure
    {
        return Cmd::promise(fn (): PromiseInterface => $this->store->progress($this->id)->then(
            static fn (AudiobookProgress $progress): Msg => new AudiobookProgressLoadedMsg($progress),
            static fn (\Throwable $e): ?Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : null,
        ));
    }

    // ---- input ---------------------------------------------------------

    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            // Save a final position (best-effort) and stop the audio before
            // popping (mirrors AlbumScreen) so leaving never leaks ffplay/mpv.
            $report = $this->playing ? $this->reportProgressCmd() : null;
            $this->teardown();

            return [$this, $report !== null
                ? Cmd::batch($report, Cmd::send(new NavigateBackMsg()))
                : Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Char && $msg->rune === ' ') {
            return $this->togglePause();
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'r') {
            // Resume from the saved position, if there is one.
            return $this->resumeMs !== null ? $this->playFrom($this->resumeMs) : [$this, null];
        }
        if ($msg->type === KeyType::Enter) {
            // Enter on the already-playing chapter toggles pause; otherwise it
            // plays the selected chapter from its start (or 0 with no chapters).
            if ($this->chapters !== [] && $this->playing && $this->selected === $this->currentChapterIndex()) {
                return $this->togglePause();
            }

            return $this->playFrom($this->selectedChapterStartMs());
        }
        if ($msg->type === KeyType::Up) {
            return [$this->moveSelection(-1), null];
        }
        if ($msg->type === KeyType::Down) {
            return [$this->moveSelection(1), null];
        }

        return [$this, null];
    }

    /** The selected chapter's start offset in ms, or 0 when there are no chapters. */
    private function selectedChapterStartMs(): int
    {
        return $this->chapters[$this->selected]->startMs ?? 0;
    }

    private function moveSelection(int $delta): self
    {
        $count = count($this->chapters);
        if ($count === 0) {
            return $this;
        }
        $selected = max(0, min($count - 1, $this->selected + $delta));
        if ($selected === $this->selected) {
            return $this;
        }
        $next = clone $this;
        $next->selected = $selected;

        return $next;
    }

    // ---- audio lifecycle -----------------------------------------------

    /**
     * (Re)build the player over the one signed stream URL, seeking to `$startMs`,
     * and start a fresh heartbeat. A missing stream URL surfaces an error toast
     * (and plays nothing). The play is SYNCHRONOUS — the URL is already on the
     * loaded detail, so there is no per-item fetch.
     */
    private function playFrom(int $startMs): array
    {
        if ($this->audiobook === null || $this->audiobook->streamUrl === null || $this->audiobook->streamUrl === '') {
            return [$this, Cmd::send(ShowToastMsg::error(self::PLAY_FAILED))];
        }

        $this->audio?->stop();
        $player = ($this->audioFactory)($this->resolveUrl($this->audiobook->streamUrl), $startMs);
        $player->start();

        $next = clone $this;
        $next->audio = $player;
        $next->playing = true;
        $next->paused = false;
        $next->positionMs = $startMs;
        $next->ticksSinceReport = 0;
        // A fresh heartbeat generation for the (re)started stream.
        $next->audioEpoch = $this->audioEpoch + 1;

        return [$next, $this->tickCmd($next->audioEpoch)];
    }

    /** Toggle pause on the playing stream; a no-op when nothing is playing. */
    private function togglePause(): array
    {
        if (!$this->playing || $this->audio === null) {
            return [$this, null];
        }

        $next = clone $this;
        $next->paused = !$this->paused;
        // Bump the epoch either way: pausing must invalidate the in-flight tick
        // (so it can't fire once more after pause), and resuming starts a fresh
        // heartbeat that no leftover tick can double.
        $next->audioEpoch = $this->audioEpoch + 1;
        if ($next->paused) {
            $this->audio->pause();

            // Persist the paused position (best-effort); stop the tick.
            return [$next, $next->reportProgressCmd()];
        }

        $this->audio->resume();

        return [$next, $this->tickCmd($next->audioEpoch)];
    }

    /**
     * One playback second elapsed: advance the estimated position and re-arm the
     * tick. At/after the audiobook's known duration the book finishes (stop +
     * a final ~100% report); every ~10 ticks a throttled progress save fires.
     * Ignored when not playing, paused, or for a superseded heartbeat.
     */
    private function onAudiobookTick(int $epoch): array
    {
        // Drop a tick from a superseded heartbeat, or when not actively playing.
        if ($epoch !== $this->audioEpoch || !$this->playing || $this->paused) {
            return [$this, null];
        }

        $next = clone $this;
        $next->positionMs = $this->positionMs + 1000;
        $next->ticksSinceReport = $this->ticksSinceReport + 1;

        $duration = $this->audiobook?->durationMs;
        if ($duration !== null && $next->positionMs >= $duration) {
            // The book finished — stop and fire a final report at ~100%.
            $next->stopPlaybackInPlace();

            return [$next, $next->reportProgressCmd()];
        }

        if ($next->ticksSinceReport >= 10) {
            // Throttled ~10s save: keep the heartbeat going AND persist progress.
            $next->ticksSinceReport = 0;

            return [$next, Cmd::batch($this->tickCmd($next->audioEpoch), $next->reportProgressCmd())];
        }

        // Continue the SAME generation (no bump here).
        return [$next, $this->tickCmd($next->audioEpoch)];
    }

    /**
     * Stop and clear playback on this instance (only ever called on a freshly
     * cloned screen, like AlbumScreen's in-place stop).
     */
    private function stopPlaybackInPlace(): void
    {
        $this->audio?->stop();
        $this->audio = null;
        $this->playing = false;
        $this->paused = false;
        // Invalidate any tick still in flight so it can't resurrect playback.
        $this->audioEpoch++;
    }

    public function teardown(): void
    {
        if ($this->tornDown) {
            return;
        }
        $this->tornDown = true;
        $this->audio?->stop();
    }

    private function tickCmd(int $epoch): \Closure
    {
        return Cmd::tick(1.0, static fn (): Msg => new AudiobookTickMsg($epoch));
    }

    /**
     * Persist the current position fire-and-forget: a failed save NEVER disrupts
     * playback (both arms swallow to null). Reads `$this`'s current state, so it
     * must be called on the screen whose position should be saved.
     */
    private function reportProgressCmd(): \Closure
    {
        return Cmd::promise(fn (): PromiseInterface => $this->store->saveProgress(
            $this->id,
            $this->positionMs,
            $this->currentChapterIndex(),
            $this->completedChapterIndices(),
            $this->percentComplete(),
        )->then(static fn (): ?Msg => null, static fn (): ?Msg => null));
    }

    /**
     * The index of the chapter whose [startMs, endMs) contains the current
     * position (or the last chapter starting at/before it); 0 when no chapters.
     */
    private function currentChapterIndex(): int
    {
        if ($this->chapters === []) {
            return 0;
        }

        $index = 0;
        foreach ($this->chapters as $i => $chapter) {
            if ($this->positionMs >= $chapter->startMs && ($chapter->endMs <= 0 || $this->positionMs < $chapter->endMs)) {
                return $i;
            }
            if ($chapter->startMs <= $this->positionMs) {
                $index = $i;
            }
        }

        return $index;
    }

    /**
     * The indices of chapters fully behind the current position.
     *
     * @return list<int>
     */
    private function completedChapterIndices(): array
    {
        $completed = [];
        foreach ($this->chapters as $i => $chapter) {
            if ($chapter->endMs > 0 && $chapter->endMs <= $this->positionMs) {
                $completed[] = $i;
            }
        }

        return $completed;
    }

    /** The 0–100 completion percentage from the position over the duration. */
    private function percentComplete(): float
    {
        $duration = $this->audiobook?->durationMs;

        return $duration !== null && $duration > 0
            ? min(100.0, $this->positionMs / $duration * 100)
            : 0.0;
    }

    /** Resolve a (possibly relative) URL against the server base; absolute/empty pass through. */
    private function resolveUrl(string $url): string
    {
        if ($url === '' || preg_match('#^https?://#i', $url) === 1) {
            return $url; // empty, or already absolute (signed URLs are absolute)
        }

        return rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/');
    }

    // ---- rendering -----------------------------------------------------

    private function body(Audiobook $book): string
    {
        $headerLines = $this->headerLines($book);
        $head = $headerLines === [] ? '' : implode("\n", $headerLines) . "\n";
        $head .= "\n"; // the blank separating the header from the table

        if ($this->chapters === []) {
            return $head . '  No chapters.';
        }

        return $head . $this->chapterTable(count($headerLines));
    }

    /**
     * The header region above the chapter table: while playing, a single
     * now-playing line; otherwise the metadata lines plus (when there is a saved
     * position) a resume hint. Width-truncated to the content width, ANSI-free.
     *
     * @return list<string>
     */
    private function headerLines(Audiobook $book): array
    {
        $width = max(1, $this->cols - 4);

        if ($this->playing) {
            return [Width::truncate($this->nowPlayingLine($book), $width)];
        }

        $lines = $this->metaLines($book);
        if ($this->resumeMs !== null) {
            $lines[] = Width::truncate('↺ Resume from ' . self::clock($this->resumeMs) . '  (press r)', $width);
        }

        return $lines;
    }

    /** The ▶/⏸ now-playing line: current chapter title + position / total. */
    private function nowPlayingLine(Audiobook $book): string
    {
        $glyph = $this->paused ? '⏸ ' : '▶ ';
        $title = $this->chapters[$this->currentChapterIndex()]->title ?? $book->title;
        $total = $book->durationMs !== null ? self::clock($book->durationMs) : '—';

        return $glyph . $title . '   ' . self::clock($this->positionMs) . ' / ' . $total;
    }

    /**
     * The (0–2) metadata lines: "by AUTHOR · narrated by NARRATOR", then
     * "SERIES #N · DURATION · LANGUAGE". Empty parts (and wholly-empty lines)
     * are omitted.
     *
     * @return list<string>
     */
    private function metaLines(Audiobook $book): array
    {
        $lines = [];

        $line1 = [];
        if ($book->author !== null && $book->author !== '') {
            $line1[] = 'by ' . $book->author;
        }
        if ($book->narrator !== null && $book->narrator !== '') {
            $line1[] = 'narrated by ' . $book->narrator;
        }
        if ($line1 !== []) {
            $lines[] = implode(self::PART_SEPARATOR, $line1);
        }

        $line2 = [];
        if ($book->series !== null && $book->series !== '') {
            $line2[] = $book->seriesPosition !== null
                ? $book->series . ' #' . $book->seriesPosition
                : $book->series;
        }
        $duration = $book->durationLabel();
        if ($duration !== '') {
            $line2[] = $duration;
        }
        if ($book->language !== null && $book->language !== '') {
            $line2[] = $book->language;
        }
        if ($line2 !== []) {
            $lines[] = implode(self::PART_SEPARATOR, $line2);
        }

        return $lines;
    }

    private function chapterTable(int $headerLineCount): string
    {
        $rows = [];
        foreach ($this->chapters as $chapter) {
            // A chapter's durationMs is a non-null int, so durationLabel() is
            // always a real clock (0ms → "0:00") — no empty/dash case here.
            $rows[] = [
                (string) ($chapter->index + 1),
                $chapter->title,
                $chapter->durationLabel(),
            ];
        }

        return Table::render([
            ['title' => '#', 'width' => self::NUM_WIDTH, 'align' => 'right'],
            ['title' => 'Chapter', 'width' => 0],
            ['title' => 'Duration', 'width' => self::DURATION_WIDTH, 'align' => 'right'],
        ], $rows, $this->selected, $this->cols - 4, $this->viewportRows($headerLineCount));
    }

    /**
     * Window the chapter table to the content body less the rendered header lines,
     * the single blank, and the table's own header + separator (2) — so the
     * selected row is never clipped by the frame.
     */
    private function viewportRows(int $headerLineCount): int
    {
        return max(1, Chrome::bodyHeight($this->rows) - $headerLineCount - 1 - 2);
    }

    /** Milliseconds → "m:ss" (or "h:mm:ss" once an hour or longer). */
    private static function clock(int $ms): string
    {
        $total = intdiv(max(0, $ms), 1000);
        $hours = intdiv($total, 3600);
        $minutes = intdiv($total % 3600, 60);
        $seconds = $total % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $seconds)
            : sprintf('%d:%02d', $minutes, $seconds);
    }

    private function headerTitle(): string
    {
        return $this->audiobook?->title ?? $this->title;
    }

    // ---- immutable copies (clone-mutate) -------------------------------

    private function withAudiobook(Audiobook $audiobook): self
    {
        $next = clone $this;
        $next->audiobook = $audiobook;
        $next->loaded = true;
        $next->selected = $this->clampSelected();

        return $next;
    }

    /** @param list<AudiobookChapter> $chapters */
    private function withChapters(array $chapters): self
    {
        $next = clone $this;
        $next->chapters = $chapters;
        $next->chaptersLoaded = true;
        $next->selected = $chapters === [] ? 0 : min($this->selected, count($chapters) - 1);

        return $next;
    }

    /**
     * Adopt the saved progress: offer a resume from a non-zero position and
     * pre-select the saved chapter (clamped to whatever chapters are loaded; the
     * chapters-load clamp will re-clamp if they arrive later).
     */
    private function withProgress(AudiobookProgress $progress): self
    {
        $next = clone $this;
        $next->resumeMs = $progress->positionMs > 0 ? $progress->positionMs : null;
        if ($next->resumeMs !== null) {
            $count = count($this->chapters);
            $next->selected = $count === 0
                ? 0
                : max(0, min($count - 1, $progress->currentChapterIndex));
        }

        return $next;
    }

    private function withError(string $error): self
    {
        $next = clone $this;
        $next->error = $error;
        $next->loaded = true;

        return $next;
    }

    private function resizedTo(int $cols, int $rows): self
    {
        $next = clone $this;
        $next->cols = $cols;
        $next->rows = $rows;

        return $next;
    }

    /** Clamp the selection into the current chapter count (0 when empty). */
    private function clampSelected(): int
    {
        return $this->chapters === [] ? 0 : min($this->selected, count($this->chapters) - 1);
    }

    // ---- breadcrumb ----------------------------------------------------

    public function crumbLabel(): string
    {
        return $this->headerTitle();
    }

    public function withCrumbs(array $trail): static
    {
        $next = clone $this;
        $next->crumbs = $trail;

        return $next;
    }

    // ---- accessors (for tests) ----------------------------------------

    public function isLoaded(): bool
    {
        return $this->loaded;
    }

    public function audiobook(): ?Audiobook
    {
        return $this->audiobook;
    }

    /** @return list<AudiobookChapter> */
    public function chapters(): array
    {
        return $this->chapters;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function selectedIndex(): int
    {
        return $this->selected;
    }

    public function selectedChapter(): ?AudiobookChapter
    {
        return $this->chapters[$this->selected] ?? null;
    }

    public function isPlaying(): bool
    {
        return $this->playing;
    }

    public function isPaused(): bool
    {
        return $this->paused;
    }

    public function positionMs(): int
    {
        return $this->positionMs;
    }

    /** The current heartbeat generation (an armed tick carries this epoch). */
    public function audioEpoch(): int
    {
        return $this->audioEpoch;
    }

    public function resumeMs(): ?int
    {
        return $this->resumeMs;
    }

    /** Test accessor: the chapter index containing the current position. */
    public function currentChapterIndexForTest(): int
    {
        return $this->currentChapterIndex();
    }

    /**
     * Test accessor: the indices of chapters behind the current position.
     *
     * @return list<int>
     */
    public function completedChapterIndicesForTest(): array
    {
        return $this->completedChapterIndices();
    }

    /** Test accessor: the 0–100 completion percentage. */
    public function percentCompleteForTest(): float
    {
        return $this->percentComplete();
    }
}
