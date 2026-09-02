<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto;

/**
 * A public SyncPlay group returned from the groups list endpoint.
 *
 * S414 authority ruling (server `01340633`, live paths only): list rows
 * (`SyncPlaySnapshotService::listGroups()`) emit exactly
 * `{id, name, member_count, has_password, current_media, is_playing}`.
 * `is_public` is NOT a wire key anywhere in the server; the public/private
 * signal is `has_password`. The pre-S414 revision read `is_public ?? isPublic
 * ?? true` — three keys the server never sends — so a password-protected
 * group ALWAYS resolved as public. `isPublic` here is the derived local view:
 * `! has_password`.
 *
 * @readonly
 */
final readonly class SyncPlayGroup
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $isPublic,
        public int $memberCount,
    ) {
    }

    /**
     * Parse one LIST ROW.
     *
     * Honest decision, tested both directions: `has_password` absent is NOT a
     * wire state this emitter ever produces (the column is NOT NULL), so the
     * fallback only guards a foreign payload. Absent → treated as PUBLIC —
     * the same default the server's own schema gives (has_password defaults
     * 0); a payload that never says "password" has never claimed privacy.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Coerce::str($data['id'] ?? ''),
            name: Coerce::str($data['name'] ?? ''),
            isPublic: !Coerce::bool($data['has_password'] ?? false),
            memberCount: Coerce::int($data['member_count'] ?? 0),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_public' => $this->isPublic,
            'member_count' => $this->memberCount,
        ];
    }
}
