<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Dto\Admin\Parental\ProfileStreamLimit;
use SugarCraft\Core\Msg;

/**
 * Carries the fetched stream limits into {@see ParentalControlsScreen}.
 */
final readonly class ParentalStreamLimitsLoadedMsg implements Msg
{
    public function __construct(public ProfileStreamLimit $limit)
    {
    }
}
