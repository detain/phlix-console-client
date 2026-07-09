<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Route;
use SugarCraft\Core\Msg;

/**
 * Open one admin section from the admin menu — the App pushes the section's
 * screen onto the stack. Carries the destination {@see Route} (e.g.
 * {@see Route::AdminDashboard}); only available sections emit this.
 */
final readonly class OpenAdminSectionMsg implements Msg
{
    public function __construct(
        public Route $section,
    ) {
    }
}
