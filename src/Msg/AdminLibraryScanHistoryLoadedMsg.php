<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Admin\ScanJob;
use SugarCraft\Core\Msg;

/**
 * A library's recent scan-job history resolved. Carries the owning `$libraryId`
 * so a history that arrives AFTER the user moved the selection (to a different
 * library) is dropped — the owner-tagged-async pattern, mirroring
 * {@see AdminScanStatusLoadedMsg}. `$history` is newest-first and may be empty.
 *
 * @see \Phlix\Console\Screen\AdminLibrariesScreen
 */
final readonly class AdminLibraryScanHistoryLoadedMsg implements Msg
{
    /** @param list<ScanJob> $history */
    public function __construct(
        public string $libraryId,
        public array $history,
    ) {
    }
}
