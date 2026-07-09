<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * One backup archive, mirroring an item of `GET /api/v1/admin/backup/list` →
 * `{success, data: Backup[], count}` (the {@see \Phlix\Server\Http\Controllers\Admin\BackupController}
 * IS enveloped — admin envelopes are per-controller, so the list lives under
 * `data`). Each row is the `BackupManager::listBackups()` shape:
 * `id, label, file_path, size_bytes, checksum_sha256, is_s3 (bool),
 * created_at, expires_at?`.
 *
 * The create endpoint (`POST .../create` → `{data: {backup_id, file_path,
 * size_bytes}}`) returns a thinner shape (a `backup_id` rather than `id`, no
 * `created_at`); the defensive `fromArray` tolerates it, though the create flow
 * resolves the server `message` rather than this DTO.
 *
 * Immutable. The checksum and on-disk path are intentionally NOT carried — the
 * screen lists Label/ID · Created · Size and a small S3 marker.
 */
final readonly class Backup
{
    public function __construct(
        public string $id,
        public ?string $label,
        public ?string $createdAt,
        public int $sizeBytes,
        public bool $isS3,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Coerce::str($data['id'] ?? ($data['backup_id'] ?? '')),
            label: Coerce::nstr($data['label'] ?? null),
            createdAt: Coerce::nstr($data['created_at'] ?? null),
            sizeBytes: Coerce::int($data['size_bytes'] ?? 0),
            isS3: Coerce::bool($data['is_s3'] ?? false),
        );
    }

    /** The row's display label: the human label, or the id when unlabelled. */
    public function displayLabel(): string
    {
        return $this->label ?? $this->id;
    }
}
