<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A transport action (pause / resume / stop) succeeded — the
 * {@see \Phlix\Console\Screen\CastScreen} adopts the new `$state` reported by the
 * backend (an empty string when the backend returned none).
 */
final readonly class CastActionDoneMsg implements Msg
{
    public function __construct(
        public string $state,
    ) {
    }
}
