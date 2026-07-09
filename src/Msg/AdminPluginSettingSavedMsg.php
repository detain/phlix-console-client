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
 * A plugin setting was saved. Carries the refreshed {@see PluginDetail} returned
 * by the PUT; the AdminPluginDetailScreen replaces its detail and toasts success.
 */
final readonly class AdminPluginSettingSavedMsg implements Msg
{
    public function __construct(
        public PluginDetail $detail,
    ) {
    }
}
