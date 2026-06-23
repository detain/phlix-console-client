<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** Loading a photo library's albums for the PhotosScreen failed. */
final readonly class PhotosFailedMsg implements Msg
{
    public function __construct(
        public string $reason,
    ) {
    }
}
