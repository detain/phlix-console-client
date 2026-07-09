<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * One discovered port-forward candidate, mirroring an item of
 * `GET /api/v1/admin/remote/portforward/candidates` →
 * `{candidates: [{hostname, externalIp, port}]}`.
 *
 * `hostname` is a full reachable URL the server discovered for itself (e.g.
 * `"http://192.168.1.100:32400"`), `externalIp` the detected external address,
 * and `port` the forwarded port. Tolerant; immutable.
 */
final readonly class PortForwardCandidate
{
    public function __construct(
        public string $hostname,
        public string $externalIp,
        public int $port,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            hostname: Coerce::str($data['hostname'] ?? ''),
            externalIp: Coerce::str($data['externalIp'] ?? ''),
            port: Coerce::int($data['port'] ?? 0),
        );
    }
}
