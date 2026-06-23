<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\PhotoAlbum;
use SugarCraft\Core\Msg;

/**
 * Open a photo album's thumbnail grid — the App pushes a PhotoAlbumScreen onto
 * the stack. Carries the whole {@see PhotoAlbum} (it already holds its photos,
 * each with a signed thumbnail, so the screen needs no further fetch).
 */
final readonly class OpenPhotoAlbumMsg implements Msg
{
    public function __construct(
        public PhotoAlbum $album,
    ) {
    }
}
