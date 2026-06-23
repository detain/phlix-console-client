<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\PhotoAlbum;
use SugarCraft\Core\Msg;

/**
 * The date-grouped photo albums for a library resolved (the server returns every
 * album, each with its full photo list, in one call) — the PhotosScreen builds
 * its whole album-cover grid from this in one shot.
 */
final readonly class PhotoAlbumsLoadedMsg implements Msg
{
    /**
     * @param list<PhotoAlbum> $albums
     */
    public function __construct(
        public array $albums,
    ) {
    }
}
