<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * One Live-TV guide entry, mirroring a row of
 * `GET /api/v1/admin/livetv/guide` → `{success, programs: [...]}` (top-level
 * named key). The server returns the raw `livetv_programs` row: `id, program_id,
 * channel_id, title, description, start_time (epoch int), end_time (epoch int),
 * category, season, episode, episode_title, series_episode, series_id, rating`.
 *
 * Surfaces a short S/E label derived from `season`/`episode`, and carries the
 * optional `series_id` so a series-recording rule can be created straight from a
 * selected program (a blank/absent value means "not a series" — gate the action).
 * Tolerant + immutable.
 */
final readonly class GuideProgram
{
    public function __construct(
        public string $id,
        public string $programId,
        public string $channelId,
        public string $title,
        public int $startTime,
        public int $endTime,
        public ?string $category,
        public ?string $description,
        public ?int $season,
        public ?int $episode,
        public ?string $seriesId,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Coerce::str($data['id'] ?? ''),
            programId: Coerce::str($data['program_id'] ?? ''),
            channelId: Coerce::str($data['channel_id'] ?? ''),
            title: Coerce::str($data['title'] ?? ''),
            startTime: Coerce::int($data['start_time'] ?? 0),
            endTime: Coerce::int($data['end_time'] ?? 0),
            category: Coerce::nstr($data['category'] ?? null),
            description: Coerce::nstr($data['description'] ?? null),
            season: Coerce::nint($data['season'] ?? null),
            episode: Coerce::nint($data['episode'] ?? null),
            seriesId: Coerce::nstr($data['series_id'] ?? null),
        );
    }

    /**
     * A short season/episode label ("S02E05", "S03", "E07"), or '' when neither
     * season nor episode is known. A pure formatting helper — no theme.
     */
    public function episodeLabel(): string
    {
        $label = '';
        if ($this->season !== null) {
            $label .= sprintf('S%02d', $this->season);
        }
        if ($this->episode !== null) {
            $label .= sprintf('E%02d', $this->episode);
        }

        return $label;
    }
}
