<?php

declare(strict_types=1);

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
