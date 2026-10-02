<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Screen;

use SugarCraft\Core\Msg;

/**
 * Signals that a parental-controls mutation completed, with a toast-ready
 * message, for {@see ParentalControlsScreen}.
 */
final readonly class ParentalActionDoneMsg implements Msg
{
    public function __construct(public string $message)
    {
    }
}
