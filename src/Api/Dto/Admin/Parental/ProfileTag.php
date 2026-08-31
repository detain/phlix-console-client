<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto\Admin\Parental;

use Phlix\Console\Api\Dto\Coerce;

/**
 * A profile tag for content filtering (block or allow).
 * Mirrors @phlix/contracts ProfileTag (v0.4.4).
 *
 * S325b: `profileId` is a CHAR(36) UUID STRING, not an int — the mirror's
 * `int` typing collapsed every real profile id to `0` (Coerce::int on a UUID),
 * and the contract has declared it `string` since v0.4.4 (S234). The wire key
 * stays `profile_id` (server emission); the TS-style property name is the
 * contract's documented mapping.
 */
final readonly class ProfileTag
{
    public const TYPE_BLOCKED = 'blocked';
    public const TYPE_ALLOWED = 'allowed';

    public function __construct(
        public int $id,
        public string $profileId,
        public string $tag,
        public string $tagType,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Coerce::int($data['id'] ?? 0),
            profileId: Coerce::str($data['profile_id'] ?? ''),
            tag: Coerce::str($data['tag'] ?? ''),
            tagType: Coerce::str($data['tag_type'] ?? self::TYPE_BLOCKED),
        );
    }

    /**
     * @return array{id: int, profile_id: string, tag: string, tag_type: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'profile_id' => $this->profileId,
            'tag' => $this->tag,
            'tag_type' => $this->tagType,
        ];
    }
}
