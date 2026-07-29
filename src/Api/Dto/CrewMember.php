<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto;

/**
 * A crew member with name, job title, and optional profile image.
 */
final readonly class CrewMember
{
    public function __construct(
        public string $name,
        public ?string $job,
        public ?string $profileUrl,
    ) {
    }
}
