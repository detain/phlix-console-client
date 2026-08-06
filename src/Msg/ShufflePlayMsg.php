<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Carries the result of a shuffle-play request.
 *
 * @param list<string> $shuffledIds The shuffled media item IDs to play.
 * @param string       $mode       'shuffle' for containers, 'single' for leaf items.
 */
final readonly class ShufflePlayMsg implements Msg
{
    /**
     * @param list<string> $shuffledIds
     */
    public function __construct(
        public string $mediaId,
        public array $shuffledIds,
        public string $mode,
    ) {
    }
}
