<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

/**
 * Implements {@see Shimmering} once for every grid screen: a screen `use`s this
 * trait, adds {@see Shimmering} to its `implements` list, and passes
 * {@see shimmerPhase()} to {@see \Phlix\Console\Ui\Skeleton::bars()} in its
 * loading body. The phase is held in a private mutable field copied via
 * clone-mutate (the established screen idiom) and defaults to 0 so an un-driven
 * screen renders a static (phase-0) skeleton — no constructor change.
 */
trait ShimmeringScreen
{
    private int $shimmerPhase = 0;

    public function withShimmerPhase(int $phase): static
    {
        $next = clone $this;
        $next->shimmerPhase = $phase;

        return $next;
    }

    /** The current shimmer animation phase (0 until the App drives it). */
    private function shimmerPhase(): int
    {
        return $this->shimmerPhase;
    }
}
