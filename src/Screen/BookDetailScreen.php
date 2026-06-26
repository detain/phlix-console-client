<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Book;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Msg\BookDetailPosterLoadedMsg;
use Phlix\Console\Msg\BookFailedMsg;
use Phlix\Console\Msg\BookLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Store\BooksStore;
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
 * A single book's detail: a hero cover beside a metadata column (title,
 * `by <author>`, the uppercased format) and a download area.
 *
 * There is no in-terminal reader (§6.9), so the screen surfaces the book's
 * signed `download_url` as copyable text with a hint to open it in a browser or
 * e-reader. The full {@see Book} (with its signed cover/download/read URLs) is
 * fetched via {@see BooksStore::book()}; the cover renders asynchronously so the
 * screen appears instantly with a dim placeholder.
 *
 * Stable collaborators are readonly; mutable view state is private and copied via
 * clone-mutate (the established screen idiom). A leaf screen: there is nothing to
 * play, so `p`/Enter do nothing special.
 */
final class BookDetailScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const HERO_WIDTH = 26;
    private const HERO_HEIGHT = 16;
    private const COL_GAP = 3;
    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const DOWNLOAD_HINT = 'Open this URL in a browser or e-reader to download:';
    private const NO_DOWNLOAD = 'No download is available for this book.';
    private const HINT = 'Esc  back';

    private ?Book $book = null;
    private bool $loaded = false;
    private ?string $error = null;
    private ?string $heroAnsi = null;
    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private readonly BooksStore $books,
        private readonly PosterLoader $posters,
        private readonly string $baseUrl,
        private readonly string $id,
        private readonly string $title,
        private int $cols = 80,
        private int $rows = 24,
    ) {
    }

    public function init(): \Closure
    {
        return $this->fetchBook();
    }

    /** @return array{self, ?\Closure} */
    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            $next = clone $this;
            $next->cols = $msg->cols;
            $next->rows = $msg->rows;

            return [$next, null];
        }
        if ($msg instanceof KeyMsg) {
            if ($msg->type === KeyType::Escape) {
                return [$this, Cmd::send(new NavigateBackMsg())];
            }

            return [$this, null];
        }
        if ($msg instanceof BookLoadedMsg) {
            return $this->onLoaded($msg->book);
        }
        if ($msg instanceof BookDetailPosterLoadedMsg) {
            return [$this->withHero($msg->ansi), null];
        }
        if ($msg instanceof BookFailedMsg) {
            return [$this->withError($msg->reason), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        if ($this->error !== null) {
            return Chrome::frame($this->headerTitle(), "\n  {$this->error}", self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
        }
        if (!$this->loaded || $this->book === null) {
            return Chrome::frame($this->headerTitle(), "\n  Loading…", self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
        }

        $hero = $this->heroAnsi ?? $this->heroPlaceholder();
        $column = $this->metadataColumn($this->book);
        $body = Layout::joinHorizontalWithSpacing(0.0, self::COL_GAP, $hero, $column);

        return Chrome::frame($this->headerTitle(), $body, self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
    }

    // ---- data ----------------------------------------------------------

    private function fetchBook(): \Closure
    {
        return Cmd::promise(fn () => $this->books->book($this->id)->then(
            static fn (Book $book): Msg => new BookLoadedMsg($book),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new BookFailedMsg('Could not load this book.'),
        ));
    }

    /** @return array{self, ?\Closure} */
    private function onLoaded(Book $book): array
    {
        $next = clone $this;
        $next->book = $book;
        $next->loaded = true;

        // Load the hero cover (if the detail minted a signed one).
        $cmd = $book->coverUrl !== null ? $next->fetchHero($this->resolveUrl($book->coverUrl)) : null;

        return [$next, $cmd];
    }

    private function fetchHero(string $url): \Closure
    {
        return Cmd::promise(fn () => $this->posters->load($url, self::HERO_WIDTH, self::HERO_HEIGHT)->then(
            static fn (string $ansi): Msg => new BookDetailPosterLoadedMsg($ansi),
            static fn (\Throwable $e): ?Msg => null, // a broken cover keeps the placeholder
        ));
    }

    // ---- rendering -----------------------------------------------------

    private function metadataColumn(Book $book): string
    {
        $width = $this->columnWidth();
        $accent = Style::new()->bold();

        $lines = [$accent->render(Width::truncate($book->title, $width))];
        if ($book->author !== null && $book->author !== '') {
            $lines[] = Width::truncate('by ' . $book->author, $width);
        }
        if ($book->format !== null && $book->format !== '') {
            $lines[] = Width::truncate(strtoupper($book->format), $width);
        }

        $lines[] = '';
        if ($book->downloadUrl !== null && $book->downloadUrl !== '') {
            $lines[] = Width::truncate(self::DOWNLOAD_HINT, $width);
            // WRAP the (long, signed) URL across column lines rather than
            // truncating it — a cut-off URL is useless, and this is the only way
            // to fetch the book. Wrapping in the column (beside the hero) adds no
            // extra body height, so it stays within the frame's content region.
            foreach (explode("\n", Width::wrap($this->resolveUrl($book->downloadUrl), $width)) as $urlLine) {
                $lines[] = $urlLine;
            }
        } else {
            $lines[] = Width::truncate(self::NO_DOWNLOAD, $width);
        }

        return implode("\n", $lines);
    }

    /** A dim placeholder block the exact size of the hero, shown until it loads. */
    private function heroPlaceholder(): string
    {
        $dim = Style::new()->faint();
        $row = $dim->render(str_repeat('░', self::HERO_WIDTH));

        return implode("\n", array_fill(0, self::HERO_HEIGHT, $row));
    }

    private function columnWidth(): int
    {
        return max(20, $this->cols - 4 - self::HERO_WIDTH - self::COL_GAP);
    }

    /** Resolve a (possibly relative) URL against the server base; absolute/empty pass through. */
    private function resolveUrl(string $url): string
    {
        if ($url === '' || preg_match('#^https?://#i', $url) === 1) {
            return $url; // empty, or already absolute (signed URLs are absolute)
        }

        return rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/');
    }

    private function headerTitle(): string
    {
        return $this->book->title ?? $this->title;
    }

    // ---- immutable copies (clone-mutate) -------------------------------

    private function withHero(string $ansi): self
    {
        $next = clone $this;
        $next->heroAnsi = $ansi;

        return $next;
    }

    private function withError(string $error): self
    {
        $next = clone $this;
        $next->error = $error;

        return $next;
    }

    // ---- breadcrumb ----------------------------------------------------

    public function crumbLabel(): string
    {
        return $this->headerTitle();
    }

    public function withCrumbs(array $trail): static
    {
        $next = clone $this;
        $next->crumbs = $trail;

        return $next;
    }

    // ---- accessors (for tests) ----------------------------------------

    public function isLoaded(): bool
    {
        return $this->loaded;
    }

    public function book(): ?Book
    {
        return $this->book;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function hasHero(): bool
    {
        return $this->heroAnsi !== null;
    }
}
