<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\AuthUser;
use Phlix\Console\Api\Dto\ContinueWatchingItem;
use Phlix\Console\Api\Dto\Library;
use Phlix\Console\Api\Dto\MediaPage;
use Phlix\Console\Api\MediaQuery;
use Phlix\Console\Media\PosterCardFactory;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Msg\ContinueWatchingLoadedMsg;
use Phlix\Console\Msg\LibrariesFailedMsg;
use Phlix\Console\Msg\LibrariesLoadedMsg;
use Phlix\Console\Msg\LibraryMediaLoadedMsg;
use Phlix\Console\Msg\OpenDetailMsg;
use Phlix\Console\Msg\OpenLibraryMsg;
use Phlix\Console\Msg\OpenWatchHistoryMsg;
use Phlix\Console\Msg\PosterLoadedMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Store\LibrariesStore;
use Phlix\Console\Store\MediaStore;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\Sidebar;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Focus\FocusRing;
use SugarCraft\Gallery\Rail;
use SugarCraft\Sprinkles\Layout;

/**
 * Browse home. Loads Continue Watching plus one rail per library and renders
 * them as scrolling poster rails beside a {@see Sidebar} libraries left-nav,
 * loading each poster asynchronously (placeholder until ready).
 *
 * Two focusable regions are held in an immutable {@see FocusRing}: the rails
 * (default) and the sidebar. Tab / Shift-Tab move focus between them; the
 * focused region takes the arrow keys and shows the accent. With the rails
 * focused, ↑/↓ move between rails and ←/→ within one; with the sidebar focused,
 * ↑/↓ move the library selection. Enter (either region) drills into the
 * selected library; a Browse-time auth failure emits {@see SessionExpiredMsg}.
 *
 * @phpstan-type RailMap array<string, Rail>
 */
final class BrowseScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const CONTINUE_ID = 'continue';
    private const SIDEBAR = 'sidebar';
    private const RAILS = 'rails';
    private const SIDEBAR_WIDTH = 22;
    private const SIDEBAR_GAP = 2;
    private const CARD_WIDTH = 14;
    private const POSTER_WIDTH = 14;
    private const POSTER_HEIGHT = 9;
    private const RAIL_HEIGHT = 12;        // estimate for vertical windowing
    private const PER_LIBRARY_LIMIT = 18;
    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const RAILS_HINT = '↑↓  rails      ←→  items      ⏎  open      Tab  menu      q  quit';
    private const SIDEBAR_HINT = '↑↓  library      ⏎  open      H  history      Tab  rails      q  quit';

    private ?Rail $continueRail = null;
    /** @var array<string, Rail> keyed by library id, in display order */
    private array $libraryRails = [];
    /** @var array<string, string> library id → type (drives type-aware opening) */
    private array $libraryTypes = [];
    /** @var array<string, int> library id → item count (the grid total) */
    private array $libraryCounts = [];
    private int $railCursor = 0;
    private int $railScroll = 0;
    private ?string $error = null;
    private FocusRing $focus;
    private Sidebar $sidebar;
    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private readonly AuthUser $user,
        private readonly LibrariesStore $libraries,
        private readonly MediaStore $media,
        private readonly PosterLoader $posters,
        private readonly string $baseUrl,
        private int $cols = 80,
        private int $rows = 24,
    ) {
        // Rails are focused first so the existing rail navigation works on load;
        // Tab moves focus to the sidebar.
        $this->focus = FocusRing::of(self::RAILS, self::SIDEBAR);
        $this->sidebar = Sidebar::new(self::SIDEBAR_WIDTH);
    }

    public function init(): \Closure
    {
        return Cmd::batch($this->fetchContinueWatching(), $this->fetchLibraries());
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
        if ($msg instanceof LibrariesLoadedMsg) {
            return $this->onLibraries($msg->libraries);
        }
        if ($msg instanceof ContinueWatchingLoadedMsg) {
            return $this->onContinueWatching($msg->items);
        }
        if ($msg instanceof LibraryMediaLoadedMsg) {
            return $this->onLibraryMedia($msg->libraryId, $msg->page);
        }
        if ($msg instanceof PosterLoadedMsg) {
            return [$this->onPoster($msg->railId, $msg->cardId, $msg->ansi, $msg->imageId), null];
        }
        if ($msg instanceof LibrariesFailedMsg) {
            return [$this->withError($msg->reason), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        $ids = $this->orderedRailIds();

        if ($ids === []) {
            $name = $this->displayName();
            $body = $this->error !== null
                ? "\n  {$this->error}"
                : "\n  Welcome, {$name}.\n\n  Loading your libraries…";

            return Chrome::frame('Browse', $body, 'q  quit', $this->cols, $this->rows, $this->crumbs, $this->theme());
        }

        $railsFocused = $this->focus->isFocused(self::RAILS);

        $railWidth = $this->railWidth();
        $visible = array_slice($ids, $this->railScroll, $this->visibleRailCount());

        $blocks = [];
        foreach ($visible as $offset => $railId) {
            $absolute = $this->railScroll + $offset;
            $rail = $this->railById($railId);
            if ($rail !== null) {
                $blocks[] = $rail->render($railWidth, $railsFocused && $absolute === $this->railCursor, self::CARD_WIDTH, self::POSTER_HEIGHT);
            }
        }

        $railsBody = $blocks === [] ? '' : Layout::joinVerticalWithSpacing(0.0, 1, ...$blocks);
        $body = Layout::joinHorizontalWithSpacing(0.0, self::SIDEBAR_GAP, $this->sidebar->render($this->contentHeight()), $railsBody);

        return Chrome::frame('Browse', $body, $railsFocused ? self::RAILS_HINT : self::SIDEBAR_HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
    }

    // ---- data loading --------------------------------------------------

    private function fetchLibraries(): \Closure
    {
        return Cmd::promise(fn () => $this->libraries->all()->then(
            static fn (array $libs): Msg => new LibrariesLoadedMsg($libs),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new LibrariesFailedMsg('Could not load your libraries.'),
        ));
    }

    private function fetchContinueWatching(): \Closure
    {
        return Cmd::promise(fn () => $this->media->continueWatching()->then(
            static fn (array $items): Msg => new ContinueWatchingLoadedMsg($items),
            static fn (\Throwable $e): ?Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : null,
        ));
    }

    private function fetchLibraryMedia(string $libraryId): \Closure
    {
        $query = MediaQuery::forLibrary($libraryId, limit: self::PER_LIBRARY_LIMIT);

        return Cmd::promise(fn () => $this->media->page($query)->then(
            static fn (MediaPage $page): Msg => new LibraryMediaLoadedMsg($libraryId, $page),
            static fn (\Throwable $e): ?Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : null,
        ));
    }

    /** Batch the poster loads for a freshly-populated rail. */
    private function loadPostersFor(string $railId, Rail $rail): ?\Closure
    {
        $cmds = [];
        foreach ($rail->cards as $card) {
            if ($card->posterUrl === null || $card->posterUrl === '' || $card->hasPoster()) {
                continue;
            }
            // Resolve relative URLs against the server base URL; absolute/empty pass through.
            $url = $this->resolveUrl($card->posterUrl);
            if ($url === '') {
                continue;
            }
            // Defensive: validate URL has a valid http/https scheme before attempting load.
            // parse_url returns false for malformed URLs and null for URLs with no scheme.
            $scheme = parse_url($url, PHP_URL_SCHEME);
            if ($scheme === null || $scheme === false || !in_array($scheme, ['http', 'https'], true)) {
                // Skip malformed URLs or non-http(s) schemes silently - treat them the same as a missing poster.
                continue;
            }
            $cardId = $card->id;
            $cmds[] = Cmd::promise(fn () => $this->posters->load($url, self::POSTER_WIDTH, self::POSTER_HEIGHT)->then(
                static fn (\Phlix\Console\Media\PosterLoadResult $result): Msg => new PosterLoadedMsg($railId, $cardId, $result->marker, $result->imageId),
                static fn (\Throwable $e): ?Msg => null, // a broken poster keeps its placeholder
            ));
        }

        return $cmds === [] ? null : Cmd::batch(...$cmds);
    }

    /** Resolve a (possibly relative) URL against the server base; absolute/empty pass through. */
    private function resolveUrl(string $url): string
    {
        if ($url === '' || preg_match('#^https?://#i', $url) === 1) {
            return $url; // empty, or already absolute (signed URLs are absolute)
        }

        return rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/');
    }

    // ---- message handlers ----------------------------------------------

    /**
     * @param list<mixed> $libraries each element is validated as a {@see Library} below
     *
     * @return array{self, ?\Closure}
     */
    private function onLibraries(array $libraries): array
    {
        $rails = [];
        $types = [];
        $counts = [];
        $cmds = [];
        foreach ($libraries as $library) {
            // Skip the (production-impossible) id that collides with the
            // reserved continue-watching rail key.
            if (!$library instanceof Library || $library->id === self::CONTINUE_ID) {
                continue;
            }
            $rails[$library->id] = new Rail($library->name);
            $types[$library->id] = $library->type;
            $counts[$library->id] = $library->itemCount;
            $cmds[] = $this->fetchLibraryMedia($library->id);
        }

        $next = $this->withLibraryRails($rails);
        $next->libraryTypes = $types;
        $next->libraryCounts = $counts;
        $next = $next->withSidebar($next->sidebar->withEntries($next->sidebarEntries()));

        // Clamp the cursor in case a reload returned fewer rails than before.
        $count = count($next->orderedRailIds());
        if ($count > 0 && $next->railCursor >= $count) {
            $next = $next->withCursor($count - 1, min($next->railScroll, $count - 1));
        }

        return [$next, $cmds === [] ? null : Cmd::batch(...$cmds)];
    }

    /**
     * @param list<mixed> $items each element is validated as a {@see ContinueWatchingItem} below
     *
     * @return array{self, ?\Closure}
     */
    private function onContinueWatching(array $items): array
    {
        if ($items === []) {
            return [$this, null];
        }

        // Dedupe by media item: a title watched across several sessions/devices
        // can arrive more than once. Duplicate cards would share an id, and a
        // rail only updates the FIRST card with a given id when its poster loads
        // (the rest keep the placeholder), so collapse to one card per item —
        // keeping the first (most-recent) occurrence the server returned.
        $cards = [];
        $seen = [];
        foreach ($items as $entry) {
            if (!$entry instanceof ContinueWatchingItem) {
                continue;
            }
            $id = $entry->item->id;
            if ($id !== '' && isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $cards[] = PosterCardFactory::fromMediaItem($entry->item, $entry->progress());
        }
        if ($cards === []) {
            return [$this, null];
        }

        $rail = new Rail('Continue Watching', $cards);

        // Prepending the continue rail shifts every library +1; if the cursor
        // was already off rail 0, bump it so the user's focus stays put.
        $newlyAdded = $this->continueRail === null;
        $next = $this->withContinueRail($rail);
        if ($newlyAdded && $this->railCursor > 0) {
            $next = $next->withCursor($this->railCursor + 1, $this->railScroll > 0 ? $this->railScroll + 1 : 0);
        }

        return [$next, $next->loadPostersFor(self::CONTINUE_ID, $rail)];
    }

    /** @return array{self, ?\Closure} */
    private function onLibraryMedia(string $libraryId, MediaPage $page): array
    {
        if (!isset($this->libraryRails[$libraryId])) {
            return [$this, null];
        }

        $cards = [];
        foreach ($page->items as $item) {
            $cards[] = PosterCardFactory::fromMediaItem($item);
        }

        $rail = $this->libraryRails[$libraryId]->withCards($cards);
        $next = $this->replaceRail($libraryId, $rail);

        return [$next, $next->loadPostersFor($libraryId, $rail)];
    }

    private function onPoster(string $railId, string $cardId, string $marker, ?int $imageId): self
    {
        $rail = $this->railById($railId);
        if ($rail === null) {
            return $this;
        }

        foreach ($rail->cards as $card) {
            if ($card->id === $cardId) {
                // Use withImage() for overlay modes (sixel/kitty/iterm2), withPoster() for inline modes
                $newCard = ($imageId !== null && !$this->posters->isInline())
                    ? $card->withImage($marker, $imageId)
                    : $card->withPoster($marker);
                return $this->replaceRail($railId, $rail->withCard($newCard));
            }
        }

        return $this;
    }

    // ---- navigation ----------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::quit()];
        }
        if ($msg->type === KeyType::Tab) {
            return [$this->withFocus($msg->shift ? $this->focus->previous() : $this->focus->next()), null];
        }

        return $this->focus->isFocused(self::SIDEBAR)
            ? $this->handleSidebarKey($msg)
            : $this->handleRailsKey($msg);
    }

    /** @return array{self, ?\Closure} */
    private function handleSidebarKey(KeyMsg $msg): array
    {
        if ($this->sidebar->isEmpty()) {
            return [$this, null];
        }
        if ($msg->type === KeyType::Up) {
            return [$this->withSidebar($this->sidebar->up()), null];
        }
        if ($msg->type === KeyType::Down) {
            return [$this->withSidebar($this->sidebar->down()), null];
        }
        if ($msg->type === KeyType::Enter) {
            return $this->openLibrary($this->sidebar->selectedId());
        }
        // H → open watch history
        if ($msg->type === KeyType::Char && ($msg->rune === 'h' || $msg->rune === 'H')) {
            return [$this, Cmd::send(new OpenWatchHistoryMsg())];
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function handleRailsKey(KeyMsg $msg): array
    {
        $ids = $this->orderedRailIds();
        $count = count($ids);
        if ($count === 0) {
            return [$this, null];
        }

        if ($msg->type === KeyType::Enter) {
            // A poster opens the focused card's detail (the sidebar opens the
            // whole library). A rail whose cards haven't loaded yet is a no-op.
            $rail = $this->railById($ids[$this->railCursor] ?? '');
            $card = $rail?->focusedCard();

            return $card !== null
                ? [$this, Cmd::send(new OpenDetailMsg($card->id, $card->title))]
                : [$this, null];
        }

        if ($msg->type === KeyType::Up) {
            return [$this->moveRail(-1, $count), null];
        }
        if ($msg->type === KeyType::Down) {
            return [$this->moveRail(1, $count), null];
        }
        if ($msg->type === KeyType::Left || $msg->type === KeyType::Right) {
            $railId = $ids[$this->railCursor] ?? null;
            $rail = $railId !== null ? $this->railById($railId) : null;
            if ($railId === null || $rail === null) {
                return [$this, null];
            }
            $perRow = Rail::perRow($this->railWidth(), self::CARD_WIDTH);
            $delta = $msg->type === KeyType::Right ? 1 : -1;

            return [$this->replaceRail($railId, $rail->moveCursor($delta, $perRow)), null];
        }

        return [$this, null];
    }

    /**
     * Open the given library id's grid (a no-op for an unknown/null id).
     *
     * @return array{self, ?\Closure}
     */
    private function openLibrary(?string $libraryId): array
    {
        $rail = $libraryId !== null ? $this->railById($libraryId) : null;
        if ($libraryId === null || $libraryId === self::CONTINUE_ID || $rail === null) {
            return [$this, null];
        }

        return [$this, Cmd::send(new OpenLibraryMsg($libraryId, $rail->title, $this->libraryTypes[$libraryId] ?? '', $this->libraryCounts[$libraryId] ?? 0))];
    }

    private function moveRail(int $delta, int $count): self
    {
        $cursor = max(0, min($count - 1, $this->railCursor + $delta));
        $perScreen = $this->visibleRailCount();

        $scroll = $this->railScroll;
        if ($cursor < $scroll) {
            $scroll = $cursor;
        } elseif ($cursor >= $scroll + $perScreen) {
            $scroll = $cursor - $perScreen + 1;
        }

        return $this->withCursor($cursor, $scroll);
    }

    // ---- rail bookkeeping ----------------------------------------------

    /** @return list<string> rail ids in display order */
    private function orderedRailIds(): array
    {
        $ids = [];
        if ($this->continueRail !== null) {
            $ids[] = self::CONTINUE_ID;
        }
        foreach (array_keys($this->libraryRails) as $libraryId) {
            $ids[] = $libraryId;
        }

        return $ids;
    }

    /** @return list<array{id: string, label: string}> library rails as sidebar entries */
    private function sidebarEntries(): array
    {
        $entries = [];
        foreach ($this->libraryRails as $id => $rail) {
            $entries[] = ['id' => $id, 'label' => $rail->title];
        }

        return $entries;
    }

    private function railById(string $railId): ?Rail
    {
        return $railId === self::CONTINUE_ID
            ? $this->continueRail
            : ($this->libraryRails[$railId] ?? null);
    }

    private function replaceRail(string $railId, Rail $rail): self
    {
        if ($railId === self::CONTINUE_ID) {
            return $this->withContinueRail($rail);
        }

        $rails = $this->libraryRails;
        if (isset($rails[$railId])) {
            $rails[$railId] = $rail;
        }

        return $this->withLibraryRails($rails);
    }

    private function railWidth(): int
    {
        // The sidebar takes a fixed left column; the rails get the rest.
        return max(self::CARD_WIDTH, $this->cols - 4 - self::SIDEBAR_WIDTH - self::SIDEBAR_GAP);
    }

    private function visibleRailCount(): int
    {
        return max(1, intdiv(max(1, Chrome::bodyHeight($this->rows)), self::RAIL_HEIGHT));
    }

    /** The vertical room for the content region (the sidebar fills this). */
    private function contentHeight(): int
    {
        return max(self::POSTER_HEIGHT, Chrome::bodyHeight($this->rows));
    }

    private function displayName(): string
    {
        return $this->user->displayName !== '' ? $this->user->displayName : $this->user->username;
    }

    // ---- immutable copies (clone-mutate) -------------------------------

    private function withContinueRail(Rail $rail): self
    {
        $next = clone $this;
        $next->continueRail = $rail;

        return $next;
    }

    /** @param array<string, Rail> $rails */
    private function withLibraryRails(array $rails): self
    {
        $next = clone $this;
        $next->libraryRails = $rails;

        return $next;
    }

    private function withCursor(int $cursor, int $scroll): self
    {
        $next = clone $this;
        $next->railCursor = $cursor;
        $next->railScroll = $scroll;

        return $next;
    }

    private function withError(string $error): self
    {
        $next = clone $this;
        $next->error = $error;

        return $next;
    }

    /** Move focus, keeping the sidebar's accent in sync with the ring. */
    private function withFocus(FocusRing $focus): self
    {
        $next = clone $this;
        $next->focus = $focus;
        $next->sidebar = $this->sidebar->withFocus($focus->isFocused(self::SIDEBAR));

        return $next;
    }

    private function withSidebar(Sidebar $sidebar): self
    {
        $next = clone $this;
        $next->sidebar = $sidebar;

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
        return 'Home';
    }

    public function withCrumbs(array $trail): static
    {
        $next = clone $this;
        $next->crumbs = $trail;

        return $next;
    }

    // ---- accessors (for tests) ----------------------------------------

    /** @return list<string> */
    public function railIds(): array
    {
        return $this->orderedRailIds();
    }

    public function rail(string $railId): ?Rail
    {
        return $this->railById($railId);
    }

    public function railCursor(): int
    {
        return $this->railCursor;
    }

    /** The focused region id ('rails' or 'sidebar'). */
    public function focusedRegion(): ?string
    {
        return $this->focus->current();
    }

    public function sidebar(): Sidebar
    {
        return $this->sidebar;
    }
}
