<?php

declare(strict_types=1);

namespace Phlix\Console\Ui;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Sprinkles\Style;

/**
 * The library filter/sort controls, shown as one row in "filter mode": a search
 * box, a sort field, and a sort order. Immutable; the owning screen reads the
 * resulting {@see search}/{@see sort}/{@see order} into a MediaQuery. Tab cycles
 * the focused control (the screen calls {@see next()}/{@see prev()}); per-control
 * editing goes through {@see handleKey()}.
 *
 * `sort`/`order` are null until touched (the server defaults to name-ascending);
 * once edited they hold an explicit value.
 */
final readonly class FilterBar
{
    /** @var list<string> */
    public const SORTS = ['name', 'year', 'rating', 'date_added', 'runtime'];

    private const SEARCH = 0;
    private const SORT = 1;
    private const ORDER = 2;
    private const CONTROLS = 3;

    public function __construct(
        public string $search = '',
        public ?string $sort = null,
        public ?string $order = null,
        public int $active = self::SEARCH,
    ) {
    }

    public static function new(): self
    {
        return new self();
    }

    public function focusSearch(): self
    {
        return new self($this->search, $this->sort, $this->order, self::SEARCH);
    }

    public function next(): self
    {
        return new self($this->search, $this->sort, $this->order, ($this->active + 1) % self::CONTROLS);
    }

    public function prev(): self
    {
        return new self($this->search, $this->sort, $this->order, ($this->active - 1 + self::CONTROLS) % self::CONTROLS);
    }

    /** Whether any filter/sort is set (vs the default name-ascending unfiltered view). */
    public function isActive(): bool
    {
        return $this->search !== '' || $this->sort !== null || $this->order !== null;
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

    private function editSearch(KeyMsg $msg): self
    {
        if ($msg->type === KeyType::Backspace) {
            return $this->search === ''
                ? $this
                : new self(mb_substr($this->search, 0, -1), $this->sort, $this->order, $this->active);
        }
        if ($msg->type === KeyType::Space) {
            return new self($this->search . ' ', $this->sort, $this->order, $this->active);
        }
        if ($msg->type === KeyType::Char && $msg->rune !== '') {
            return new self($this->search . $msg->rune, $this->sort, $this->order, $this->active);
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

        return new self($this->search, self::SORTS[$index], $this->order, $this->active);
    }

    private function editOrder(KeyMsg $msg): self
    {
        if (!in_array($msg->type, [KeyType::Left, KeyType::Right, KeyType::Space, KeyType::Enter], true)) {
            return $this;
        }

        $next = ($this->order ?? 'asc') === 'asc' ? 'desc' : 'asc';

        return new self($this->search, $this->sort, $next, $this->active);
    }

    public function render(): string
    {
        $search = $this->search === '' ? '(type to filter)' : $this->search;

        return $this->segment(self::SEARCH, 'Search: ' . $search)
            . '    ' . $this->segment(self::SORT, 'Sort: ' . ($this->sort ?? 'name'))
            . '    ' . $this->segment(self::ORDER, 'Order: ' . ($this->order ?? 'asc'));
    }

    private function segment(int $control, string $label): string
    {
        return $this->active === $control ? Style::new()->reverse()->bold()->render($label) : $label;
    }
}
