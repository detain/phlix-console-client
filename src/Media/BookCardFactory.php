<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Media;

use Phlix\Console\Api\Dto\Book;
use SugarCraft\Gallery\PosterCard;

/**
 * Maps a Phlix {@see Book} to a generic sugar-gallery {@see PosterCard}.
 *
 * Unlike a {@see MediaItem}, a book carries NO usable cover URL in the list
 * shape (the detail endpoint mints a signed one on demand), so the card is built
 * with a null `posterUrl`; {@see \Phlix\Console\Screen\BooksScreen} lazily
 * resolves the cover per cell and attaches it via {@see PosterCard::withPoster()}.
 * The card title is the book title (the widget has no subtitle/badge slot).
 */
final class BookCardFactory
{
    public static function fromBook(Book $book): PosterCard
    {
        return new PosterCard($book->id, $book->title);
    }
}
