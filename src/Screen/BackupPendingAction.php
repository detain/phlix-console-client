<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Dto\Admin\Backup;

/**
 * A backup action that has been armed and is awaiting an inline (y/n)
 * confirmation on the {@see AdminBackupScreen}'s status line. Immutable.
 *
 * Restore is DESTRUCTIVE — its prompt is a STRONG confirm warning that it
 * OVERWRITES current data; only an explicit `y` performs it.
 */
final readonly class BackupPendingAction
{
    public function __construct(
        public string $action,
        public Backup $backup,
    ) {
    }

    /** The confirm prompt, e.g. "Delete 'pre-upgrade'? (y/n)". */
    public function prompt(): string
    {
        $name = $this->backup->displayLabel();

        return match ($this->action) {
            'restore' => "Restore '{$name}'? This OVERWRITES current data. (y/n)",
            'upload-s3' => "Upload '{$name}' to S3? (y/n)",
            default => "Delete '{$name}'? (y/n)",
        };
    }
}
