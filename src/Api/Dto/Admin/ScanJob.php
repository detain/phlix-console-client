<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * A library scan/rescan/match-metadata job row, mirroring the server's
 * `scan-status` payload (and the `scan-history` rows). Immutable.
 *
 * IMPORTANT: the job row carries NO total / denominator column, so a true
 * percentage is NOT derivable. The screen shows an HONEST counter readout
 * (status badge + found/added/updated/removed + the current path + an error
 * when failed), never a fake % bar. {@see isActive()} (queued|running) drives
 * whether the live poll keeps running.
 */
final readonly class ScanJob
{
    public function __construct(
        public string $id,
        public string $libraryId,
        public string $type,
        public string $status,
        public int $itemsFound,
        public int $itemsAdded,
        public int $itemsUpdated,
        public int $itemsRemoved,
        public ?string $currentPath,
        public ?string $error,
        public ?string $queuedAt,
        public ?string $startedAt,
        public ?string $completedAt,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Coerce::str($data['id'] ?? ''),
            libraryId: Coerce::str($data['library_id'] ?? ''),
            type: Coerce::str($data['type'] ?? ''),
            status: Coerce::str($data['status'] ?? ''),
            itemsFound: Coerce::int($data['items_found'] ?? 0),
            itemsAdded: Coerce::int($data['items_added'] ?? 0),
            itemsUpdated: Coerce::int($data['items_updated'] ?? 0),
            itemsRemoved: Coerce::int($data['items_removed'] ?? 0),
            currentPath: Coerce::nstr($data['current_path'] ?? null),
            error: Coerce::nstr($data['error'] ?? null),
            queuedAt: Coerce::nstr($data['queued_at'] ?? null),
            startedAt: Coerce::nstr($data['started_at'] ?? null),
            completedAt: Coerce::nstr($data['completed_at'] ?? null),
        );
    }

    /**
     * True while the job is still progressing (queued or running) — this drives
     * whether the screen keeps the live scan-status poll armed.
     */
    public function isActive(): bool
    {
        return $this->status === 'queued' || $this->status === 'running';
    }

    /**
     * A terse one-line readout, e.g. `"running · found 12, +3 ~1 -0"`. The
     * status is rendered verbatim (empty → "unknown"); the counters are always
     * shown so progress is visible even without a denominator.
     */
    public function summary(): string
    {
        $status = $this->status === '' ? 'unknown' : $this->status;

        return sprintf(
            '%s · found %d, +%d ~%d -%d',
            $status,
            $this->itemsFound,
            $this->itemsAdded,
            $this->itemsUpdated,
            $this->itemsRemoved,
        );
    }
}
