<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto;

/**
 * The authenticated user, mirroring the `user` object returned by
 * `/auth/login`, `/auth/refresh`, and `/auth/me`. Immutable.
 */
final readonly class AuthUser
{
    public function __construct(
        public string $id,
        public string $username,
        public string $email,
        public string $displayName,
        public bool $isAdmin,
        public string $status,
        public ?string $lastLoginAt,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Coerce::str($data['id'] ?? ''),
            username: Coerce::str($data['username'] ?? ''),
            email: Coerce::str($data['email'] ?? ''),
            displayName: Coerce::str($data['display_name'] ?? ($data['username'] ?? '')),
            isAdmin: Coerce::bool($data['is_admin'] ?? false),
            status: Coerce::str($data['status'] ?? 'active', 'active'),
            // The server column is `last_login` (raw users row); accept the
            // `_at` spelling too in case a future server version renames it.
            lastLoginAt: Coerce::nstr($data['last_login'] ?? $data['last_login_at'] ?? null),
            createdAt: Coerce::nstr($data['created_at'] ?? null),
            updatedAt: Coerce::nstr($data['updated_at'] ?? null),
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
