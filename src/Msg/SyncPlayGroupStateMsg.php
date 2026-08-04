<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Group state was updated via TYPE_GROUP_STATE sync message.
 */
final readonly class SyncPlayGroupStateMsg implements Msg
{
}
