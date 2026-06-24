<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * The shimmer animation tick. The {@see \Phlix\Console\App} arms one of these
 * (via {@see \SugarCraft\Core\Cmd::tick}) only while a {@see \Phlix\Console\Screen\Loadable}
 * top screen is loading; on receipt it advances the shimmer phase and re-arms
 * WHILE the screen is still loading, then stops (no re-arm) once it isn't —
 * a gated, single-chain heartbeat mirroring the toast prune tick.
 */
final readonly class ShimmerTickMsg implements Msg
{
}
