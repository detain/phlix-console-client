<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Admin\PluginDetail;
use SugarCraft\Core\Msg;

/**
 * The plugin detail loaded successfully. Carries the full {@see PluginDetail};
 * the AdminPluginDetailScreen swaps it in.
 */
final readonly class AdminPluginDetailLoadedMsg implements Msg
{
    public function __construct(
        public PluginDetail $detail,
    ) {
    }
}
