<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Chapter;
use SugarCraft\Core\Msg;

/**
 * Carries the loaded chapter list for a media item (movie/episode).
 */
final readonly class ChaptersLoadedMsg extends Msg
{
    /**
     * @param list<Chapter> $chapters
     */
    public function __construct(
        public string $itemId,
        public array $chapters,
    ) {
    }
}
