<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\I18n\Lang;
use Phlix\Console\Msg\FavoritesFailedMsg;
use Phlix\Console\Msg\FavoritesLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenDetailMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Store\FavoritesStore;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\Skeleton;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\Util\Width;

/**
 * Displays the user's favorited media items.
 *
 * Fetches from GET /api/v1/users/me/favorites via {@see FavoritesStore}
 * and displays items in a scrollable list with a favorite marker (♥).
 */
final class FavoritesScreen implements Model, Teardownable, Breadcrumbed, Themed, Loadable, Shimmering
{
    use SubscriptionCapable;
    use RetriesFailedLoad;
    use ThemedScreen;
    use ShimmeringScreen;
    use ResizesTo;
    use SelectsNext;
    use SelectsPrevious;
    use WindowedList;

    private const HINT = 'q  back      ↑↓  navigate      ⏎  open';
    /** Failed-load hint: names only keys the error state arms [CON-44]. */
    private const ERROR_HINT = 'r  retry      q  back';
    private const SESSION_EXPIRED_KEY = 'auth.session_expired';
    private const LOAD_FAILED = 'Could not load the favorites.';

    /** @var list<MediaItem> */
    private array $items = [];
    private int $selectedIndex = 0;
    private bool $loading = true;
    private ?string $error = null;
    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private readonly FavoritesStore $favorites,
        private int $cols = 80,
        private int $rows = 24,
    ) {
    }

    public function init(): \Closure
    {
        return Cmd::promise(fn (): \React\Promise\PromiseInterface => $this->favorites->page()->then(
            static function (\Phlix\Console\Api\Dto\MediaPage $page): FavoritesLoadedMsg {
                return new FavoritesLoadedMsg($page->items);
            },
            static function (\Throwable $e): FavoritesFailedMsg|SessionExpiredMsg {
                return $e instanceof AuthError
                    ? new SessionExpiredMsg(Lang::t(self::SESSION_EXPIRED_KEY))
                    : new FavoritesFailedMsg(self::LOAD_FAILED);
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
        if ($msg instanceof FavoritesLoadedMsg) {
            $next = clone $this;
            $next->items = $msg->items;
            $next->loading = false;
            $next->error = null;

            return [$next, null];
        }
        if ($msg instanceof FavoritesFailedMsg) {
            return [$this->withError($msg->reason), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame(
            'Favorites',
            $this->body(),
            $this->error !== null ? self::ERROR_HINT : self::HINT,
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
        return 'Favorites';
    }

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        if (
            $msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && ($msg->rune === 'q' || $msg->rune === 'Q'))
        ) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }

        // 'r' retries a failed load [CON-44]: the error hint publishes it, so the
        // arm must exist. Inert otherwise.
        if ($this->error !== null && $msg->type === KeyType::Char && ($msg->rune === 'r' || $msg->rune === 'R')) {
            return $this->retryLoad();
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
            return "\n\n  Loading favorites…\n\n" . Skeleton::bars($this->cols - 4, max(1, Chrome::bodyHeight($this->rows) - 4), $this->shimmerPhase(), $this->theme());
        }
        if ($this->error !== null) {
            return "\n\n  " . Chrome::errorLine($this->error, $this->cols, $this->theme());
        }
        if ($this->items === []) {
            return "\n\n  No favorites yet.\n  Open an item and press F to add it here!";
        }

        [$visible, $offset] = $this->listWindow($this->items);

        $lines = [];
        foreach ($visible as $relative => $item) {
            $prefix = $offset + $relative === $this->selectedIndex ? '▶ ' : '  ';
            $lines[] = $prefix . $this->renderItem($item);
        }

        return "\n\n" . implode("\n", $lines);
    }

    private function renderItem(MediaItem $item): string
    {
        $name = Width::truncate($item->name, $this->cols - 30);
        $year = $item->year !== null ? (string) $item->year : '';
        $type = ucfirst($item->type);

        return "{$name}  ♥  {$type}" . ($year !== '' ? "  {$year}" : '');
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

    /** @param list<string> $trail */
    public function withCrumbs(array $trail): static
    {
        $next = clone $this;
        $next->crumbs = $trail;

        return $next;
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
