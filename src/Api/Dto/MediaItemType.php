<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto;

/**
 * The media item type, mirroring the server's 13-member `media_items.type` ENUM.
 *
 * Sourced from:
 *   - migrations/001_initial_schema.sql  (movie, series, season, episode, music, album, artist, video, audio, book, photo)
 *   - migrations/011_music_library.sql    (+ track)
 *   - migrations/034_media_items_type_audiobook.sql (+ audiobook)
 *
 * @note The library type (libraries.type) is a different, smaller ENUM with only
 *       5 values: movie, series, music, photo, video. This enum is for media_items.type.
 */
enum MediaItemType: string
{
    case MOVIE = 'movie';
    case SERIES = 'series';
    case SEASON = 'season';
    case EPISODE = 'episode';
    case TRACK = 'track';
    case MUSIC = 'music';
    case ALBUM = 'album';
    case ARTIST = 'artist';
    case VIDEO = 'video';
    case AUDIO = 'audio';
    case BOOK = 'book';
    case PHOTO = 'photo';
    case AUDIOBOOK = 'audiobook';

    /**
     * Valid library types for creating a library (a subset of MediaItemType).
     *
     * @return list<string>
     */
    public static function validLibraryTypes(): array
    {
        return ['movie', 'series', 'music', 'audiobook', 'photo', 'book', 'video'];
    }

    /**
     * Whether this type is a valid library type for library creation.
     */
    public function isValidLibraryType(): bool
    {
        return in_array($this->value, self::validLibraryTypes(), true);
    }
}
