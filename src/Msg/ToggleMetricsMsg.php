<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Toggle the diagnostic metrics / HUD overlay on or off. Dispatched from the
 * command palette (so it needs no global key, avoiding any conflict). The App
 * flips its {@see \Phlix\Console\App} `metricsVisible` flag in response.
 */
final readonly class ToggleMetricsMsg implements Msg
{
}
