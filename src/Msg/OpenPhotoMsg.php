<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\PhotoAlbum;
use SugarCraft\Core\Msg;

/**
 * Open the fullscreen photo viewer at a given index within an album — the App
 * pushes a PhotoViewerScreen onto the stack. Carries the whole {@see PhotoAlbum}
 * (it already holds its photos, each with a signed `full_url`, so the viewer can
 * page/slide them client-side with no extra round-trip) plus the index of the
 * photo to open first (the grid cursor's position).
 */
final readonly class OpenPhotoMsg implements Msg
{
    public function __construct(
        public PhotoAlbum $album,
        public int $index,
    ) {
    }
}
