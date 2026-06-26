<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A backup action (create / delete / restore / upload-to-S3) succeeded. Carries
 * the server `message` to toast; the AdminBackupScreen toasts it and refetches
 * the list.
 */
final readonly class AdminBackupActionDoneMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}
