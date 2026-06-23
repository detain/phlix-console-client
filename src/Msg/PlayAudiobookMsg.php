<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Audiobook;
use SugarCraft\Core\Msg;

/**
 * Play (or seek) an audiobook at $startMs — the
 * {@see \Phlix\Console\Screen\AudiobookDetailScreen} emits this on Enter (a
 * chapter's start offset) and on `r` (the saved resume position), and the App
 * (which now OWNS the audiobook audio session) spawns the player synchronously
 * over the audiobook's ONE signed `stream_url` (already on the loaded detail —
 * no per-item fetch). Carries the whole {@see Audiobook} (its stream URL +
 * duration drive playback) and its chapter list (the seek markers, so the App
 * can report progress + label the current chapter).
 */
final readonly class PlayAudiobookMsg implements Msg
{
    /**
     * @param list<\Phlix\Console\Api\Dto\AudiobookChapter> $chapters
     */
    public function __construct(
        public Audiobook $audiobook,
        public array $chapters,
        public int $startMs,
    ) {
    }
}
