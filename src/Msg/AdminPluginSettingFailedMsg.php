<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Saving a plugin setting failed. Carries the server `error` the
 * AdminPluginDetailScreen toasts (the detail is left unchanged).
 */
final readonly class AdminPluginSettingFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}
