<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Dto\PhotoAlbum;
use Phlix\Console\Media\PhotoCardFactory;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Msg\GridPosterLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenPhotoMsg;
use Phlix\Console\Ui\Chrome;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Gallery\PosterCard;
use SugarCraft\Gallery\PosterGrid;

/**
 * One photo album's thumbnail grid: a 2-D virtualized {@see PosterGrid} over the
 * album's photos. ↑↓←→ move, PgUp/PgDn page, Home/End jump, Esc returns.
 *
 * The album carries its photos in memory (each with a signed `thumbnail_url`),
 * so the grid is built FULLY in the constructor — there is NO data fetch at all.
 * Each card's thumbnail is KNOWN UPFRONT (built in as `posterUrl`), so the grid
 * loads visible thumbnails DIRECTLY (no per-card detail fetch); a photo with no
 * thumbnail keeps its placeholder. Thumbnail loading is strictly best-effort:
 * any fetch/render failure leaves the placeholder, never crashing.
 *
 * Enter opens the fullscreen photo viewer at the cursor's photo — it emits an
 * {@see OpenPhotoMsg} carrying the whole album + the cursor index, which the App
 * turns into a pushed {@see PhotoViewerScreen}. A no-op when the album is empty.
 *
 * Stable collaborators are readonly; mutable view state is private and copied via
 * clone-mutate (the established screen idiom).
 */
final class PhotoAlbumScreen implements Breadcrumbed
{
    use SubscriptionCapable;

    private const CARD_WIDTH = 14;
    private const POSTER_HEIGHT = 9;
    private const H_SPACING = 2;
    private const V_SPACING = 1;
    private const OVERSCAN = 1;
    private const HINT = '↑↓←→  move      ⏎  view      Esc  back';

    private PosterGrid $grid;
    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private readonly PhotoAlbum $album,
        private readonly PosterLoader $posters,
        private readonly string $baseUrl,
        private int $cols = 80,
        private int $rows = 24,
    ) {
        $cards = [];
        foreach ($album->photos as $index => $photo) {
            $cards[$index] = PhotoCardFactory::fromPhoto($photo);
        }

        $this->grid = PosterGrid::new(self::CARD_WIDTH, self::POSTER_HEIGHT, self::H_SPACING, self::V_SPACING)
            ->withViewport(self::viewportCols($cols), self::viewportRows($rows))
            ->reset(count($album->photos))
            ->withItems($cards);
    }

    public function init(): ?\Closure
    {
        // No data fetch — the album carries its photos. Just load the initially
        // visible thumbnails (each card already has its signed thumbnail URL).
        return $this->loadCoversIn($this->grid, ...$this->grid->visibleRange(self::OVERSCAN));
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return $this->onResize($msg->cols, $msg->rows);
        }
        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }
        if ($msg instanceof GridPosterLoadedMsg) {
            return [$this->onPoster($msg->index, $msg->ansi), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        $total = $this->grid->total();
        if ($total === 0) {
            $header = 'No photos';
        } else {
            $header = $total . ' photos   ·   ' . ($this->grid->cursorIndex() + 1) . '/' . $total;
        }

        $body = $header . "\n\n" . $this->grid->render(true);

        return Chrome::frame($this->album->date, $body, self::HINT, $this->cols, $this->rows, $this->crumbs);
    }

    // ---- input ---------------------------------------------------------

    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Enter) {
            // Open the fullscreen viewer at the cursor's photo (the App pushes a
            // PhotoViewerScreen). A no-op when the album is empty.
            return $this->album->photos !== [] && $this->grid->cursorCard() !== null
                ? [$this, Cmd::send(new OpenPhotoMsg($this->album, $this->grid->cursorIndex()))]
                : [$this, null];
        }

        $grid = match ($msg->type) {
            KeyType::Left => $this->grid->left(),
            KeyType::Right => $this->grid->right(),
            KeyType::Up => $this->grid->up(),
            KeyType::Down => $this->grid->down(),
            KeyType::PageUp => $this->grid->pageUp(),
            KeyType::PageDown => $this->grid->pageDown(),
            KeyType::Home => $this->grid->home(),
            KeyType::End => $this->grid->end(),
            default => $this->grid,
        };

        if ($grid === $this->grid) {
            return [$this, null];
        }

        return $this->afterGridChange($grid);
    }

    private function onResize(int $cols, int $rows): array
    {
        $grid = $this->grid->withViewport(self::viewportCols($cols), self::viewportRows($rows));

        $next = clone $this;
        $next->cols = $cols;
        $next->rows = $rows;
        $next->grid = $grid;

        return $next->afterGridChange($grid);
    }

    // ---- data ----------------------------------------------------------

    /**
     * After the grid's cursor/viewport moved: load thumbnails for the cells now
     * on screen. All photo data is already in memory, so there is NO fetch — only
     * the lazy thumbnail render for newly-visible cells.
     */
    private function afterGridChange(PosterGrid $grid): array
    {
        $next = clone $this;
        $next->grid = $grid;

        return [$next, $next->loadCoversIn($grid, ...$grid->visibleRange(self::OVERSCAN))];
    }

    private function onPoster(int $index, string $ansi): self
    {
        $card = $this->grid->item($index);
        if ($card === null) {
            return $this;
        }

        return $this->withGrid($this->grid->withItem($index, $card->withPoster($ansi)));
    }

    /**
     * Load thumbnails for the cover-less cells in [start, end]: each card already
     * carries its signed thumbnail as `posterUrl`, so render it DIRECTLY (no
     * detail fetch) → {@see GridPosterLoadedMsg}. A card with a null `posterUrl`
     * is skipped — it keeps its placeholder. Any render failure is swallowed so
     * the cell keeps its placeholder, never crashing.
     */
    private function loadCoversIn(PosterGrid $grid, int $start, int $end): ?\Closure
    {
        $cmds = [];
        for ($i = max(0, $start); $i <= $end; $i++) {
            $card = $grid->item($i);
            if ($card === null || $card->hasPoster() || $card->posterUrl === null) {
                continue;
            }
            $cmds[] = $this->loadCover($i, $card->posterUrl);
        }

        return $cmds === [] ? null : Cmd::batch(...$cmds);
    }

    private function loadCover(int $index, string $thumbnailUrl): \Closure
    {
        return Cmd::promise(fn () => $this->posters->load($this->resolveUrl($thumbnailUrl), self::CARD_WIDTH, self::POSTER_HEIGHT)->then(
            static fn (string $ansi): Msg => new GridPosterLoadedMsg($index, $ansi),
            static fn (\Throwable $e): ?Msg => null, // best-effort: a broken thumbnail keeps its skeleton
        ));
    }

    /** Resolve a (possibly relative) URL against the server base; absolute/empty pass through. */
    private function resolveUrl(string $url): string
    {
        if ($url === '' || preg_match('#^https?://#i', $url) === 1) {
            return $url; // empty, or already absolute (signed URLs are absolute)
        }

        return rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/');
    }

    // ---- immutable copies (clone-mutate) -------------------------------

    private function withGrid(PosterGrid $grid): self
    {
        $next = clone $this;
        $next->grid = $grid;

        return $next;
    }

    private static function viewportCols(int $cols): int
    {
        return max(self::CARD_WIDTH, $cols - 4);
    }

    private static function viewportRows(int $rows): int
    {
        // The content panel fills the frame; window the grid to that body height
        // less the count line + blank above it (2), so the bottom rows never clip.
        return max(self::POSTER_HEIGHT + 2, Chrome::bodyHeight($rows) - 2);
    }

    // ---- breadcrumb ----------------------------------------------------

    public function crumbLabel(): string
    {
        return $this->album->date;
    }

    public function withCrumbs(array $trail): static
    {
        $next = clone $this;
        $next->crumbs = $trail;

        return $next;
    }

    // ---- accessors (for tests) ----------------------------------------

    public function album(): PhotoAlbum
    {
        return $this->album;
    }

    public function grid(): PosterGrid
    {
        return $this->grid;
    }
}
