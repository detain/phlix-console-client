<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\AudiobookProgress;
use SugarCraft\Core\Msg;

/**
 * The listener's saved progress through an audiobook resolved (the
 * {@see \Phlix\Console\Screen\AudiobookDetailScreen} offers a resume from
 * `positionMs` and pre-selects the saved chapter). A non-auth progress error
 * is swallowed (no resume offered), so this is dispatched only on success.
 */
final readonly class AudiobookProgressLoadedMsg implements Msg
{
    public function __construct(
        public AudiobookProgress $progress,
    ) {
    }
}
