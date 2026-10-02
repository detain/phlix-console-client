<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Dto\Admin\Parental\ProfileTag;
use SugarCraft\Core\Msg;

/**
 * Carries the fetched profile tags into {@see ParentalControlsScreen}.
 */
final readonly class ParentalTagsLoadedMsg implements Msg
{
    /** @param list<ProfileTag> $tags */
    public function __construct(public array $tags)
    {
    }
}
