<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use SugarCraft\Forms\Form;

/**
 * One open edit/rename session on the {@see AdminLiveTvScreen}: the embedded
 * candy-forms {@see Form} plus the target it edits (a `kind` — tuner / channel /
 * rule — and the row `id`). Held as a single nullable field so the form and its
 * target are always set or cleared together. Immutable except for swapping the
 * form in as the user types (via {@see withForm()}).
 */
final readonly class LiveTvEditSession
{
    public function __construct(
        public string $kind,
        public string $targetId,
        public Form $form,
    ) {
    }

    /** A copy with the form swapped (as the embedded form advances). */
    public function withForm(Form $form): self
    {
        return new self($this->kind, $this->targetId, $form);
    }
}
