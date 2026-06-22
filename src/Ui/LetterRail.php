<?php

declare(strict_types=1);

namespace Phlix\Console\Ui;

use Phlix\Console\Api\Dto\LetterIndex;
use SugarCraft\Sprinkles\Style;

/**
 * A one-row A–Z jump bar bound to a {@see LetterIndex}: `#` then `A`–`Z`, with
 * letters that have items shown bright, empty ones dimmed, and the current
 * letter (the bucket the grid cursor sits in) highlighted. Pressing a letter
 * jumps the grid to that bucket's offset — the screen owns that wiring; this
 * widget is just the indicator.
 */
final readonly class LetterRail
{
    public function __construct(
        public LetterIndex $index,
        public ?string $current = null,
    ) {
    }

    public function withCurrent(?string $letter): self
    {
        return new self($this->index, $letter);
    }

    public function render(): string
    {
        $cells = [];
        foreach ($this->index->letters as $bucket) {
            if ($bucket->letter === $this->current) {
                $cells[] = Style::new()->reverse()->bold()->render($bucket->letter);
            } elseif ($bucket->count > 0) {
                $cells[] = $bucket->letter;
            } else {
                $cells[] = Style::new()->faint()->render($bucket->letter);
            }
        }

        return implode(' ', $cells);
    }
}
