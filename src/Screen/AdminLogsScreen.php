<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Admin\LogFile;
use Phlix\Console\Api\Dto\Admin\LogTail;
use Phlix\Console\Msg\AdminLogFailedMsg;
use Phlix\Console\Msg\AdminLogFilesLoadedMsg;
use Phlix\Console\Msg\AdminLogTailLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Ui\Chrome;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Style;

/**
 * The admin Logs viewer: a two-pane log tailer. The left pane lists the server's
 * log files ({@see AdminClient::logFiles()}) with an "All logs (merged)" entry at
 * the top; selecting an entry tails it ({@see AdminClient::tailLog()} for a single
 * file, {@see AdminClient::tailAllLogs()} for the merged view) into the right
 * pane's scrollable viewport.
 *
 * Tab (or ←/→) switches focus between the file list and the viewport: when the
 * list is focused ↑/↓ move the selection and Enter tails it; when the viewport is
 * focused ↑/↓ and PgUp/PgDn scroll the lines. `r` refetches the current selection
 * (or the file list when nothing is tailed yet); Esc/q go back. A failed fetch
 * shows the error plus a retry hint; an auth failure surfaces a session expiry.
 *
 * The client is injected (built locally by the App from its shared ApiClient, so
 * the App holds no AdminClient field). Stable collaborators are readonly; the
 * loaded data, selection, focus, and scroll offset are private mutable view state
 * set via clone-mutate (the established screen idiom).
 */
final class AdminLogsScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LIST_FAILED = 'Could not load the log files.';
    private const TAIL_FAILED = 'Could not read the log.';
    private const HINT = 'Tab  focus      ↑↓/PgUp/PgDn  move      ⏎  open      r  refresh      Esc  back';

    /** How many lines a tail request asks the server for. */
    private const LINES = 200;

    /** The synthetic "all logs" entry, always first in the list. */
    private const ALL_LOGS_LABEL = 'All logs (merged)';

    /** Width of the file-list pane (the rest is the viewport). */
    private const LIST_WIDTH = 28;

    /** Which pane has focus. */
    private const FOCUS_LIST = 0;
    private const FOCUS_VIEWER = 1;

    /** @var list<LogFile> */
    private array $files = [];
    private bool $filesLoaded = false;
    private ?LogTail $tail = null;
    private bool $tailing = false;
    private ?string $error = null;

    private int $selected = 0;
    private int $focus = self::FOCUS_LIST;
    private int $scroll = 0;

    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private readonly AdminClient $admin,
        private int $cols = 80,
        private int $rows = 24,
    ) {
    }

    public function init(): \Closure
    {
        return $this->listCmd();
    }

    // ---- fetch commands ------------------------------------------------

    private function listCmd(): \Closure
    {
        return Cmd::promise(fn () => $this->admin->logFiles()->then(
            /** @param list<LogFile> $files */
            static fn (array $files): Msg => new AdminLogFilesLoadedMsg($files),
            static fn (\Throwable $e): Msg => self::failure($e, self::LIST_FAILED),
        ));
    }

    private function tailCmd(): \Closure
    {
        // Index 0 is the synthetic "all logs" entry; any other index maps to a
        // real file (offset by 1).
        $selected = $this->selected;
        if ($selected === 0) {
            return Cmd::promise(fn () => $this->admin->tailAllLogs(self::LINES)->then(
                static fn (LogTail $tail): Msg => new AdminLogTailLoadedMsg($tail),
                static fn (\Throwable $e): Msg => self::failure($e, self::TAIL_FAILED),
            ));
        }

        // The selection is clamped to the entry count by moveSelection, so this
        // index is always in range; a defensive empty name falls back to the list.
        $logFile = $this->files[$selected - 1] ?? null;
        $file = $logFile === null ? '' : $logFile->name;
        if ($file === '') {
            return $this->listCmd();
        }

        return Cmd::promise(fn () => $this->admin->tailLog($file, self::LINES)->then(
            static fn (LogTail $tail): Msg => new AdminLogTailLoadedMsg($tail),
            static fn (\Throwable $e): Msg => self::failure($e, self::TAIL_FAILED),
        ));
    }

    private static function failure(\Throwable $e, string $message): Msg
    {
        return $e instanceof AuthError
            ? new SessionExpiredMsg(self::SESSION_EXPIRED)
            : new AdminLogFailedMsg($message);
    }

    /** @return array{self, ?\Closure} */
    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [$this->resizedTo($msg->cols, $msg->rows), null];
        }
        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }
        if ($msg instanceof AdminLogFilesLoadedMsg) {
            return [$this->withFiles($msg->files), null];
        }
        if ($msg instanceof AdminLogTailLoadedMsg) {
            return [$this->withTail($msg->tail), null];
        }
        if ($msg instanceof AdminLogFailedMsg) {
            return [$this->withError($msg->message), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame('Admin · Logs', $this->body(), self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
    }

    // ---- input ---------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'r') {
            return $this->refresh();
        }
        if ($msg->type === KeyType::Tab) {
            return [$this->toggleFocus(), null];
        }
        if ($msg->type === KeyType::Left) {
            return [$this->focusedOn(self::FOCUS_LIST), null];
        }
        if ($msg->type === KeyType::Right) {
            return [$this->focusedOn(self::FOCUS_VIEWER), null];
        }

        return $this->focus === self::FOCUS_LIST
            ? $this->handleListKey($msg)
            : [$this->handleViewerKey($msg), null];
    }

    /** @return array{self, ?\Closure} */
    private function handleListKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Up) {
            return [$this->moveSelection(-1), null];
        }
        if ($msg->type === KeyType::Down) {
            return [$this->moveSelection(1), null];
        }
        if ($msg->type === KeyType::Enter) {
            return [$this->tailingSelection(), $this->tailCmd()];
        }

        return [$this, null];
    }

    private function handleViewerKey(KeyMsg $msg): self
    {
        return match ($msg->type) {
            KeyType::Up => $this->scrollBy(-1),
            KeyType::Down => $this->scrollBy(1),
            KeyType::PageUp => $this->scrollBy(-$this->viewportRows()),
            KeyType::PageDown => $this->scrollBy($this->viewportRows()),
            KeyType::Home => $this->scrolledTo(0),
            KeyType::End => $this->scrolledTo($this->maxScroll()),
            default => $this,
        };
    }

    /** @return array{self, ?\Closure} */
    private function refresh(): array
    {
        // Refetch whatever is currently shown: the active tail when one exists,
        // otherwise the file list.
        if ($this->tail !== null || $this->tailing) {
            return [$this->tailingSelection(), $this->tailCmd()];
        }

        $next = clone $this;
        $next->filesLoaded = false;
        $next->error = null;

        return [$next, $this->listCmd()];
    }

    // ---- rendering -----------------------------------------------------

    private function body(): string
    {
        if (!$this->filesLoaded && $this->error === null) {
            return "\n  Loading logs…";
        }
        if ($this->error !== null && !$this->filesLoaded) {
            return "\n  {$this->error}\n\n  Press r to retry.";
        }

        $listWidth = min(self::LIST_WIDTH, max(10, $this->cols - 20));
        $listLines = $this->listLines($listWidth);
        $viewerLines = $this->viewerLines();

        $rows = max(count($listLines), count($viewerLines), 1);
        $out = [];
        $sep = $this->focus === self::FOCUS_VIEWER ? ' │ ' : ' ┊ ';
        for ($i = 0; $i < $rows; ++$i) {
            $left = Width::padRight($listLines[$i] ?? '', $listWidth);
            $right = $viewerLines[$i] ?? '';
            $out[] = '  ' . $left . $sep . $right;
        }

        return "\n" . implode("\n", $out);
    }

    /**
     * The file-list pane rows: the "All logs" entry then each file, the selected
     * row marked (and accented when the list has focus).
     *
     * @return list<string>
     */
    private function listLines(int $width): array
    {
        $labels = [self::ALL_LOGS_LABEL];
        foreach ($this->files as $file) {
            $labels[] = $file->name;
        }

        $accent = Style::new()->bold();
        $lines = [];
        foreach ($labels as $i => $label) {
            $marker = $i === $this->selected ? '› ' : '  ';
            $text = Width::truncate($marker . $label, $width);
            if ($i === $this->selected && $this->focus === self::FOCUS_LIST) {
                $text = $accent->render($text);
            }
            $lines[] = $text;
        }

        return $lines;
    }

    /**
     * The viewport pane rows: the scrolled window of tail lines, each truncated to
     * the available width, with a header and (when truncated) an omitted-lines note.
     *
     * @return list<string>
     */
    private function viewerLines(): array
    {
        $width = $this->viewerWidth();
        $tail = $this->tail;

        if ($this->tailing) {
            return [Width::truncate('Loading…', $width)];
        }
        if ($this->error !== null) {
            return [
                Width::truncate($this->error, $width),
                Width::truncate('Press r to retry.', $width),
            ];
        }
        if ($tail === null) {
            return [Width::truncate('Select a log to tail it.', $width)];
        }
        if ($tail->lines === []) {
            return [Width::truncate('(empty)', $width)];
        }

        $rows = $this->viewportRows();
        $offset = min($this->scroll, $this->maxScroll());
        $window = array_slice($tail->lines, $offset, $rows);

        $out = [];
        if ($tail->truncated) {
            $out[] = Width::truncate('(truncated — older lines omitted)', $width);
        }
        foreach ($window as $line) {
            $out[] = Width::truncate($line, $width);
        }

        return $out;
    }

    private function viewerWidth(): int
    {
        $listWidth = min(self::LIST_WIDTH, max(10, $this->cols - 20));

        // body indent (2) + list pane + separator (3).
        return max(10, $this->cols - 4 - 2 - $listWidth - 3);
    }

    /** How many tail lines the viewport shows at the current height. */
    private function viewportRows(): int
    {
        return max(1, Chrome::bodyHeight($this->rows) - 1);
    }

    /** The greatest scroll offset that still shows a full window of lines. */
    private function maxScroll(): int
    {
        $tail = $this->tail;
        if ($tail === null) {
            return 0;
        }

        return max(0, count($tail->lines) - $this->viewportRows());
    }

    // ---- immutable copies (clone-mutate) -------------------------------

    /** @param list<LogFile> $files */
    private function withFiles(array $files): self
    {
        $next = clone $this;
        $next->files = $files;
        $next->filesLoaded = true;
        $next->error = null;

        return $next;
    }

    private function withTail(LogTail $tail): self
    {
        $next = clone $this;
        $next->tail = $tail;
        $next->tailing = false;
        $next->error = null;
        $next->scroll = 0;

        return $next;
    }

    private function withError(string $error): self
    {
        $next = clone $this;
        $next->error = $error;
        $next->tailing = false;

        return $next;
    }

    /** Enter the loading state for a fresh tail of the current selection. */
    private function tailingSelection(): self
    {
        $next = clone $this;
        $next->tailing = true;
        $next->error = null;
        $next->focus = self::FOCUS_VIEWER;

        return $next;
    }

    private function toggleFocus(): self
    {
        return $this->focusedOn($this->focus === self::FOCUS_LIST ? self::FOCUS_VIEWER : self::FOCUS_LIST);
    }

    private function focusedOn(int $focus): self
    {
        if ($focus === $this->focus) {
            return $this;
        }
        $next = clone $this;
        $next->focus = $focus;

        return $next;
    }

    private function moveSelection(int $delta): self
    {
        $count = count($this->files) + 1; // +1 for the "all logs" entry
        $selected = max(0, min($count - 1, $this->selected + $delta));
        if ($selected === $this->selected) {
            return $this;
        }
        $next = clone $this;
        $next->selected = $selected;

        return $next;
    }

    private function scrollBy(int $delta): self
    {
        return $this->scrolledTo($this->scroll + $delta);
    }

    private function scrolledTo(int $offset): self
    {
        $offset = max(0, min($this->maxScroll(), $offset));
        if ($offset === $this->scroll) {
            return $this;
        }
        $next = clone $this;
        $next->scroll = $offset;

        return $next;
    }

    private function resizedTo(int $cols, int $rows): self
    {
        $next = clone $this;
        $next->cols = $cols;
        $next->rows = $rows;

        return $next;
    }

    // ---- breadcrumb ----------------------------------------------------

    public function crumbLabel(): string
    {
        return 'Logs';
    }

    public function withCrumbs(array $trail): static
    {
        $next = clone $this;
        $next->crumbs = $trail;

        return $next;
    }

    // ---- accessors (for tests) ----------------------------------------

    public function filesLoaded(): bool
    {
        return $this->filesLoaded;
    }

    /** @return list<LogFile> */
    public function fileList(): array
    {
        return $this->files;
    }

    public function tail(): ?LogTail
    {
        return $this->tail;
    }

    public function isTailing(): bool
    {
        return $this->tailing;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function selectedIndex(): int
    {
        return $this->selected;
    }

    public function viewerFocused(): bool
    {
        return $this->focus === self::FOCUS_VIEWER;
    }

    public function scrollOffset(): int
    {
        return $this->scroll;
    }
}
