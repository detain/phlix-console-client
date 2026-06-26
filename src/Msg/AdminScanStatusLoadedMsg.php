<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Admin\ScanJob;
use SugarCraft\Core\Msg;

/**
 * A library's latest scan-status resolved. Carries the owning `$libraryId` so a
 * status that arrives AFTER the user moved the selection (to a different library)
 * is dropped — the owner-tagged-async pattern. `$job` is null when the library has
 * no scan job yet.
 */
final readonly class AdminScanStatusLoadedMsg implements Msg
{
    public function __construct(
        public string $libraryId,
        public ?ScanJob $job,
    ) {
    }
}
