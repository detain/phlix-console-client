<?php

declare(strict_types=1);

namespace Phlix\Console\Tests;

use Phlix\Console\Spike\PosterSpike;
use Phlix\Console\Spike\VideoSpike;
use PHPUnit\Framework\TestCase;

final class SpikeTest extends TestCase
{
    private string $png = '';
    private string $mp4 = '';

    protected function setUp(): void
    {
        // Synthesize a tiny 2:3 poster with gd — no external asset required.
        $img = imagecreatetruecolor(48, 72);
        imagefilledrectangle($img, 0, 0, 48, 72, imagecolorallocate($img, 200, 40, 40));
        imagefilledrectangle($img, 8, 8, 40, 64, imagecolorallocate($img, 245, 165, 36));
        $this->png = tempnam(sys_get_temp_dir(), 'phlix_poster_') . '.png';
        imagepng($img, $this->png);
        imagedestroy($img);
    }

    protected function tearDown(): void
    {
        foreach ([$this->png, $this->mp4] as $f) {
            if ($f !== '' && is_file($f)) {
                @unlink($f);
            }
        }
    }

    public function testPosterRendersHalfBlockAnsi(): void
    {
        $out = (new PosterSpike())->render($this->png, 20, 10, 'halfblock');

        self::assertNotSame('', $out);
        self::assertStringContainsString("\x1b[", $out, 'half-block output should carry SGR escapes');
    }

    public function testPosterMissingFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new PosterSpike())->render('/no/such/poster.png', 10);
    }

    public function testVideoFrameDecodesToAnsi(): void
    {
        if (!$this->haveFfmpeg()) {
            self::markTestSkipped('ffmpeg not available');
        }

        $this->mp4 = tempnam(sys_get_temp_dir(), 'phlix_video_') . '.mp4';
        exec(sprintf(
            'ffmpeg -y -f lavfi -i testsrc=duration=1:size=320x240:rate=15 -pix_fmt yuv420p %s 2>/dev/null',
            escapeshellarg($this->mp4)
        ), $_, $rc);

        if ($rc !== 0 || !is_file($this->mp4) || filesize($this->mp4) === 0) {
            self::markTestSkipped('could not synthesize a test video');
        }

        $frames = (new VideoSpike())->frames($this->mp4, 1, 40, 12, 'halfblock');

        self::assertCount(1, $frames);
        self::assertNotSame('', $frames[0]);
    }

    private function haveFfmpeg(): bool
    {
        exec('command -v ffmpeg', $_, $rc);
        return $rc === 0;
    }
}
