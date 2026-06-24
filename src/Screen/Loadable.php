<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use SugarCraft\Core\Model;

/**
 * A screen that has a "still fetching its first data" loading state. The
 * {@see \Phlix\Console\App} watches the top screen: while it {@see isLoading()},
 * the App runs a gated shimmer tick so the screen's skeleton placeholder animates
 * (and stops the tick the moment the screen stops loading — no free-running
 * heartbeat). A screen reports {@see isLoading()} true exactly while it renders
 * that loading body.
 */
interface Loadable extends Model
{
    /** True while the screen is still loading its first batch of data. */
    public function isLoading(): bool;
}
