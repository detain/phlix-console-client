<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto;

/**
 * A server HLS transcode job — the fallback when a file can't be direct-played.
 * Mirrors both `POST /media/{id}/transcode` (job_id + master_url + status) and
 * `GET /transcode/{job}/status` (+ playlist_ready + progress). `masterUrl` is a
 * signed `/hls/{job}/master.m3u8` the player feeds to ffmpeg (no auth header).
 * Immutable.
 */
final readonly class TranscodeJob
{
    /**
     * @param list<Rendition> $variants the ABR ladder rungs this job exposes
     *        (highest-first), each with a signed per-variant playlist `url`;
     *        empty for a legacy job (`variants` null on the wire).
     */
    public function __construct(
        public string $jobId,
        public string $status,
        public string $masterUrl,
        public float $progress,
        public bool $playlistReady,
        public array $variants = [],
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            jobId: Coerce::str($data['job_id'] ?? ''),
            status: Coerce::str($data['status'] ?? ''),
            masterUrl: Coerce::str($data['master_url'] ?? ''),
            progress: Coerce::float($data['progress'] ?? 0),
            playlistReady: Coerce::bool($data['playlist_ready'] ?? false),
            variants: Rendition::listFromArray($data['variants'] ?? null),
        );
    }

    /**
     * Ready to start HLS playback — the master playlist + first segments exist
     * (HLS streams while later segments keep encoding), or the job is done.
     */
    public function isPlayable(): bool
    {
        return ($this->playlistReady || $this->status === 'completed') && $this->masterUrl !== '';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
