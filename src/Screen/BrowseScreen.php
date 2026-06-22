<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\AuthUser;
use Phlix\Console\Api\Dto\ContinueWatchingItem;
use Phlix\Console\Api\Dto\Library;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Api\Dto\MediaPage;
use Phlix\Console\Api\MediaQuery;
use Phlix\Console\Media\PosterCardFactory;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Msg\ContinueWatchingLoadedMsg;
use Phlix\Console\Msg\LibrariesFailedMsg;
use Phlix\Console\Msg\LibrariesLoadedMsg;
use Phlix\Console\Msg\LibraryMediaLoadedMsg;
use Phlix\Console\Msg\PosterLoadedMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Store\LibrariesStore;
use Phlix\Console\Store\MediaStore;
use Phlix\Console\Ui\Chrome;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Gallery\Rail;
use SugarCraft\Sprinkles\Layout;

/**
 * Browse home — the Phase 1 deliverable. Loads Continue Watching plus one rail
 * per library, renders them as scrolling poster rails, and loads each poster
 * asynchronously (placeholder until ready). ↑/↓ move between rails, ←/→ within
 * a rail. A Browse-time auth failure emits {@see SessionExpiredMsg} (the App
 * returns to login).
 *
 * @phpstan-type RailMap array<string, Rail>
 */
final class BrowseScreen implements Model
{
    use SubscriptionCapable;

    private const CONTINUE_ID = 'continue';
    private const CARD_WIDTH = 14;
    private const POSTER_WIDTH = 14;
    private const POSTER_HEIGHT = 9;
    private const RAIL_HEIGHT = 12;        // estimate for vertical windowing
    private const PER_LIBRARY_LIMIT = 18;
    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';

    /**
     * @param array<string, Rail> $libraryRails  keyed by library id, in display order
     */
    public function __construct(
        private readonly AuthUser $user,
        private readonly LibrariesStore $libraries,
        private readonly MediaStore $media,
        private readonly PosterLoader $posters,
        private readonly ?Rail $continueRail = null,
        private readonly array $libraryRails = [],
        private readonly int $railCursor = 0,
        private readonly int $railScroll = 0,
        private readonly ?string $error = null,
        private readonly int $cols = 80,
        private readonly int $rows = 24,
    ) {
    }

    public function init(): ?\Closure
    {
        return Cmd::batch($this->fetchContinueWatching(), $this->fetchLibraries());
    }

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
            return [$this->onPoster($msg->railId, $msg->cardId, $msg->ansi), null];
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

            return Chrome::frame('Browse', $body, 'q  quit', $this->cols, $this->rows);
        }

        $railWidth = $this->railWidth();
        $visible = array_slice($ids, $this->railScroll, $this->visibleRailCount());

        $blocks = [];
        foreach ($visible as $offset => $railId) {
            $absolute = $this->railScroll + $offset;
            $rail = $this->railById($railId);
            if ($rail !== null) {
                $blocks[] = $rail->render($railWidth, $absolute === $this->railCursor, self::CARD_WIDTH, self::POSTER_HEIGHT);
            }
        }

        $body = $blocks === [] ? '' : Layout::joinVerticalWithSpacing(0.0, 1, ...$blocks);

        return Chrome::frame('Browse', $body, '↑↓  rails      ←→  items      q  quit', $this->cols, $this->rows);
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
            static fn (array $items): ?Msg => new ContinueWatchingLoadedMsg($items),
            static fn (\Throwable $e): ?Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : null,
        ));
    }

    private function fetchLibraryMedia(string $libraryId): \Closure
    {
        $query = MediaQuery::forLibrary($libraryId, limit: self::PER_LIBRARY_LIMIT);

        return Cmd::promise(fn () => $this->media->page($query)->then(
            static fn (MediaPage $page): ?Msg => new LibraryMediaLoadedMsg($libraryId, $page),
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
            if ($card->posterUrl === null || $card->hasPoster()) {
                continue;
            }
            $url = $card->posterUrl;
            $cardId = $card->id;
            $cmds[] = Cmd::promise(fn () => $this->posters->load($url, self::POSTER_WIDTH, self::POSTER_HEIGHT)->then(
                static fn (string $ansi): Msg => new PosterLoadedMsg($railId, $cardId, $ansi),
                static fn (\Throwable $e): ?Msg => null, // a broken poster keeps its placeholder
            ));
        }

        return $cmds === [] ? null : Cmd::batch(...$cmds);
    }

    // ---- message handlers ----------------------------------------------

    private function onLibraries(array $libraries): array
    {
        $rails = [];
        $cmds = [];
        foreach ($libraries as $library) {
            // Skip the (production-impossible) id that collides with the
            // reserved continue-watching rail key.
            if (!$library instanceof Library || $library->id === self::CONTINUE_ID) {
                continue;
            }
            $rails[$library->id] = new Rail($library->name);
            $cmds[] = $this->fetchLibraryMedia($library->id);
        }

        $next = $this->withLibraryRails($rails);

        // Clamp the cursor in case a reload returned fewer rails than before.
        $count = count($next->orderedRailIds());
        if ($count > 0 && $next->railCursor >= $count) {
            $next = $next->withCursor($count - 1, min($next->railScroll, $count - 1));
        }

        return [$next, $cmds === [] ? null : Cmd::batch(...$cmds)];
    }

    private function onContinueWatching(array $items): array
    {
        if ($items === []) {
            return [$this, null];
        }

        $cards = [];
        foreach ($items as $entry) {
            if ($entry instanceof ContinueWatchingItem) {
                $cards[] = PosterCardFactory::fromMediaItem($entry->item, $entry->progress());
            }
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

    private function onLibraryMedia(string $libraryId, MediaPage $page): array
    {
        if (!isset($this->libraryRails[$libraryId])) {
            return [$this, null];
        }

        $cards = [];
        foreach ($page->items as $item) {
            if ($item instanceof MediaItem) {
                $cards[] = PosterCardFactory::fromMediaItem($item);
            }
        }

        $rail = $this->libraryRails[$libraryId]->withCards($cards);
        $next = $this->replaceRail($libraryId, $rail);

        return [$next, $next->loadPostersFor($libraryId, $rail)];
    }

    private function onPoster(string $railId, string $cardId, string $ansi): self
    {
        $rail = $this->railById($railId);
        if ($rail === null) {
            return $this;
        }

        foreach ($rail->cards as $card) {
            if ($card->id === $cardId) {
                return $this->replaceRail($railId, $rail->withCard($card->withPoster($ansi)));
            }
        }

        return $this;
    }

    // ---- navigation ----------------------------------------------------

    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::quit()];
        }

        $ids = $this->orderedRailIds();
        $count = count($ids);
        if ($count === 0) {
            return [$this, null];
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
        return max(10, $this->cols - 4);
    }

    private function visibleRailCount(): int
    {
        return max(1, intdiv(max(1, $this->rows - 4), self::RAIL_HEIGHT));
    }

    private function displayName(): string
    {
        return $this->user->displayName !== '' ? $this->user->displayName : $this->user->username;
    }

    // ---- immutable copies ----------------------------------------------

    private function withContinueRail(Rail $rail): self
    {
        return new self($this->user, $this->libraries, $this->media, $this->posters, $rail, $this->libraryRails, $this->railCursor, $this->railScroll, $this->error, $this->cols, $this->rows);
    }

    /** @param array<string, Rail> $rails */
    private function withLibraryRails(array $rails): self
    {
        return new self($this->user, $this->libraries, $this->media, $this->posters, $this->continueRail, $rails, $this->railCursor, $this->railScroll, $this->error, $this->cols, $this->rows);
    }

    private function withCursor(int $cursor, int $scroll): self
    {
        return new self($this->user, $this->libraries, $this->media, $this->posters, $this->continueRail, $this->libraryRails, $cursor, $scroll, $this->error, $this->cols, $this->rows);
    }

    private function withError(string $error): self
    {
        return new self($this->user, $this->libraries, $this->media, $this->posters, $this->continueRail, $this->libraryRails, $this->railCursor, $this->railScroll, $error, $this->cols, $this->rows);
    }

    private function resizedTo(int $cols, int $rows): self
    {
        return new self($this->user, $this->libraries, $this->media, $this->posters, $this->continueRail, $this->libraryRails, $this->railCursor, $this->railScroll, $this->error, $cols, $rows);
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
}
