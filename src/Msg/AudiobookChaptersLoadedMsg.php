<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\AudiobookChapter;
use SugarCraft\Core\Msg;

/** An audiobook's chapter list arrived — the AudiobookDetailScreen fills its chapter table. */
final readonly class AudiobookChaptersLoadedMsg implements Msg
{
    /**
     * @param list<AudiobookChapter> $chapters
     */
    public function __construct(
        public array $chapters,
    ) {
    }
}
