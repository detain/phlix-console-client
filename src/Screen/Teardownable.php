<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

/**
 * A screen that holds external resources (e.g. the player's ffmpeg/ffplay
 * subprocesses) needing explicit cleanup when it leaves the stack OR when the
 * whole app quits. The {@see \Phlix\Console\App} calls {@see teardown()} on the
 * top screen before a global quit so nothing is leaked; a screen that pops
 * itself (Esc/back) tears itself down on the way out.
 */
interface Teardownable
{
    /** Release external resources. Must be idempotent (may be called twice). */
    public function teardown(): void;
}
