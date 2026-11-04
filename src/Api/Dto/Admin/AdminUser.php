<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * One row of the admin user list, mirroring an item of
 * `GET /api/v1/admin/users` → `{users: User[]}`. The server no longer ships
 * the raw `users` table row here: every wire-boundary read of a users row
 * passes through its single redaction door, `UserRow::forWire()` (phlix-server
 * `src/Auth/Dto/UserRow.php`, SRV-M12 @ 0a2cb2a5), so `password_hash`,
 * `password_reset_token` and `provider_data` NEVER appear in `$data` — the
 * listing is redacted at the repository source and the `GET …/{id}` detail
 * at the controller. Server-side the auth lookups deliberately keep the raw
 * row (`password_verify()` needs the hash); only redacted rows cross the
 * wire. The remaining keys are the DB columns: `id, username, email,
 * is_admin (TINYINT 0/1), status, created_at, last_login` (plus
 * `display_name`, `updated_at`, `avatar_url`, and non-credential
 * account-state columns like `must_change_password`). This DTO declares no
 * property for a redacted column and never populated one.
 *
 * Named `AdminUser` to avoid colliding with the auth-time {@see \Phlix\Console\Api\Dto\AuthUser}.
 *
 * Tolerant: the boolean admin flag accepts 0|1, "0"|"1", and true|false; the
 * last-login key is read as `last_login` (the real column) with `last_login_at`
 * as a fallback. Immutable.
 */
final readonly class AdminUser
{
    public function __construct(
        public string $id,
        public string $username,
        public string $email,
        public bool $isAdmin,
        public string $status,
        public ?string $displayName,
        public ?string $createdAt,
        public ?string $lastLoginAt,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Coerce::str($data['id'] ?? ''),
            username: Coerce::str($data['username'] ?? ''),
            email: Coerce::str($data['email'] ?? ''),
            isAdmin: Coerce::bool($data['is_admin'] ?? false),
            status: Coerce::str($data['status'] ?? 'active', 'active'),
            displayName: Coerce::nstr($data['display_name'] ?? null),
            createdAt: Coerce::nstr($data['created_at'] ?? null),
            lastLoginAt: Coerce::nstr($data['last_login'] ?? ($data['last_login_at'] ?? null)),
        );
    }

    /** A human label: the display name when present, else the username. */
    public function label(): string
    {
        return $this->displayName ?? $this->username;
    }

    /** The role cell text. */
    public function roleLabel(): string
    {
        return $this->isAdmin ? 'Admin' : 'User';
    }
}
