<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Msg\CollectionsFailedMsg;
use Phlix\Console\Msg\CollectionsLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenDetailMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Api\ApiClient;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\Util\Width;

/**
 * Displays the user's collections.
 *
 * Fetches from GET /api/v1/collections via {@see ApiClient::getPlaylists()}
 * and displays items in a scrollable list.
 */
final class CollectionsScreen implements Model, Teardownable, Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const HINT = 'Q: Back  ↑↓: Navigate  Enter: Open  A: Add item  R: Remove item  D: Delete';
    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LOAD_FAILED = 'Could not load collections.';
    private const ADD_ITEM_NOT_IMPLEMENTED = 'Add/remove items from the collection detail screen.';
    private const DELETE_CONFIRM_CANCELLED = 'Delete cancelled.';

    /** @var list<MediaItem> */
    private array $items = [];
    private int $selectedIndex = 0;
    private bool $loading = true;
    private ?string $error = null;
    /** @var list<string> */
    private array $crumbs = [];
    private ?CollectionPendingAction $pendingDelete = null;

    public function __construct(
        private readonly ApiClient $api,
        private int $cols = 80,
        private int $rows = 24,
    ) {
    }

    public function init(): \Closure
    {
        return Cmd::promise(fn (): \React\Promise\PromiseInterface => $this->api->getPlaylists()->then(
            static function (array $collections): CollectionsLoadedMsg {
                // Collections are returned as array{id, name, library_id} - convert to MediaItem-like for display
                $items = [];
                foreach ($collections as $collection) {
                    $items[] = MediaItem::fromArray([
                        'id' => $collection['id'],
                        'name' => $collection['name'] ?: 'Unnamed Collection',
                        'type' => 'collection',
                    ]);
                }

                return new CollectionsLoadedMsg($items);
            },
            static function (\Throwable $e): CollectionsFailedMsg|SessionExpiredMsg {
                return $e instanceof AuthError
                    ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                    : new CollectionsFailedMsg(self::LOAD_FAILED);
            },
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
        if ($msg instanceof CollectionsLoadedMsg) {
            $next = clone $this;
            $next->items = $msg->items;
            $next->loading = false;
            $next->error = null;

            return [$next, null];
        }
        if ($msg instanceof CollectionsFailedMsg) {
            return [$this->withError($msg->message), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame(
            'Collections',
            $this->body(),
            self::HINT,
            $this->cols,
            $this->rows,
            $this->crumbs,
            $this->theme(),
        );
    }

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        // Handle delete confirmation first if pending
        if ($this->pendingDelete !== null) {
            return $this->handleDeleteConfirmKey($msg);
        }

        if (
            $msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && ($msg->rune === 'q' || $msg->rune === 'Q'))
        ) {
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

        // 'a' - Add item to collection (not available from list view)
        if ($msg->type === KeyType::Char && $msg->rune === 'a') {
            return [$this, Cmd::send(ShowToastMsg::info(self::ADD_ITEM_NOT_IMPLEMENTED))];
        }

        // 'r' - Remove item from collection (not available from list view)
        if ($msg->type === KeyType::Char && $msg->rune === 'r') {
            return [$this, Cmd::send(ShowToastMsg::info(self::ADD_ITEM_NOT_IMPLEMENTED))];
        }

        // 'd' or 'D' - Arm delete confirmation
        if ($msg->type === KeyType::Char && ($msg->rune === 'd' || $msg->rune === 'D')) {
            return $this->armDelete();
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function handleDeleteConfirmKey(KeyMsg $msg): array
    {
        $pending = $this->pendingDelete;
        if ($pending === null) {
            return [$this, null];
        }

        // Escape cancels the pending delete
        if ($msg->type === KeyType::Escape) {
            return [$this->withPendingDelete(null), Cmd::send(ShowToastMsg::info(self::DELETE_CONFIRM_CANCELLED))];
        }

        // Accumulate typed characters for typed "delete" confirmation
        if ($msg->type === KeyType::Char && $msg->rune !== '') {
            $next = $pending->withTyped($msg->rune);
            if ($next->isConfirmed()) {
                // Confirmed! Execute delete
                return $this->executeDelete($pending->getCollection());
            }

            return [$this->withPendingDelete($next), null];
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function armDelete(): array
    {
        if (!isset($this->items[$this->selectedIndex])) {
            return [$this, null];
        }

        $collection = $this->items[$this->selectedIndex];
        $pending = new CollectionPendingAction('delete', $collection);

        return [$this->withPendingDelete($pending), null];
    }

    /** @return array{self, ?\Closure} */
    private function executeDelete(MediaItem $collection): array
    {
        $next = $this->working();
        $promise = $this->api->deletePlaylist($collection->id)->then(
            static function (): CollectionsLoadedMsg {
                return new CollectionsLoadedMsg([]);
            },
            static function (\Throwable $e): CollectionsFailedMsg|SessionExpiredMsg {
                return $e instanceof AuthError
                    ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                    : new CollectionsFailedMsg('Delete failed: ' . $e->getMessage());
            },
        );

        return [
            $next,
            static function () use ($promise): \React\Promise\PromiseInterface {
                return $promise;
            },
        ];
    }

    private function working(): self
    {
        $next = clone $this;
        $next->loading = true;
        $next->pendingDelete = null;

        return $next;
    }

    private function withPendingDelete(?CollectionPendingAction $pending): self
    {
        $next = clone $this;
        $next->pendingDelete = $pending;

        return $next;
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

        return [$this, Cmd::send(new OpenDetailMsg($item->id, $item->name))];
    }

    private function body(): string
    {
        if ($this->loading) {
            return "\n\n  Loading collections…";
        }
        if ($this->error !== null) {
            return "\n\n  {$this->error}";
        }
        if ($this->items === []) {
            return "\n\n  No collections yet.";
        }

        // Show delete confirmation prompt if pending
        if ($this->pendingDelete !== null) {
            $lines = [];
            foreach ($this->items as $i => $item) {
                $prefix = $i === $this->selectedIndex ? '▶ ' : '  ';
                $lines[] = $prefix . $this->renderItem($item);
            }

            $prompt = "\n\n  ⚠️  {$this->pendingDelete->prompt()}";
            $cancelHint = "\n  Esc: Cancel";

            return $prompt . implode("\n", $lines) . $cancelHint;
        }

        $lines = [];
        foreach ($this->items as $i => $item) {
            $prefix = $i === $this->selectedIndex ? '▶ ' : '  ';
            $lines[] = $prefix . $this->renderItem($item);
        }

        return "\n\n" . implode("\n", $lines);
    }

    private function renderItem(MediaItem $item): string
    {
        $name = Width::truncate($item->name ?: 'Unnamed', $this->cols - 4);

        return "{$name}";
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

    public function teardown(): void
    {
        // Nothing to tear down - no external resources held.
    }

    public function crumbLabel(): string
    {
        return 'Collections';
    }

    /** @return list<MediaItem> */
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
