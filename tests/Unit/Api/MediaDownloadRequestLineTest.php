<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Tests\Unit\Api;

use Phlix\Console\Api\ApiClient;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that the media download HTTP call targets the correct route.
 *
 * Route contract (confirmed against MediaItemController::getDownload):
 *   GET /api/v1/media/{id}/download → returns {url, filename, size, content_type}
 */
final class MediaDownloadRequestLineTest extends TestCase
{
    public function testDownloadMediaRouteUsesGet(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../src/Api/ApiClient.php');

        // downloadMedia must use GET, not POST or other methods
        $this->assertStringContainsString(
            "'GET', '/api/v1/media/' . rawurlencode(\$mediaId) . '/download'",
            $source,
            'downloadMedia() must GET /api/v1/media/{id}/download'
        );
    }

    public function testDownloadMediaPathContainsMediaSegment(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../src/Api/ApiClient.php');

        // The path must contain /media/ and /download
        $this->assertStringContainsString(
            '/api/v1/media/',
            $source,
            'downloadMedia() must call a /media/ path'
        );
        $this->assertStringContainsString(
            '/download',
            $source,
            'downloadMedia() must call a /download path'
        );
    }

    public function testDownloadMediaMethodExists(): void
    {
        $this->assertStringContainsString(
            'public function downloadMedia(string $mediaId)',
            file_get_contents(__DIR__ . '/../../../src/Api/ApiClient.php'),
            'downloadMedia() method must exist in ApiClient.php'
        );
    }

    public function testDownloadSectionCommentExists(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../src/Api/ApiClient.php');

        $this->assertStringContainsString(
            '// ---- download -----',
            $source,
            'A download section comment must exist in ApiClient.php'
        );
    }
}
