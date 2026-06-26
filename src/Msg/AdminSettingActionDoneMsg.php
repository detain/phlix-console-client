<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A setting update (PUT) succeeded. Carries the server `message` to toast; the
 * AdminSettingsScreen toasts it and refetches via GET (the PUT response carries
 * no `types`).
 */
final readonly class AdminSettingActionDoneMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}
