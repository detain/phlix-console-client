<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Msg\DetailFailedMsg;
use Phlix\Console\Msg\DetailLoadedMsg;
use Phlix\Console\Msg\DetailPosterLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Store\MediaStore;
use Phlix\Console\Ui\Chrome;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\Util\Width;
use SugarCraft\Shine\Renderer;
use SugarCraft\Sprinkles\Layout;
use SugarCraft\Sprinkles\Style;

/**
 * A single item's detail: a hero poster beside its metadata (title, year /
 * rating / runtime, genres, director, cast) and a {@see Renderer candy-shine}
 * rendered synopsis, plus a Play entry-point and Back. The full item (with the
 * signed `stream_url`) is fetched via {@see MediaStore::item()}; the poster is
 * rendered asynchronously so the screen appears instantly with a placeholder.
 *
 * Play is wired but inert in Phase 3 — pressing `p` shows that direct-play
 * arrives with the sugar-reel player in Phase 4; the action is the seam.
 *
 * Stable collaborators (id/name + stores) are readonly; the mutable view state
 * is private and copied via clone-mutate (the established screen idiom).
 */
final class DetailScreen implements Model
{
    use SubscriptionCapable;

    private const HERO_WIDTH = 26;
    private const HERO_HEIGHT = 16;
    private const COL_GAP = 3;
    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const PLAY_NOTICE = '▶  Direct-play arrives in Phase 4 (the sugar-reel player).';
    private const HINT = '↑↓  scroll synopsis      p  play      Esc  back';
    private const LOADING_HINT = 'Esc  back';

    private ?MediaItem $item = null;
    private bool $loaded = false;
    private ?string $heroAnsi = null;
    private ?string $error = null;
    private bool $playNotice = false;
    private int $synopsisScroll = 0;

    public function __construct(
        private readonly string $id,
        private readonly string $name,
        private readonly MediaStore $media,
        private readonly PosterLoader $posters,
        private int $cols = 80,
        private int $rows = 24,
    ) {
    }

    public function init(): ?\Closure
    {
        return $this->fetchItem();
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            $next = clone $this;
            $next->cols = $msg->cols;
            $next->rows = $msg->rows;

            return [$next, null];
        }
        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }
        if ($msg instanceof DetailLoadedMsg) {
            return $this->onLoaded($msg->item);
        }
        if ($msg instanceof DetailPosterLoadedMsg) {
            return [$this->withHero($msg->ansi), null];
        }
        if ($msg instanceof DetailFailedMsg) {
            return [$this->withError($msg->reason), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        if ($this->error !== null) {
            return Chrome::frame($this->headerTitle(), "\n  {$this->error}", self::LOADING_HINT, $this->cols, $this->rows);
        }
        if (!$this->loaded || $this->item === null) {
            return Chrome::frame($this->headerTitle(), "\n  Loading…", self::LOADING_HINT, $this->cols, $this->rows);
        }

        $hero = $this->heroAnsi ?? $this->heroPlaceholder();
        $column = $this->metadataColumn($this->item);
        $body = Layout::joinHorizontalWithSpacing(0.0, self::COL_GAP, $hero, $column);

        return Chrome::frame($this->headerTitle(), $body, self::HINT, $this->cols, $this->rows);
    }

    // ---- input ---------------------------------------------------------

    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Char && ($msg->rune === 'p' || $msg->rune === 'P')) {
            $next = clone $this;
            $next->playNotice = true;

            return [$next, null];
        }
        if ($msg->type === KeyType::Up) {
            return [$this->scrollSynopsis(-1), null];
        }
        if ($msg->type === KeyType::Down) {
            return [$this->scrollSynopsis(1), null];
        }

        return [$this, null];
    }

    private function scrollSynopsis(int $delta): self
    {
        $scroll = max(0, $this->synopsisScroll + $delta);
        if ($scroll === $this->synopsisScroll) {
            return $this;
        }
        $next = clone $this;
        $next->synopsisScroll = $scroll;

        return $next;
    }

    // ---- data ----------------------------------------------------------

    private function fetchItem(): \Closure
    {
        return Cmd::promise(fn () => $this->media->item($this->id)->then(
            static fn (MediaItem $item): Msg => new DetailLoadedMsg($item),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new DetailFailedMsg('Could not load this title.'),
        ));
    }

    private function onLoaded(MediaItem $item): array
    {
        $next = clone $this;
        $next->item = $item;
        $next->loaded = true;

        $cmd = $item->posterUrl !== null ? $next->fetchHero($item->posterUrl) : null;

        return [$next, $cmd];
    }

    private function fetchHero(string $url): \Closure
    {
        return Cmd::promise(fn () => $this->posters->load($url, self::HERO_WIDTH, self::HERO_HEIGHT)->then(
            static fn (string $ansi): Msg => new DetailPosterLoadedMsg($ansi),
            static fn (\Throwable $e): ?Msg => null, // a broken poster keeps the placeholder
        ));
    }

    // ---- rendering -----------------------------------------------------

    private function metadataColumn(MediaItem $item): string
    {
        $width = $this->columnWidth();
        $accent = Style::new()->bold();

        $lines = [$accent->render(Width::truncate($item->name, $width))];
        $lines[] = $this->metaLine($item);

        if ($item->genres !== []) {
            $lines[] = Width::truncate(implode(', ', $item->genres), $width);
        }
        if ($item->director !== null && $item->director !== '') {
            $lines[] = Width::truncate('Directed by ' . $item->director, $width);
        }
        if ($item->actors !== []) {
            $lines[] = Width::truncate('Cast: ' . implode(', ', $item->actors), $width);
        }

        $header = $lines;
        $actions = $this->playNotice ? self::PLAY_NOTICE : '▶  p  Play        Esc  Back';

        // The synopsis fills whatever room remains, scrollable with ↑/↓.
        $reserved = count($header) + 3; // a blank above + a blank + the actions line below
        $synopsisRows = max(1, $this->bodyHeight() - $reserved);
        $synopsis = $this->synopsisWindow($item, $width, $synopsisRows);

        return implode("\n", [...$header, '', ...$synopsis, '', $actions]);
    }

    private function metaLine(MediaItem $item): string
    {
        $parts = [];
        if ($item->type === 'episode' && $item->seasonNumber !== null && $item->episodeNumber !== null) {
            $parts[] = sprintf('S%02dE%02d', $item->seasonNumber, $item->episodeNumber);
            if ($item->episodeTitle !== null && $item->episodeTitle !== '') {
                $parts[] = $item->episodeTitle;
            }
        } else {
            $parts[] = ucfirst($item->type);
        }
        if ($item->year !== null) {
            $parts[] = (string) $item->year;
        }
        if ($item->rating !== null && $item->rating !== '') {
            $parts[] = $item->rating;
        }
        $length = $this->lengthLabel($item);
        if ($length !== null) {
            $parts[] = $length;
        }

        return Width::truncate(implode('  ·  ', $parts), $this->columnWidth());
    }

    /** A human runtime — TMDB minutes if present, else the probed duration seconds. */
    private function lengthLabel(MediaItem $item): ?string
    {
        $minutes = $item->runtime ?? ($item->duration !== null ? intdiv($item->duration, 60) : null);
        if ($minutes === null || $minutes <= 0) {
            return null;
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $h > 0 ? ($m > 0 ? "{$h}h {$m}m" : "{$h}h") : "{$m}m";
    }

    /**
     * Render the overview as markdown→ANSI (candy-shine), word-wrapped to the
     * column, and return the scrolled window of $rows lines.
     *
     * @return list<string>
     */
    private function synopsisWindow(MediaItem $item, int $width, int $rows): array
    {
        $overview = $item->overview;
        if ($overview === null || trim($overview) === '') {
            return ['No synopsis available.'];
        }

        $rendered = Renderer::ansi()->withWordWrap($width)->render($overview);
        $all = explode("\n", rtrim($rendered, "\n"));

        $max = max(0, count($all) - $rows);
        $offset = min($this->synopsisScroll, $max);

        return array_slice($all, $offset, $rows);
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

    private function bodyHeight(): int
    {
        return max(self::HERO_HEIGHT, $this->rows - 4);
    }

    private function headerTitle(): string
    {
        return $this->item?->name ?? $this->name;
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

    // ---- accessors (for tests) ----------------------------------------

    public function isLoaded(): bool
    {
        return $this->loaded;
    }

    public function item(): ?MediaItem
    {
        return $this->item;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function hasHero(): bool
    {
        return $this->heroAnsi !== null;
    }

    public function showsPlayNotice(): bool
    {
        return $this->playNotice;
    }
}
