<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Admin\ServerSettings;
use SugarCraft\Core\Msg;

/**
 * The server settings loaded successfully. Carries the full {@see ServerSettings}
 * set; the AdminSettingsScreen swaps it in.
 */
final readonly class AdminSettingsLoadedMsg implements Msg
{
    public function __construct(
        public ServerSettings $settings,
    ) {
    }
}
