<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Ui;

use Phlix\Console\I18n\Lang;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Sprinkles\Style;

/**
 * The library filter/sort controls, shown as one row in "filter mode": a search
 * box, a sort field, a sort order, and facet chips. Immutable; the owning screen
 * reads the resulting {@see search}/{@see sort}/{@see order}/{@see genres} into
 * a MediaQuery. Tab cycles the focused control (the screen calls {@see next()}/
 * {@see prev()}); per-control editing goes through {@see handleKey()}.
 *
 * `sort`/`order` are null until touched (the server defaults to name-ascending);
 * once edited they hold an explicit value.
 */
final readonly class FilterBar
{
    /** @var list<string> */
    public const SORTS = ['name', 'year', 'rating', 'date_added', 'runtime', 'genre', 'artist'];

    private const SEARCH = 0;
    private const SORT = 1;
    private const ORDER = 2;
    private const CONTROLS = 3;

    /**
     * @param list<string> $genres          Selected genre filters
     * @param list<string> $availableGenres Available genre facets from the server
     */
    public function __construct(
        public string $search = '',
        public ?string $sort = null,
        public ?string $order = null,
        public int $active = self::SEARCH,
        public array $genres = [],
        public array $availableGenres = [],
    ) {
    }

    public static function new(): self
    {
        return new self();
    }

    /**
     * Create a bar with available genre facets from the server.
     *
     * @param list<string> $availableGenres
     */
    public function withGenres(array $availableGenres): self
    {
        return new self(
            $this->search,
            $this->sort,
            $this->order,
            $this->active,
            $this->genres,
            $availableGenres,
        );
    }

    public function focusSearch(): self
    {
        return new self($this->search, $this->sort, $this->order, self::SEARCH, $this->genres, $this->availableGenres);
    }

    public function next(): self
    {
        return new self(
            $this->search,
            $this->sort,
            $this->order,
            ($this->active + 1) % self::CONTROLS,
            $this->genres,
            $this->availableGenres,
        );
    }

    public function prev(): self
    {
        return new self(
            $this->search,
            $this->sort,
            $this->order,
            ($this->active - 1 + self::CONTROLS) % self::CONTROLS,
            $this->genres,
            $this->availableGenres,
        );
    }

    /** Whether any filter/sort/facet is set (vs the default name-ascending unfiltered view). */
    public function isActive(): bool
    {
        return $this->search !== '' || $this->sort !== null || $this->order !== null || $this->genres !== [];
    }

    /** Apply a key to the focused control, returning the updated bar (or self). */
    public function handleKey(KeyMsg $msg): self
    {
        return match ($this->active) {
            self::SEARCH => $this->editSearch($msg),
            self::SORT => $this->editSort($msg),
            self::ORDER => $this->editOrder($msg),
            default => $this,
        };
    }

    /**
     * Toggle a genre facet by name. If already selected, remove it; otherwise add it.
     */
    public function toggleGenre(string $genre): self
    {
        $genres = in_array($genre, $this->genres, true)
            ? array_values(array_filter($this->genres, static fn (string $g): bool => $g !== $genre))
            : [...$this->genres, $genre];

        return new self(
            $this->search,
            $this->sort,
            $this->order,
            $this->active,
            $genres,
            $this->availableGenres,
        );
    }

    private function editSearch(KeyMsg $msg): self
    {
        if ($msg->type === KeyType::Backspace) {
            return $this->search === ''
                ? $this
                : new self(mb_substr($this->search, 0, -1), $this->sort, $this->order, $this->active, $this->genres, $this->availableGenres);
        }
        if ($msg->type === KeyType::Space) {
            return new self($this->search . ' ', $this->sort, $this->order, $this->active, $this->genres, $this->availableGenres);
        }
        if ($msg->type === KeyType::Char && $msg->rune !== '') {
            return new self($this->search . $msg->rune, $this->sort, $this->order, $this->active, $this->genres, $this->availableGenres);
        }

        return $this;
    }

    private function editSort(KeyMsg $msg): self
    {
        $delta = match ($msg->type) {
            KeyType::Right => 1,
            KeyType::Left => -1,
            default => 0,
        };
        if ($delta === 0) {
            return $this;
        }

        $current = array_search($this->sort ?? 'name', self::SORTS, true);
        $index = (($current === false ? 0 : $current) + $delta + count(self::SORTS)) % count(self::SORTS);

        return new self($this->search, self::SORTS[$index], $this->order, $this->active, $this->genres, $this->availableGenres);
    }

    private function editOrder(KeyMsg $msg): self
    {
        if (!in_array($msg->type, [KeyType::Left, KeyType::Right, KeyType::Space, KeyType::Enter], true)) {
            return $this;
        }

        $next = ($this->order ?? 'asc') === 'asc' ? 'desc' : 'asc';

        return new self($this->search, $this->sort, $next, $this->active, $this->genres, $this->availableGenres);
    }

    public function render(): string
    {
        $search = $this->search === '' ? Lang::t('filter.search_placeholder') : $this->search;

        $base = $this->segment(self::SEARCH, Lang::t('filter.search_label') . $search)
            . '    ' . $this->segment(self::SORT, Lang::t('filter.sort_label') . ($this->sort ?? 'name'))
            . '    ' . $this->segment(self::ORDER, Lang::t('filter.order_label') . ($this->order ?? Lang::t('filter.order_asc')));

        if ($this->availableGenres === []) {
            return $base;
        }

        $chips = [];
        foreach ($this->availableGenres as $genre) {
            $selected = in_array($genre, $this->genres, true);
            $chipText = '[' . $genre . ']';
            $chips[] = $selected ? Style::new()->reverse()->render($chipText) : $chipText;
        }

        return $base . '    ' . implode(' ', $chips);
    }

    private function segment(int $control, string $label): string
    {
        return $this->active === $control ? Style::new()->reverse()->bold()->render($label) : $label;
    }
}
