<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;

/**
 * Structural pin for the media-authority rollout [review console__secaudit m4,
 * M-CONSOLE-55]: every server-supplied PLAYBACK/DOWNLOAD source in the app must
 * travel through the gated resolver, and the artwork carve-out must stay on the
 * ungated one. The predicate itself is pinned by {@see ServerAuthorityTest};
 * this guard stops a future feature from re-introducing an ungated sink.
 */
final class MediaAuthorityWiringTest extends TestCase
{
    private function src(string $rel): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/src/' . $rel);
        self::assertIsString($contents);

        return $contents;
    }

    public function testNoMediaSourceFieldBypassesTheGateInPlaybackScreens(): void
    {
        $forbidden = [
            'Screen/PlayerScreen.php' => [
                'resolveUrl($this->item->streamUrl',
                'resolveUrl($job->masterUrl',
                'resolveUrl($url)',
            ],
            'Screen/CastScreen.php' => ['resolveUrl($this->item->streamUrl'],
            'App.php' => [
                'resolveUrl($item->streamUrl',
                'resolveUrl($audiobook->streamUrl',
            ],
        ];

        foreach ($forbidden as $file => $patterns) {
            $contents = $this->src($file);
            foreach ($patterns as $pattern) {
                self::assertStringNotContainsString(
                    $pattern,
                    $contents,
                    "a media source URL reached a sink without the authority gate in {$file} [M-CONSOLE-55]",
                );
            }
        }
    }

    public function testTheGatedResolverIsSingleSourcedAmongScreens(): void
    {
        $definitions = 0;
        foreach (glob(dirname(__DIR__, 3) . '/src/Screen/*.php') ?: [] as $path) {
            $definitions += substr_count((string) file_get_contents($path), 'function resolveMediaUrl');
        }

        self::assertSame(1, $definitions, 'screens get the gate from the ResolvesUrls trait only (App carries its documented twin)');
    }

    public function testTheGateIsWiredAtEveryMediaSink(): void
    {
        self::assertSame(5, substr_count($this->src('Screen/PlayerScreen.php'), '$this->resolveMediaUrl('), 'stream + 2 transcode starts + ladder + quality rendition');
        self::assertSame(1, substr_count($this->src('Screen/CastScreen.php'), '$this->resolveMediaUrl('));
        self::assertSame(2, substr_count($this->src('App.php'), '$this->resolveMediaUrl('), 'track + audiobook');
    }

    public function testArtworkStaysOnTheUngatedResolverAndTheCarveOutIsDocumented(): void
    {
        // Display-only art is cross-host by server design (TMDB/CDN) — it must
        // NOT be host-pinned, and the carve-out must stay written down.
        self::assertStringContainsString('resolveUrl($this->item->posterUrl', $this->src('Screen/CastScreen.php'));
        self::assertStringContainsString('carve-out from the media-authority gate', $this->src('Screen/PosterFetchPolicy.php'));
    }

    public function testTheDownloadSinkIsWiredWithTheServerAuthority(): void
    {
        self::assertStringContainsString('new DownloadService(serverBase: $this->baseUrl)', $this->src('Screen/DetailScreen.php'));
        self::assertStringContainsString('ServerAuthority::trusted($this->serverBase, $signedUrl)', $this->src('Download/DownloadService.php'));
    }
}
