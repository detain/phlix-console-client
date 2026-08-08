<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * The like toggle API call succeeded; confirms the optimistic update.
 */
final readonly class LikeToggledMsg implements Msg
{
    public function __construct(
        public string $mediaId,
        public int $likeLevel,
    ) {
    }
}
