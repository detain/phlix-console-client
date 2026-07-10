<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\RecentlyWatchedItem;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenDetailMsg;
use Phlix\Console\Msg\WatchHistoryLoadedMsg;
use Phlix\Console\Msg\WatchHistoryFailedMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Ui\Chrome;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\Util\Width;

/**
 * Displays the user's recently watched items.
 *
 * Fetches from GET /api/v1/users/me/recently-watched and displays
 * items in a scrollable list with progress indicators.
 */
final class WatchHistoryScreen implements Model, Teardownable, Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const HINT = 'Q: Back  ↑↓: Navigate  Enter: Open';
    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LOAD_FAILED = 'Could not load watch history.';

    /** @var list<RecentlyWatchedItem> */
    private array $items = [];
    private int $selectedIndex = 0;
    private bool $loading = true;
    private ?string $error = null;
    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private readonly ApiClient $api,
        private int $cols = 80,
        private int $rows = 24,
    ) {
    }

    public function init(): \Closure
    {
        return Cmd::promise(function (): \React\Promise\PromiseInterface {
            return $this->api->recentlyWatched()->then(
                static function (array $items): WatchHistoryLoadedMsg {
                    return new WatchHistoryLoadedMsg($items);
                },
                static function (\Throwable $e): WatchHistoryFailedMsg|SessionExpiredMsg {
                    return $e instanceof AuthError
                        ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                        : new WatchHistoryFailedMsg(self::LOAD_FAILED);
                },
            );
        });
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
        if ($msg instanceof WatchHistoryLoadedMsg) {
            $next = clone $this;
            $next->items = $msg->items;
            $next->loading = false;
            $next->error = null;

            return [$next, null];
        }
        if ($msg instanceof WatchHistoryFailedMsg) {
            return [$this->withError($msg->reason), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame(
            'Recently Watched',
            $this->body(),
            self::HINT,
            $this->cols,
            $this->rows,
            $this->crumbs,
            $this->theme(),
        );
    }

    public function teardown(): void
    {
        // Nothing to tear down - no external resources held.
    }

    public function crumbLabel(): string
    {
        return 'History';
    }

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && ($msg->rune === 'q' || $msg->rune === 'Q'))) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }

        if ($msg->type === KeyType::Up || ($msg->type === KeyType::Char && $msg->rune === 'k')) {
            return $this->selectPrev();
        }

        if ($msg->type === KeyType::Down || ($msg->type === KeyType::Char && $msg->rune === 'j')) {
            return $this->selectNext();
        }

        if ($msg->type === KeyType::Enter) {
            return $this->openSelected();
        }

        return [$this, null];
    }

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
        if ($this->selectedIndex < count($this->items) - 1) {
            return [$this->withSelectedIndex($this->selectedIndex + 1), null];
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function openSelected(): array
    {
        if (!isset($this->items[$this->selectedIndex])) {
            return [$this, null];
        }

        $item = $this->items[$this->selectedIndex];

        return [$this, Cmd::send(new OpenDetailMsg($item->mediaItemId, $item->name))];
    }

    private function body(): string
    {
        if ($this->loading) {
            return "\n\n  Loading watch history…";
        }
        if ($this->error !== null) {
            return "\n\n  {$this->error}";
        }
        if ($this->items === []) {
            return "\n\n  No watch history yet.\n  Start watching to build your history!";
        }

        $lines = [];
        foreach ($this->items as $i => $item) {
            $prefix = $i === $this->selectedIndex ? '▶ ' : '  ';
            $lines[] = $prefix . $this->renderItem($item);
        }

        return "\n\n" . implode("\n", $lines);
    }

    private function renderItem(RecentlyWatchedItem $item): string
    {
        $name = Width::truncate($item->name, $this->cols - 30);
        $progress = $item->progress();
        $progressBar = $this->renderProgressBar($progress);
        $status = $this->shortStatus($item->playbackStatus);

        return "{$name}  {$progressBar}  {$status}";
    }

    private function renderProgressBar(float $progress): string
    {
        $barWidth = 10;
        $filled = (int) round($progress * $barWidth);
        $empty = $barWidth - $filled;

        return '[' . str_repeat('█', $filled) . str_repeat('░', $empty) . ']';
    }

    private function shortStatus(string $status): string
    {
        return match ($status) {
            'completed' => '✓',
            'playing' => '▶',
            'paused' => '⏸',
            default => '○',
        };
    }

    public function withSelectedIndex(int $index): self
    {
        $next = clone $this;
        $next->selectedIndex = $index;

        return $next;
    }

    public function withError(?string $error): self
    {
        $next = clone $this;
        $next->error = $error;
        $next->loading = false;

        return $next;
    }

    private function resizedTo(int $cols, int $rows): self
    {
        $next = clone $this;
        $next->cols = $cols;
        $next->rows = $rows;

        return $next;
    }

    /** @param list<string> $trail */
    public function withCrumbs(array $trail): static
    {
        $next = clone $this;
        $next->crumbs = $trail;

        return $next;
    }

    /** @return list<RecentlyWatchedItem> */
    public function items(): array
    {
        return $this->items;
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
}
