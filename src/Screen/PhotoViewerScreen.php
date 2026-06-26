<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Photo;
use Phlix\Console\Api\Dto\PhotoAlbum;
use Phlix\Console\Api\Dto\PhotoExif;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\PhotoExifLoadedMsg;
use Phlix\Console\Msg\PhotoImageLoadedMsg;
use Phlix\Console\Msg\PhotoSlideTickMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Store\PhotosStore;
use Phlix\Console\Ui\Chrome;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Layout;
use SugarCraft\Sprinkles\Style;

/**
 * The fullscreen photo viewer: the current photo's full image fills the body,
 * captioned with its name and position (`name · 3/42`), with ←/→ paging to the
 * prev/next photo (clamped at the ends), `i` toggling an EXIF side panel, and
 * `s` toggling an auto-advancing slideshow. Esc returns.
 *
 * The album carries every photo (each with a signed `full_url`), so paging and
 * the slideshow cycle CLIENT-SIDE with no extra round-trip; the image renders
 * asynchronously via {@see PosterLoader} (a dim placeholder shows meanwhile) and
 * the EXIF map is fetched lazily off the photo DETAIL ({@see PhotosStore::photo}).
 * Both loads are strictly best-effort: any failure leaves the placeholder / a
 * "No EXIF data" panel, never crashing — only an EXIF AuthError surfaces (as a
 * session expiry) so the App can re-authenticate.
 *
 * TWO independent generations guard the async work (the epoch-tick discipline
 * from AlbumScreen, applied twice):
 *   • `$gen` — the image+EXIF load generation. Bumped on every nav / EXIF toggle
 *     / resize so a result resolved for a superseded photo or width is dropped.
 *   • `$slideEpoch` — the slideshow countdown generation. Bumped when the
 *     slideshow toggles off and on every MANUAL nav while it runs (resetting the
 *     countdown), so a leftover tick from a superseded chain is dropped — an
 *     auto-advance continues the SAME epoch (no bump) to keep one chain alive.
 *
 * This is deliberately NOT {@see Teardownable}: there is no subprocess to stop.
 * A slide tick that arrives after the screen is popped routes to the new top
 * screen (which ignores it), and the chain dies because it only re-arms while
 * THIS screen processes a live tick.
 *
 * Stable collaborators are readonly; mutable view state is private and copied
 * via clone-mutate (the established screen idiom) — never mutated in place.
 */
final class PhotoViewerScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const EXIF_WIDTH = 30;
    private const COL_GAP = 2;
    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const HINT = '←→  prev/next      i  EXIF      s  slideshow      Esc  back';

    private int $index;
    private ?string $imageAnsi = null;
    private ?PhotoExif $exif = null;
    private bool $exifLoaded = false;
    private bool $showExif = false;
    private bool $slideshow = false;
    /** The image+EXIF load generation (bumped on nav / EXIF toggle / resize). */
    private int $gen = 0;
    /** The slideshow countdown generation (bumped on toggle-off + manual nav). */
    private int $slideEpoch = 0;
    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private readonly PhotoAlbum $album,
        int $index,
        private readonly PhotosStore $store,
        private readonly PosterLoader $posters,
        private readonly string $baseUrl,
        private int $cols = 80,
        private int $rows = 24,
        private readonly float $slideInterval = 4.0,
    ) {
        // Clamp into [0, last]; an empty album floors to 0 (currentPhoto() is then
        // null and the view renders "No photos").
        $this->index = max(0, min($index, count($album->photos) - 1));
    }

    public function init(): ?\Closure
    {
        return $this->batchOrNull($this->loadCmdList());
    }

    /** @return array{self, ?\Closure} */
    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return $this->onResize($msg->cols, $msg->rows);
        }
        if ($msg instanceof PhotoImageLoadedMsg) {
            return [$msg->generation === $this->gen ? $this->withImage($msg->ansi) : $this, null];
        }
        if ($msg instanceof PhotoExifLoadedMsg) {
            return [$msg->generation === $this->gen ? $this->withExif($msg->exif) : $this, null];
        }
        if ($msg instanceof PhotoSlideTickMsg) {
            return $this->slideshow && $msg->epoch === $this->slideEpoch ? $this->advanceSlide() : [$this, null];
        }
        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }

        return [$this, null];
    }

    public function view(): string
    {
        $photo = $this->currentPhoto();
        if ($photo === null) {
            return Chrome::frame($this->album->date, "\n  No photos.", self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
        }

        $caption = Width::truncate($this->captionText($photo), max(1, $this->cols - 4));
        $image = $this->imageAnsi ?? $this->imagePlaceholder();
        $body = $this->showExif
            ? Layout::joinHorizontalWithSpacing(0.0, self::COL_GAP, $image, $this->exifColumn())
            : $image;

        return Chrome::frame($photo->name, $caption . "\n\n" . $body, self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
    }

    /** The photo at the current index, or null when the album is empty. */
    public function currentPhoto(): ?Photo
    {
        return $this->album->photos[$this->index] ?? null;
    }

    // ---- input ---------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        return match (true) {
            $msg->type === KeyType::Left => $this->move(-1),
            $msg->type === KeyType::Right => $this->move(1),
            $msg->type === KeyType::Char && $msg->rune === 'i' => $this->toggleExif(),
            $msg->type === KeyType::Char && $msg->rune === 's' => $this->toggleSlideshow(),
            $msg->type === KeyType::Escape => [$this, Cmd::send(new NavigateBackMsg())],
            default => [$this, null],
        };
    }

    /**
     * Page $delta photos (clamped to the album bounds — NO wrap on manual nav).
     * Loads the new photo's image + EXIF (bumping the load generation so stale
     * results drop); while the slideshow runs, also bumps the slide epoch and
     * re-arms the tick so manual paging resets the countdown.
     *
     * @return array{self, ?\Closure}
     */
    private function move(int $delta): array
    {
        $count = count($this->album->photos);
        if ($count === 0) {
            return [$this, null];
        }
        $new = max(0, min($count - 1, $this->index + $delta));
        if ($new === $this->index) {
            return [$this, null];
        }

        $next = clone $this;
        $next->index = $new;
        $next->imageAnsi = null;
        $next->exif = null;
        $next->exifLoaded = false;
        $next->gen = $this->gen + 1;

        $cmds = $next->loadCmdList();
        if ($next->slideshow) {
            // Manual nav resets the slideshow countdown: bump the epoch (dropping
            // the pending tick) and arm a fresh one from the new photo.
            $next->slideEpoch = $this->slideEpoch + 1;
            $cmds[] = $next->slideTickCmd($next->slideEpoch);
        }

        return [$next, $this->batchOrNull($cmds)];
    }

    /**
     * Toggle the EXIF side panel. The image width changes (it shares the body
     * with the panel), so the image reloads at the new width under a fresh
     * generation; the placeholder shows in the meantime. EXIF is already cached
     * (or in flight) so its refetch is free — keeping the reload uniform.
     *
     * @return array{self, ?\Closure}
     */
    private function toggleExif(): array
    {
        $next = clone $this;
        $next->showExif = !$this->showExif;
        $next->gen = $this->gen + 1;
        $next->imageAnsi = null;

        return [$next, $this->batchOrNull($next->loadCmdList())];
    }

    /**
     * Toggle the slideshow. Turning it on arms the first tick; turning it off
     * bumps the epoch so the pending tick is dropped. Either way the bump
     * supersedes any in-flight countdown.
     *
     * @return array{self, ?\Closure}
     */
    private function toggleSlideshow(): array
    {
        $next = clone $this;
        $next->slideshow = !$this->slideshow;
        $next->slideEpoch = $this->slideEpoch + 1;

        return [$next, $next->slideshow ? $next->slideTickCmd($next->slideEpoch) : null];
    }

    /**
     * Advance one photo on a valid slide tick (wrapping at the end), then re-arm
     * the SAME epoch to continue the chain (no bump — a bump would orphan it). A
     * single/empty album keeps the timer alive with no change.
     *
     * @return array{self, ?\Closure}
     */
    private function advanceSlide(): array
    {
        $count = count($this->album->photos);
        if ($count <= 1) {
            return [$this, $this->slideTickCmd($this->slideEpoch)];
        }

        $next = clone $this;
        $next->index = ($this->index + 1) % $count;
        $next->imageAnsi = null;
        $next->exif = null;
        $next->exifLoaded = false;
        $next->gen = $this->gen + 1;

        $cmds = $next->loadCmdList();
        // Continue the SAME slide epoch (do NOT bump) so one chain stays alive.
        $cmds[] = $next->slideTickCmd($this->slideEpoch);

        return [$next, Cmd::batch(...$cmds)];
    }

    /** @return array{self, ?\Closure} */
    private function onResize(int $cols, int $rows): array
    {
        $next = clone $this;
        $next->cols = $cols;
        $next->rows = $rows;
        // The rendered ANSI is sized to the old viewport, so invalidate it and
        // reload under a fresh generation (EXIF is cached, so its refetch is free
        // — keeping the reload uniform).
        $next->gen = $this->gen + 1;
        $next->imageAnsi = null;

        return [$next, $this->batchOrNull($next->loadCmdList())];
    }

    // ---- async loads ---------------------------------------------------

    /**
     * Build the load Cmds for the current photo under the current generation: the
     * full image (only when it has a `full_url`) and its EXIF (off the detail).
     * Both are best-effort — only an EXIF AuthError surfaces (as a session
     * expiry); a non-auth EXIF error becomes a null-EXIF result so the panel
     * shows "No EXIF data" rather than hanging on "Loading EXIF…".
     *
     * @return list<\Closure>
     */
    private function loadCmdList(): array
    {
        $photo = $this->currentPhoto();
        if ($photo === null) {
            return [];
        }

        $gen = $this->gen;
        $cmds = [];

        if ($photo->fullUrl !== null) {
            $cmds[] = Cmd::promise(fn () => $this->posters->load($this->resolveUrl($photo->fullUrl), $this->imageWidth(), $this->imageHeight())->then(
                static fn (string $ansi): Msg => new PhotoImageLoadedMsg($gen, $ansi),
                static fn (\Throwable $e): ?Msg => null, // a broken image keeps the placeholder
            ));
        }

        $cmds[] = Cmd::promise(fn () => $this->store->photo($photo->id)->then(
            static fn (Photo $p): Msg => new PhotoExifLoadedMsg($gen, $p->exif),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new PhotoExifLoadedMsg($gen, null),
        ));

        return $cmds;
    }

    private function slideTickCmd(int $epoch): \Closure
    {
        return Cmd::tick($this->slideInterval, static fn (): Msg => new PhotoSlideTickMsg($epoch));
    }

    /** The image width: narrowed for the EXIF panel when it is shown. */
    private function imageWidth(): int
    {
        return $this->showExif
            ? max(20, $this->cols - 4 - self::EXIF_WIDTH - self::COL_GAP)
            : max(1, $this->cols - 4);
    }

    /** The image height: the body less the caption + blank (2). */
    private function imageHeight(): int
    {
        return max(1, Chrome::bodyHeight($this->rows) - 2);
    }

    // ---- rendering -----------------------------------------------------

    private function captionText(Photo $photo): string
    {
        $caption = $photo->name . '   ·   ' . ($this->index + 1) . '/' . count($this->album->photos);
        if ($this->slideshow) {
            $caption .= '   ·   ▶ slideshow';
        }

        return $caption;
    }

    /** A dim placeholder block the exact size of the image, shown until it loads. */
    private function imagePlaceholder(): string
    {
        $dim = Style::new()->faint();
        $row = $dim->render(str_repeat('░', $this->imageWidth()));

        return implode("\n", array_fill(0, $this->imageHeight(), $row));
    }

    /**
     * The EXIF side column (width {@see EXIF_WIDTH}): a "Loading EXIF…" /
     * "No EXIF data" notice, or a bold "EXIF" header above one bold label line +
     * its wrapped, indented value per non-null pair. Every line is width-clamped
     * so the column never bleeds into the image.
     */
    private function exifColumn(): string
    {
        $dim = Style::new()->faint();

        if (!$this->exifLoaded) {
            return $dim->render(Width::truncate('Loading EXIF…', self::EXIF_WIDTH));
        }
        if ($this->exif === null || $this->exif->isEmpty()) {
            return $dim->render(Width::truncate('No EXIF data', self::EXIF_WIDTH));
        }

        $bold = Style::new()->bold();
        $lines = [$bold->render(Width::truncate('EXIF', self::EXIF_WIDTH))];
        foreach ($this->exif->displayPairs() as [$label, $value]) {
            $lines[] = $bold->render(Width::truncate($label, self::EXIF_WIDTH));
            foreach (explode("\n", Width::wrap($value, self::EXIF_WIDTH - 2)) as $valueLine) {
                $lines[] = Width::truncate('  ' . $valueLine, self::EXIF_WIDTH);
            }
        }

        return implode("\n", $lines);
    }

    /** Resolve a (possibly relative) URL against the server base; absolute/empty pass through. */
    private function resolveUrl(string $url): string
    {
        if ($url === '' || preg_match('#^https?://#i', $url) === 1) {
            return $url; // empty, or already absolute (signed URLs are absolute)
        }

        return rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/');
    }

    /**
     * Batch the Cmds, or null when there are none (so init()/update can return null cleanly).
     *
     * @param list<\Closure> $cmds
     */
    private function batchOrNull(array $cmds): ?\Closure
    {
        return $cmds === [] ? null : Cmd::batch(...$cmds);
    }

    // ---- immutable copies (clone-mutate) -------------------------------

    private function withImage(string $ansi): self
    {
        $next = clone $this;
        $next->imageAnsi = $ansi;

        return $next;
    }

    private function withExif(?PhotoExif $exif): self
    {
        $next = clone $this;
        $next->exif = $exif;
        $next->exifLoaded = true;

        return $next;
    }

    // ---- breadcrumb ----------------------------------------------------

    public function crumbLabel(): string
    {
        return $this->currentPhoto()->name ?? $this->album->date;
    }

    public function withCrumbs(array $trail): static
    {
        $next = clone $this;
        $next->crumbs = $trail;

        return $next;
    }

    // ---- accessors (for tests) ----------------------------------------

    public function index(): int
    {
        return $this->index;
    }

    public function showExif(): bool
    {
        return $this->showExif;
    }

    public function slideshow(): bool
    {
        return $this->slideshow;
    }

    public function exif(): ?PhotoExif
    {
        return $this->exif;
    }

    public function hasImage(): bool
    {
        return $this->imageAnsi !== null;
    }

    /** The current image+EXIF load generation (a load result carries this). */
    public function gen(): int
    {
        return $this->gen;
    }

    /** The current slideshow countdown generation (an armed slide tick carries this). */
    public function slideEpoch(): int
    {
        return $this->slideEpoch;
    }
}
