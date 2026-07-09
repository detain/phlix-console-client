<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * The managed-subdomain status, mirroring
 * `GET /api/v1/admin/remote/subdomain/status` → TOP-LEVEL `{claimed:false}` when
 * unclaimed, else `{claimed:true, subdomain, fqdn, certPath, keyPath}` (the
 * {@see \Phlix\Server\Http\Controllers\Admin\AdminHubController} is unenveloped).
 *
 * A `{data:{...}}` wrapper yields the unclaimed default. Immutable.
 */
final readonly class SubdomainStatus
{
    public function __construct(
        public bool $claimed,
        public ?string $subdomain,
        public ?string $fqdn,
        public ?string $certPath,
        public ?string $keyPath,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            claimed: Coerce::bool($data['claimed'] ?? false),
            subdomain: Coerce::nstr($data['subdomain'] ?? null),
            fqdn: Coerce::nstr($data['fqdn'] ?? null),
            certPath: Coerce::nstr($data['certPath'] ?? null),
            keyPath: Coerce::nstr($data['keyPath'] ?? null),
        );
    }

    /** A short human label for the current state. */
    public function stateLabel(): string
    {
        return $this->claimed ? 'Claimed' : 'Not claimed';
    }

    /** A one-line summary for the panel (the FQDN when claimed). */
    public function summary(): string
    {
        if (!$this->claimed) {
            return 'No subdomain claimed.';
        }

        return 'Claimed ' . ($this->fqdn ?? $this->subdomain ?? 'a subdomain') . '.';
    }
}
