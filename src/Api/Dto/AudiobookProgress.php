<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto;

/**
 * A listener's progress through an audiobook, mirroring the server's
 * `{audiobook_id, user_id, position_ms, current_chapter_index,
 * completed_chapters, percent_complete, last_played_at}` shape (returned both
 * by the GET progress endpoint and inside the POST progress response).
 *
 * `positionMs` is MILLISECONDS (not seconds or ticks); `percentComplete` is a
 * 0–100 float; `lastPlayedAt` is a unix timestamp or null when never played.
 * Immutable.
 */
final readonly class AudiobookProgress
{
    /**
     * @param list<int> $completedChapters
     */
    public function __construct(
        public string $audiobookId,
        public string $userId,
        public int $positionMs,
        public int $currentChapterIndex,
        public array $completedChapters,
        public float $percentComplete,
        public ?int $lastPlayedAt,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $completed = [];
        foreach (Coerce::map($data['completed_chapters'] ?? null) as $value) {
            $int = Coerce::nint($value);
            if ($int !== null) {
                $completed[] = $int;
            }
        }

        return new self(
            audiobookId: Coerce::str($data['audiobook_id'] ?? ''),
            userId: Coerce::str($data['user_id'] ?? ''),
            positionMs: Coerce::int($data['position_ms'] ?? null, 0),
            currentChapterIndex: Coerce::int($data['current_chapter_index'] ?? null, 0),
            completedChapters: array_values($completed),
            percentComplete: Coerce::float($data['percent_complete'] ?? null, 0.0),
            lastPlayedAt: Coerce::nint($data['last_played_at'] ?? null),
        );
    }
}
