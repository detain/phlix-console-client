<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * One Live-TV tuner, mirroring a row of `GET /api/v1/admin/livetv/tuners` →
 * `{success, tuners: [...]}` (the data rides a top-level named key, NOT
 * `{success, data}`). The server returns the raw `livetv_tuners` row, so the
 * keys are the DB columns: `id, tuner_id, type, name, host, port, device_id,
 * enabled (TINYINT 0/1), last_seen, status, capabilities`.
 *
 * Tolerant: `enabled` accepts 0|1, "0"|"1", true|false; optional host/port/
 * last-seen become null when absent. Immutable.
 */
final readonly class Tuner
{
    public function __construct(
        public string $id,
        public string $tunerId,
        public string $type,
        public string $name,
        public ?string $host,
        public ?int $port,
        public bool $enabled,
        public string $status,
        public ?string $lastSeen,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Coerce::str($data['id'] ?? ''),
            tunerId: Coerce::str($data['tuner_id'] ?? ''),
            type: Coerce::str($data['type'] ?? ''),
            name: Coerce::str($data['name'] ?? ''),
            host: Coerce::nstr($data['host'] ?? null),
            port: Coerce::nint($data['port'] ?? null),
            enabled: Coerce::bool($data['enabled'] ?? false),
            status: Coerce::str($data['status'] ?? ''),
            lastSeen: Coerce::nstr($data['last_seen'] ?? null),
        );
    }
}
