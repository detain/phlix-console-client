<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Audiobook;
use Phlix\Console\Api\Dto\AudiobookChapter;
use Phlix\Console\Api\Dto\AudiobookProgress;
use Phlix\Console\Msg\AudiobookChaptersLoadedMsg;
use Phlix\Console\Msg\AudiobookFailedMsg;
use Phlix\Console\Msg\AudiobookLoadedMsg;
use Phlix\Console\Msg\AudiobookProgressLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\PlayAudiobookMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ToggleAudioMsg;
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
 * The audiobook AUDIO is owned by the {@see \Phlix\Console\App} (an
 * {@see \Phlix\Console\Audio\AudiobookSession}), NOT this screen, so playback
 * persists as the user navigates — shown by the persistent
 * {@see \Phlix\Console\Ui\NowPlayingBar}. This screen is therefore a pure chapter
 * list that EMITS Msgs: Enter on a chapter (or `r` to resume) emits a
 * {@see PlayAudiobookMsg} (carrying the loaded audiobook + chapters + a start
 * offset in MILLISECONDS — chapters are seek markers into the one signed stream);
 * Space emits {@see ToggleAudioMsg}; Esc/q go back (audio keeps playing, the bar
 * shows it). The saved progress is still fetched to offer a "↺ Resume from …"
 * affordance (a display hint + the `r` seed).
 *
 * ↑/↓ move the chapter selection. Stable collaborators are readonly; mutable
 * view state is private and copied via clone-mutate.
 */
final class AudiobookDetailScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const HINT = '↑↓  select   ⏎  play   r  resume   space  pause   Esc  back';
    private const NUM_WIDTH = 5;
    private const DURATION_WIDTH = 10;
    private const PART_SEPARATOR = '   ·   ';

    private ?Audiobook $audiobook = null;
    private bool $loaded = false;
    /** @var list<AudiobookChapter> */
    private array $chapters = [];
    private int $selected = 0;
    private ?string $error = null;
    /** @var list<string> */
    private array $crumbs = [];

    /** The saved resume position in ms, or null when there is none to resume. */
    private ?int $resumeMs = null;

    public function __construct(
        private readonly AudiobooksStore $store,
        private readonly string $id,
        private readonly string $title,
        private int $cols = 80,
        private int $rows = 24,
    ) {
    }

    public function init(): \Closure
    {
        // Three concurrent fetches: the detail (author/narrator/series/duration +
        // the signed stream URL), the chapter list, and the saved progress. The
        // chapter fetch degrades to empty on a non-auth error; the progress fetch
        // is swallowed on a non-auth error (no resume offered).
        return Cmd::batch($this->fetchDetail(), $this->fetchChapters(), $this->fetchProgress());
    }

    /** @return array{self, ?\Closure} */
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

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            // The App owns the audio now, so leaving never stops playback — the
            // now-playing bar keeps it visible. Just go back.
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Char && $msg->rune === ' ') {
            // Pause/resume the App-owned session.
            return [$this, Cmd::send(new ToggleAudioMsg())];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'r') {
            // Resume from the saved position, if there is one (App plays the seek).
            return $this->resumeMs !== null ? $this->play($this->resumeMs) : [$this, null];
        }
        if ($msg->type === KeyType::Enter) {
            // Play the selected chapter from its start (or 0 with no chapters).
            return $this->play($this->selectedChapterStartMs());
        }
        if ($msg->type === KeyType::Up) {
            return [$this->moveSelection(-1), null];
        }
        if ($msg->type === KeyType::Down) {
            return [$this->moveSelection(1), null];
        }

        return [$this, null];
    }

    /**
     * Emit a {@see PlayAudiobookMsg} for the App to play/seek the audiobook at
     * $startMs. A no-op (no Msg) until the audiobook detail (with its stream URL)
     * has loaded — the App also guards a missing URL with an error toast.
     *
     * @return array{self, ?\Closure}
     */
    private function play(int $startMs): array
    {
        if ($this->audiobook === null) {
            return [$this, null];
        }

        return [$this, Cmd::send(new PlayAudiobookMsg($this->audiobook, $this->chapters, $startMs))];
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
     * The header region above the chapter table: the metadata lines plus (when
     * there is a saved position) a resume hint. Width-truncated to the content
     * width, ANSI-free.
     *
     * @return list<string>
     */
    private function headerLines(Audiobook $book): array
    {
        $width = max(1, $this->cols - 4);

        $lines = $this->metaLines($book);
        if ($this->resumeMs !== null) {
            $lines[] = Width::truncate('↺ Resume from ' . self::clock($this->resumeMs) . '  (press r)', $width);
        }

        return $lines;
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
        return $this->audiobook->title ?? $this->title;
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

    public function resumeMs(): ?int
    {
        return $this->resumeMs;
    }
}
