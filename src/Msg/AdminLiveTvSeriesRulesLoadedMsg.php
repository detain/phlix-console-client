<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Admin\SeriesRule;
use SugarCraft\Core\Msg;

/**
 * The Live-TV series-recording rules arrived — the AdminLiveTvScreen caches them
 * into the Series Rules section and renders the windowed table.
 */
final readonly class AdminLiveTvSeriesRulesLoadedMsg implements Msg
{
    /** @param list<SeriesRule> $rules */
    public function __construct(
        public array $rules,
    ) {
    }
}
