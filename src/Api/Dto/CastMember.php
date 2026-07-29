<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto;

/**
 * A cast member with name, role, and optional profile image.
 */
final readonly class CastMember
{
    public function __construct(
        public string $name,
        public ?string $role,
        public ?string $profileUrl,
    ) {
    }
}
