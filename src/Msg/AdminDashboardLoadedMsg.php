<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Admin\AdminDashboard;
use SugarCraft\Core\Msg;

/** The admin dashboard data arrived — the AdminDashboardScreen renders its panels. */
final readonly class AdminDashboardLoadedMsg implements Msg
{
    public function __construct(
        public AdminDashboard $dashboard,
    ) {
    }
}
