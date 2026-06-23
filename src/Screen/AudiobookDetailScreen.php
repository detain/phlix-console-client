<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Audiobook;
use Phlix\Console\Api\Dto\AudiobookChapter;
use Phlix\Console\Msg\AudiobookChaptersLoadedMsg;
use Phlix\Console\Msg\AudiobookFailedMsg;
use Phlix\Console\Msg\AudiobookLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Store\AudiobooksStore;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\Table;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;

/**
 * A single audiobook's detail: a metadata header (by author · narrated by
 * narrator, then series · duration · language) above a borderless chapter
 * table (# · Chapter · Duration) rendered via {@see Table} with reverse-video
 * row selection. Audiobooks have no usable cover server-side (the `cover_url`
 * is a raw filesystem path), so the screen is text-forward.
 *
 * The detail ({@see AudiobooksStore::audiobook}) and the chapter list
 * ({@see AudiobooksStore::chapters}) are fetched concurrently in {@see init()}.
 * A non-auth chapters error degrades gracefully to an empty chapter list (the
 * metadata still shows) rather than a whole-screen error; an auth failure on
 * either surfaces as a session expiry so the App can re-authenticate.
 *
 * Enter is INERT this PR — it surfaces an info toast placeholder. The next
 * update (A3) rewires it to spawn an AudioPlayer over the signed `stream_url`
 * (resuming at the saved position, with chapter seek and progress reporting).
 *
 * Stable collaborators are readonly; the mutable view state is private and
 * copied via clone-mutate (the established screen idiom).
 */
final class AudiobookDetailScreen implements Breadcrumbed
{
    use SubscriptionCapable;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const PLAYBACK_SOON = 'Audio playback arrives in the next update';
    private const HINT = '↑↓  select      ⏎  play      Esc  back';
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

    public function __construct(
        private readonly AudiobooksStore $store,
        private readonly string $id,
        private readonly string $title,
        private int $cols = 80,
        private int $rows = 24,
    ) {
    }

    public function init(): ?\Closure
    {
        // Two concurrent fetches: the detail (author/narrator/series/duration)
        // and the chapter list. The chapter fetch degrades to empty on a
        // non-auth error so the metadata always shows.
        return Cmd::batch($this->fetchDetail(), $this->fetchChapters());
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
            return Chrome::frame($this->headerTitle(), "\n  {$this->error}", self::HINT, $this->cols, $this->rows, $this->crumbs);
        }
        if (!$this->loaded || $this->audiobook === null) {
            return Chrome::frame($this->headerTitle(), "\n  Loading…", self::HINT, $this->cols, $this->rows, $this->crumbs);
        }

        return Chrome::frame($this->headerTitle(), $this->body($this->audiobook), self::HINT, $this->cols, $this->rows, $this->crumbs);
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

    // ---- input ---------------------------------------------------------

    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Enter) {
            // Inert placeholder this PR — A3 rewires Enter to spawn an AudioPlayer
            // (resuming at the saved position) over the signed stream URL.
            return $this->chapters === []
                ? [$this, null]
                : [$this, Cmd::send(ShowToastMsg::info(self::PLAYBACK_SOON))];
        }
        if ($msg->type === KeyType::Up) {
            return [$this->moveSelection(-1), null];
        }
        if ($msg->type === KeyType::Down) {
            return [$this->moveSelection(1), null];
        }

        return [$this, null];
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
        $metaLines = $this->metaLines($book);
        // The meta lines, then a single blank, then the chapter table (or an
        // empty notice). Reserve the rendered meta-line count plus the blank so
        // the table viewport sizing matches exactly what is drawn.
        $head = $metaLines === [] ? '' : implode("\n", $metaLines) . "\n";
        $head .= "\n"; // the blank separating meta from the table

        if ($this->chapters === []) {
            return $head . '  No chapters.';
        }

        return $head . $this->chapterTable(count($metaLines));
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

    private function chapterTable(int $metaLineCount): string
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
        ], $rows, $this->selected, $this->cols - 4, $this->viewportRows($metaLineCount));
    }

    /**
     * Window the chapter table to the content body less the rendered meta lines,
     * the single blank, and the table's own header + separator (2) — so the
     * selected row is never clipped by the frame.
     */
    private function viewportRows(int $metaLineCount): int
    {
        return max(1, Chrome::bodyHeight($this->rows) - $metaLineCount - 1 - 2);
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
}
