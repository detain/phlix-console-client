<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Cast\CastStatus;
use SugarCraft\Core\Msg;

/**
 * A status poll resolved — the {@see \Phlix\Console\Screen\CastScreen} updates the
 * last-known state line (when the carried `$epoch` still matches its current poll
 * generation).
 */
final readonly class CastStatusLoadedMsg implements Msg
{
    public function __construct(
        public int $epoch,
        public CastStatus $status,
    ) {
    }
}
