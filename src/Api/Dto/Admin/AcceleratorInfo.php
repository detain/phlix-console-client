<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Api\Dto\Admin;

/**
 * One detected hardware accelerator with its available encoders.
 */
final readonly class AcceleratorInfo
{
    /**
     * @param list<string> $encoders
     */
    public function __construct(
        public string $name,
        public array $encoders,
        public bool $isHardware,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $encoders = [];
        foreach (is_array($data['encoders'] ?? null) ? $data['encoders'] : [] as $encoder) {
            if (is_string($encoder)) {
                $encoders[] = $encoder;
            }
        }

        $name = $data['name'] ?? 'Unknown';

        return new self(
            is_string($name) ? $name : 'Unknown',
            $encoders,
            (bool) ($data['isHardware'] ?? false),
        );
    }
}
