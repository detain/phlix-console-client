<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Admin\Recording;
use SugarCraft\Core\Msg;

/**
 * The Live-TV recordings arrived — the AdminLiveTvScreen caches them into the
 * Recordings section and renders the windowed table.
 */
final readonly class AdminLiveTvRecordingsLoadedMsg implements Msg
{
    /** @param list<Recording> $recordings */
    public function __construct(
        public array $recordings,
    ) {
    }
}
