<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\MediaRatings;
use SugarCraft\Core\Msg;

/** Ratings for a media item resolved; the DetailScreen renders the badge. */
final readonly class RatingsLoadedMsg implements Msg
{
    public function __construct(
        public MediaRatings $ratings,
    ) {
    }
}
