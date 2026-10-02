<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Dto\Admin\Parental\AccessSchedule;
use SugarCraft\Core\Msg;

/**
 * Carries the fetched access schedules into {@see ParentalControlsScreen}.
 */
final readonly class ParentalSchedulesLoadedMsg implements Msg
{
    /** @param list<AccessSchedule> $schedules */
    public function __construct(public array $schedules)
    {
    }
}
