<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\MediaItem;
use SugarCraft\Core\Msg;

/**
 * Carries the loaded similar media items from the API to the DetailScreen.
 */
final readonly class SimilarLoadedMsg implements Msg
{
    /**
     * @param list<MediaItem> $items
     */
    public function __construct(
        public string $mediaId,
        public array $items,
    ) {
    }
}
