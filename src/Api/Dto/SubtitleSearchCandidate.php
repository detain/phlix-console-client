<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto;

/**
 * An external subtitle candidate returned by the remote subtitle search
 * (e.g. OpenSubtitles). Mirrors a row from
 * `GET /api/v1/media/{id}/subtitles/search`. Immutable.
 */
final readonly class SubtitleSearchCandidate
{
    public function __construct(
        public string $provider,
        public string $downloadId,
        public string $language,
        public string $name,
        public string $format,
        public ?string $releaseName,
        public bool $hearingImpaired,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            provider: Coerce::str($data['provider'] ?? '', ''),
            downloadId: Coerce::str($data['download_id'] ?? $data['downloadId'] ?? '', ''),
            language: Coerce::str($data['language'] ?? 'und', 'und'),
            name: Coerce::str($data['name'] ?? '', ''),
            format: Coerce::str($data['format'] ?? '', ''),
            releaseName: isset($data['release_name']) ? Coerce::str($data['release_name']) : null,
            hearingImpaired: Coerce::bool($data['hearing_impaired'] ?? $data['hearingImpaired'] ?? false),
        );
    }

    /**
     * @return array{provider:string, download_id:string, language:string, name:string, format:string, release_name:?string, hearing_impaired:bool}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'download_id' => $this->downloadId,
            'language' => $this->language,
            'name' => $this->name,
            'format' => $this->format,
            'release_name' => $this->releaseName,
            'hearing_impaired' => $this->hearingImpaired,
        ];
    }
}
