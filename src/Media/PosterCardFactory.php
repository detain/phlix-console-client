<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Media;

use Phlix\Console\Api\Dto\MediaItem;
use SugarCraft\Gallery\PosterCard;

/**
 * Maps a Phlix {@see MediaItem} to a generic sugar-gallery {@see PosterCard}.
 *
 * sugar-gallery's card is intentionally domain-agnostic (it knows nothing about
 * Phlix), so this thin adapter lives in the client and is the one place that
 * bridges the server DTO to the widget.
 */
final class PosterCardFactory
{
    public static function fromMediaItem(MediaItem $item, ?float $progress = null): PosterCard
    {
        return new PosterCard($item->id, $item->name, $item->posterUrl, $progress);
    }
}
