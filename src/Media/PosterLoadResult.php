<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Media;

/**
 * The result of {@see PosterLoader::load()}: in inline mode the marker is the
 * rendered poster bytes and imageId is null; in overlay mode the marker is the
 * placeholder cell block and imageId is the assigned overlay image ID.
 * The digest is the {@see \SugarCraft\Mosaic\ImageLayer::digestFor()} window
 * key of bytes+size and is non-null in overlay mode.
 */
final readonly class PosterLoadResult
{
    public function __construct(
        public string $marker,
        public ?int $imageId,
        public ?string $digest = null,
    ) {
    }
}
