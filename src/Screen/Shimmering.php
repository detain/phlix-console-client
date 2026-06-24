<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use SugarCraft\Core\Model;

/**
 * A screen whose loading body shows an animated shimmer skeleton. Mirrors
 * {@see Themed}/{@see Breadcrumbed}: only the {@see \Phlix\Console\App} owns the
 * animation phase, so it hands the current phase to the top screen via
 * {@see withShimmerPhase()} (clone-mutate, the original unchanged) just before
 * that screen renders — the screen then feeds the phase to
 * {@see \Phlix\Console\Ui\Skeleton}.
 *
 * Screens get the implementation for free by `use`-ing {@see ShimmeringScreen}.
 */
interface Shimmering extends Model
{
    /**
     * A copy that will render its loading skeleton at $phase (clone-mutate; the
     * original is unchanged).
     */
    public function withShimmerPhase(int $phase): static;
}
