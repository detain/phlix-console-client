<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Album;
use SugarCraft\Core\Msg;

/** The music library's album list arrived — the MusicScreen fills its table. */
final readonly class AlbumsLoadedMsg implements Msg
{
    /**
     * @param list<Album> $albums
     */
    public function __construct(
        public array $albums,
    ) {
    }
}
