<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\SubtitleSearchCandidate;
use SugarCraft\Core\Msg;

/**
 * Carries the loaded external subtitle search candidates from the API to the DetailScreen.
 *
 * @param list<SubtitleSearchCandidate> $candidates
 */
final readonly class SubtitleSearchResultMsg implements Msg
{
    /**
     * @param list<SubtitleSearchCandidate> $candidates
     */
    public function __construct(
        public string $mediaId,
        public array $candidates,
    ) {
    }
}
