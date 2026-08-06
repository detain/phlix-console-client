<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Carries the missing-episode report from the API to the DetailScreen.
 *
 * The canonical count of missing episodes is always `count($missingEpisodes)`;
 * `totalExpected` and `totalExisting` are optional and absent on degraded
 * server responses (no metadata_json or no positive episode_count).
 *
 * @param list<array{episode_number:int}> $missingEpisodes
 */
final readonly class MissingEpisodesLoadedMsg implements Msg
{
    /**
     * @param list<array{episode_number:int}> $missingEpisodes
     */
    public function __construct(
        public string $mediaId,
        public array $missingEpisodes,
        public ?int $totalExpected = null,
        public ?int $totalExisting = null,
    ) {
    }

    /** True when there are no missing episodes (empty report). */
    public function isEmpty(): bool
    {
        return $this->missingEpisodes === [];
    }
}
