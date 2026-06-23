<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Audiobook;
use Phlix\Console\Msg\AudiobooksFailedMsg;
use Phlix\Console\Msg\AudiobooksLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenAudiobookMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Store\AudiobooksStore;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\Table;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;

/**
 * An audiobook library's list, rendered as a borderless sugar-table via
 * {@see Table} (Title · Author · Narrator · Duration) with reverse-video row
 * selection. Audiobooks have no usable cover art server-side (the `cover_url`
 * is a raw filesystem path), so the screen is text-forward, exactly like
 * {@see MusicScreen}. ↑/↓ move the selection, Enter opens the audiobook's
 * detail (an {@see OpenAudiobookMsg} the App turns into an
 * {@see AudiobookDetailScreen}), Esc/q go back.
 *
 * The list is fetched once via {@see AudiobooksStore::all()} (the store pages
 * through the server's 100-capped endpoint, accumulating every audiobook).
 * Stable collaborators are readonly; the mutable view state is private and
 * copied via clone-mutate (the established screen idiom).
 */
final class AudiobooksScreen implements Breadcrumbed
{
    use SubscriptionCapable;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const HINT = '↑↓  select      ⏎  open      Esc  back';
    // Fixed columns; the flex Title column fills whatever is left.
    private const AUTHOR_WIDTH = 24;
    private const NARRATOR_WIDTH = 20;
    private const DURATION_WIDTH = 10;

    /** @var list<Audiobook> */
    private array $audiobooks = [];
    private int $selected = 0;
    private bool $loaded = false;
    private ?string $error = null;
    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private readonly AudiobooksStore $store,
        private readonly ?string $libraryId,
        private readonly string $name,
        private int $cols = 80,
        private int $rows = 24,
    ) {
    }

    public function init(): ?\Closure
    {
        return Cmd::promise(fn () => $this->store->all($this->libraryId)->then(
            static fn (array $audiobooks): Msg => new AudiobooksLoadedMsg($audiobooks),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AudiobooksFailedMsg('Could not load this library.'),
        ));
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [$this->resizedTo($msg->cols, $msg->rows), null];
        }
        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }
        if ($msg instanceof AudiobooksLoadedMsg) {
            return [$this->withAudiobooks($msg->audiobooks), null];
        }
        if ($msg instanceof AudiobooksFailedMsg) {
            return [$this->withError($msg->reason), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame($this->title(), $this->body(), self::HINT, $this->cols, $this->rows, $this->crumbs);
    }

    // ---- input ---------------------------------------------------------

    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Enter) {
            if ($this->audiobooks === []) {
                return [$this, null];
            }
            $audiobook = $this->audiobooks[$this->selected];

            return [$this, Cmd::send(new OpenAudiobookMsg($audiobook->id, $audiobook->title))];
        }
        if ($msg->type === KeyType::Up) {
            return [$this->moveSelection(-1), null];
        }
        if ($msg->type === KeyType::Down) {
            return [$this->moveSelection(1), null];
        }

        return [$this, null];
    }

    private function moveSelection(int $delta): self
    {
        $count = count($this->audiobooks);
        if ($count === 0) {
            return $this;
        }
        $selected = max(0, min($count - 1, $this->selected + $delta));
        if ($selected === $this->selected) {
            return $this;
        }
        $next = clone $this;
        $next->selected = $selected;

        return $next;
    }

    // ---- rendering -----------------------------------------------------

    private function body(): string
    {
        if (!$this->loaded) {
            return "\n  Loading audiobooks…";
        }
        if ($this->error !== null) {
            return "\n  {$this->error}";
        }
        if ($this->audiobooks === []) {
            return "\n  No audiobooks in this library.";
        }

        $rows = [];
        foreach ($this->audiobooks as $audiobook) {
            $duration = $audiobook->durationLabel();
            $rows[] = [
                $audiobook->title,
                $audiobook->author ?? '—',
                $audiobook->narrator ?? '—',
                $duration === '' ? '—' : $duration,
            ];
        }

        return Table::render([
            ['title' => 'Title', 'width' => 0],
            ['title' => 'Author', 'width' => self::AUTHOR_WIDTH],
            ['title' => 'Narrator', 'width' => self::NARRATOR_WIDTH],
            ['title' => 'Duration', 'width' => self::DURATION_WIDTH, 'align' => 'right'],
        ], $rows, $this->selected, $this->cols - 4, $this->viewportRows());
    }

    private function viewportRows(): int
    {
        // The content panel fills the frame; window the table to that body height
        // less the table's own header + separator (2), so the selected row is
        // never clipped.
        return max(1, Chrome::bodyHeight($this->rows) - 2);
    }

    /** The library name when known, else a generic "Audiobooks" fallback. */
    private function title(): string
    {
        return $this->name !== '' ? $this->name : 'Audiobooks';
    }

    // ---- immutable copies (clone-mutate) -------------------------------

    /** @param list<Audiobook> $audiobooks */
    private function withAudiobooks(array $audiobooks): self
    {
        $next = clone $this;
        $next->audiobooks = $audiobooks;
        $next->loaded = true;
        $next->error = null;
        $next->selected = $audiobooks === [] ? 0 : min($this->selected, count($audiobooks) - 1);

        return $next;
    }

    private function withError(string $error): self
    {
        $next = clone $this;
        $next->error = $error;
        $next->loaded = true;

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
        return $this->title();
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

    public function error(): ?string
    {
        return $this->error;
    }

    public function selectedAudiobook(): ?Audiobook
    {
        return $this->audiobooks[$this->selected] ?? null;
    }

    public function selectedIndex(): int
    {
        return $this->selected;
    }

    /** @return list<Audiobook> */
    public function audiobooks(): array
    {
        return $this->audiobooks;
    }
}
