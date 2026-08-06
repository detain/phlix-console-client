<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenDetailMsg;
use Phlix\Console\Msg\PlaylistsFailedMsg;
use Phlix\Console\Msg\PlaylistsLoadedMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
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
 * Displays the user's playlists.
 *
 * Fetches from GET /api/v1/collections via {@see ApiClient::getPlaylists()}
 * and displays items in a scrollable list.
 */
final class PlaylistsScreen implements Model, Teardownable, Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const HINT = 'Q: Back  ↑↓: Navigate  Enter: Open';
    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LOAD_FAILED = 'Could not load playlists.';

    /** @var list<MediaItem> */
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
        return Cmd::promise(fn (): \React\Promise\PromiseInterface => $this->api->getPlaylists()->then(
            static function (array $playlists): PlaylistsLoadedMsg {
                // Playlists are returned as array{id, name, library_id} - convert to MediaItem-like for display
                $items = [];
                foreach ($playlists as $playlist) {
                    $items[] = MediaItem::fromArray([
                        'id' => $playlist['id'],
                        'name' => $playlist['name'] ?: 'Unnamed Playlist',
                        'type' => 'playlist',
                    ]);
                }

                return new PlaylistsLoadedMsg($items);
            },
            static function (\Throwable $e): PlaylistsFailedMsg|SessionExpiredMsg {
                return $e instanceof AuthError
                    ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                    : new PlaylistsFailedMsg(self::LOAD_FAILED);
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
        if ($msg instanceof PlaylistsLoadedMsg) {
            $next = clone $this;
            $next->items = $msg->items;
            $next->loading = false;
            $next->error = null;

            return [$next, null];
        }
        if ($msg instanceof PlaylistsFailedMsg) {
            return [$this->withError($msg->message), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame(
            'Playlists',
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

        return [$this, Cmd::send(new OpenDetailMsg($item->id, $item->name))];
    }

    private function body(): string
    {
        if ($this->loading) {
            return "\n\n  Loading playlists…";
        }
        if ($this->error !== null) {
            return "\n\n  {$this->error}";
        }
        if ($this->items === []) {
            return "\n\n  No playlists yet.";
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
        return 'Playlists';
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
