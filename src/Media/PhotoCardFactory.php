<?php

declare(strict_types=1);

namespace Phlix\Console\Media;

use Phlix\Console\Api\Dto\Photo;
use Phlix\Console\Api\Dto\PhotoAlbum;
use SugarCraft\Gallery\PosterCard;

/**
 * Maps a Phlix {@see PhotoAlbum} or {@see Photo} to a generic sugar-gallery
 * {@see PosterCard}.
 *
 * Unlike {@see BookCardFactory}, a photo's signed `thumbnail_url` is KNOWN
 * UPFRONT (the album/photo objects carry it), so the card is built WITH its
 * thumbnail as `posterUrl` — the grid loads that directly (no per-card detail
 * fetch). An album with no cover, or a photo with no thumbnail, yields a card
 * with a null `posterUrl`; the grid then keeps its placeholder.
 *
 * An album card's title is its date plus its photo count ("2026-06-23 (12)"); a
 * photo card's title is the photo's file name (the widget has no subtitle slot).
 */
final class PhotoCardFactory
{
    public static function fromAlbum(PhotoAlbum $album): PosterCard
    {
        return new PosterCard(
            $album->id,
            $album->date . ' (' . $album->photoCount . ')',
            $album->coverPhoto?->thumbnailUrl,
        );
    }

    public static function fromPhoto(Photo $photo): PosterCard
    {
        return new PosterCard($photo->id, $photo->name, $photo->thumbnailUrl);
    }
}
