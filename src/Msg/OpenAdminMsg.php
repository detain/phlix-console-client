<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Open the admin area — the App pushes the {@see \Phlix\Console\Screen\AdminMenuScreen}
 * (the section index) onto the stack. Emitted by the palette's "Admin" action,
 * which is offered only to an admin user.
 */
final readonly class OpenAdminMsg implements Msg
{
}
