<?php

declare(strict_types=1);

namespace Phlix\Console\Spike;

use SugarCraft\Reel\Decode\DecoderFactory;
use SugarCraft\Reel\Render\Mode;
use SugarCraft\Reel\Render\RendererFactory;
use SugarCraft\Reel\Source\VideoSource;

/**
 * Phase-0 proof: probe a video/GIF and decode the first N frames, rendering
 * each to ANSI. Exercises the ffmpeg/gif decode → FrameRenderer pipeline
 * without needing an interactive TTY, so it can run in CI.
 *
 * ffmpeg decodes HEVC/MKV/AV1 natively and accepts http(s) URLs — this is the
 * basis for the Phase-4 player direct-playing what browsers cannot.
 */
final class VideoSpike
{
    public function probeReport(string $src): string
    {
        if (!is_file($src)) {
            return "source: {$src} (not a local file — passed straight to ffmpeg)";
        }
        $v = VideoSource::probe($src);

        return sprintf(
            'source: %s  %dx%d  %.2ffps  %.1fs  audio=%s',
            basename($src),
            $v->width,
            $v->height,
            $v->fps,
            $v->duration,
            $v->hasAudio ? 'yes' : 'no',
        );
    }

    /**
     * Decode up to $count frames and render each to ANSI.
     *
     * @return list<string>
     */
    public function frames(string $src, int $count = 1, int $cols = 60, int $rows = 20, ?string $mode = null): array
    {
        $m = $this->mode($mode);
        $renderer = RendererFactory::create($m);
        $decoder = DecoderFactory::create($src, $cols, $rows, 1.0, $m);

        $out = [];
        try {
            for ($i = 0; $i < $count; $i++) {
                $frame = $decoder->next();
                if ($frame === null) {
                    break;
                }
                $out[] = $renderer->render($frame, $m);
            }
        } finally {
            $decoder->close();
        }

        return $out;
    }

    private function mode(?string $mode): Mode
    {
        return match ($mode) {
            null, 'auto' => RendererFactory::autoMode(),
            default      => Mode::from($mode),
        };
    }
}
