<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Admin\GuideProgram;
use SugarCraft\Core\Msg;

/**
 * The Live-TV guide programs arrived — the AdminLiveTvScreen caches them into the
 * Guide section and renders the windowed table.
 */
final readonly class AdminLiveTvGuideLoadedMsg implements Msg
{
    /** @param list<GuideProgram> $programs */
    public function __construct(
        public array $programs,
    ) {
    }
}
