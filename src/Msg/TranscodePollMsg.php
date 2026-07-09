<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * The tick that drives transcode-readiness polling — the
 * {@see \Phlix\Console\Screen\PlayerScreen} requests the job status on each one.
 */
final readonly class TranscodePollMsg implements Msg
{
}
