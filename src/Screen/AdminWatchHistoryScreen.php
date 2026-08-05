<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Admin\WatchHistoryEntry;
use Phlix\Console\Msg\AdminWatchHistoryFailedMsg;
use Phlix\Console\Msg\AdminWatchHistoryLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\Table;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Form;

/**
 * The admin cross-user watch-history surface: a scrollable list of every
 * user's recently-watched items, with user + media metadata, progress, and
 * status.
 *
 * `r` refetches; `u` opens a filter-by-user form (enter a userId or leave blank
 * to clear); Esc/q go back. A fetch failure shows a line plus a retry hint; an
 * auth failure surfaces a session expiry.
 *
 * The client is injected (built locally by the App from its shared ApiClient, so
 * the App holds no AdminClient field). Stable collaborators are readonly; the
 * loaded data + flags are private mutable view state set via clone-mutate (the
 * established screen idiom).
 */
final class AdminWatchHistoryScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LOAD_FAILED = 'Could not load watch history.';
    private const HINT = 'u  filter-user  r  refresh  Esc  back';

    /** Default page size. */
    private const PAGE_SIZE = 50;

    /** @var list<WatchHistoryEntry> */
    private array $entries = [];
    private int $selectedIndex = 0;
    private bool $loading = true;
    private ?string $error = null;
    /** @var list<string> */
    private array $crumbs = [];

    /** @var array<string, mixed>|null */
    private ?array $formContext = null;

    public function __construct(
        private readonly AdminClient $admin,
        private int $cols = 80,
        private int $rows = 24,
    ) {
    }

    public function init(): \Closure
    {
        return $this->fetchCmd(null);
    }

    /**
     * Build a refetch command, optionally filtered by a userId.
     *
     * @return \Closure
     */
    private function fetchCmd(?string $userId): \Closure
    {
        return Cmd::promise(fn (): \React\Promise\PromiseInterface => $this->admin->watchHistory($userId, self::PAGE_SIZE)
            ->then(
                static fn (array $entries): AdminWatchHistoryLoadedMsg => new AdminWatchHistoryLoadedMsg($entries),
                static fn (\Throwable $e): Msg => $e instanceof AuthError
                    ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                    : new AdminWatchHistoryFailedMsg(self::LOAD_FAILED),
            ));
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
        if ($msg instanceof AdminWatchHistoryLoadedMsg) {
            $next = clone $this;
            $next->entries = $msg->entries;
            $next->loading = false;
            $next->error = null;
            $next->selectedIndex = 0;
            $next->formContext = null;

            return [$next, null];
        }
        if ($msg instanceof AdminWatchHistoryFailedMsg) {
            return [$this->withError($msg->message), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame(
            'Admin · Watch History',
            $this->body(),
            self::HINT,
            $this->cols,
            $this->rows,
            $this->crumbs,
            $this->theme(),
        );
    }

    // ---- input ---------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'r') {
            return [$this->reloading(), $this->fetchCmd($this->currentUserId())];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'u') {
            return [$this->withFormContext(['userId' => null]), null];
        }
        if ($msg->type === KeyType::Up || ($msg->type === KeyType::Char && $msg->rune === 'k')) {
            return $this->selectPrev();
        }
        if ($msg->type === KeyType::Down || ($msg->type === KeyType::Char && $msg->rune === 'j')) {
            return $this->selectNext();
        }

        return [$this, null];
    }

    // ---- selection -----------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function selectPrev(): array
    {
        if ($this->selectedIndex > 0) {
            return [$this->withSelectedIndex($this->selectedIndex - 1), null];
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function selectNext(): array
    {
        if ($this->selectedIndex < count($this->entries) - 1) {
            return [$this->withSelectedIndex($this->selectedIndex + 1), null];
        }

        return [$this, null];
    }

    private function withSelectedIndex(int $index): self
    {
        $next = clone $this;
        $next->selectedIndex = $index;

        return $next;
    }

    // ---- error ---------------------------------------------------------

    private function withError(string $reason): self
    {
        $next = clone $this;
        $next->error = $reason;
        $next->loading = false;

        return $next;
    }

    private function reloading(): self
    {
        $next = clone $this;
        $next->loading = true;
        $next->error = null;

        return $next;
    }

    // ---- form context --------------------------------------------------

    /**
     * @param array{userId: ?string} $context
     */
    private function withFormContext(array $context): self
    {
        $next = clone $this;
        $next->formContext = $context;

        return $next;
    }

    private function currentUserId(): ?string
    {
        return is_string(($this->formContext['userId'] ?? null)) ? ($this->formContext['userId']) : null;
    }

    // ---- rendering -----------------------------------------------------

    private function body(): string
    {
        if ($this->loading) {
            return "\n\n  Loading watch history…";
        }
        if ($this->error !== null) {
            return "\n\n  {$this->error}\n  Press r to retry.";
        }
        if ($this->entries === []) {
            return "\n\n  No watch history found.";
        }

        $userFilter = $this->currentUserId();

        return "\n" . Table::render(
            [
                ['title' => 'User', 'width' => 16],
                ['title' => 'Profile', 'width' => 12],
                ['title' => 'Media', 'width' => 0],
                ['title' => 'Progress', 'width' => 9, 'align' => 'right'],
                ['title' => 'Status', 'width' => 7],
                ['title' => 'Last Watched', 'width' => 20],
            ],
            $this->tableRows(),
            $this->selectedIndex,
            $this->cols - 4,
            Chrome::bodyHeight($this->rows),
        );
    }

    /** @return list<array{string}> */
    private function tableRows(): array
    {
        $rows = [];
        foreach ($this->entries as $entry) {
            $progress = $this->renderProgress($entry->progressPercent);
            $status = $entry->statusSymbol();
            $lastWatched = $this->formatTimestamp($entry->lastWatchedAt);

            $rows[] = [
                $entry->username,
                $entry->profileName,
                $this->truncate($entry->mediaName, $this->cols - 72),
                $progress,
                $status,
                $lastWatched,
            ];
        }

        return $rows;
    }

    private function renderProgress(float $percent): string
    {
        $barWidth = 6;
        $filled = (int) round(($percent / 100.0) * $barWidth);
        $empty = $barWidth - $filled;

        return '[' . str_repeat('█', $filled) . str_repeat('░', $empty) . ']';
    }

    private function formatTimestamp(string $ts): string
    {
        if ($ts === '') {
            return '—';
        }

        try {
            $dt = new \DateTimeImmutable($ts);

            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return $ts;
        }
    }

    private function truncate(string $text, int $maxWidth): string
    {
        if ($maxWidth <= 0) {
            return '';
        }
        if (mb_strlen($text, 'UTF-8') <= $maxWidth) {
            return $text;
        }

        return mb_substr($text, 0, $maxWidth - 1, 'UTF-8') . '…';
    }

    // ---- clone-mutate --------------------------------------------------

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
        return 'Watch History';
    }

    /** @param list<string> $trail */
    public function withCrumbs(array $trail): static
    {
        $next = clone $this;
        $next->crumbs = $trail;

        return $next;
    }

    // ---- accessors -----------------------------------------------------

    /** @return list<WatchHistoryEntry> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function selectedIndex(): int
    {
        return $this->selectedIndex;
    }

    public function isLoading(): bool
    {
        return $this->loading;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function pageSize(): int
    {
        return self::PAGE_SIZE;
    }
}
