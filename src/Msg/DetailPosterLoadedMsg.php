<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** The detail screen's hero poster finished rendering to ANSI, with overlay imageId if in pixel-graphics mode. */
final readonly class DetailPosterLoadedMsg implements Msg
{
    public function __construct(
        public string $marker,
        public ?int $imageId = null,
    ) {
    }
}
