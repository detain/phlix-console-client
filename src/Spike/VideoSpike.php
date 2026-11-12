<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Spike;

use SugarCraft\Video\Decode\Decoder;
use SugarCraft\Video\Decode\DecoderFactory;
use SugarCraft\Video\Decode\FfmpegDecoder;
use SugarCraft\Video\Render\Mode;
use SugarCraft\Video\Render\RendererFactory;
use SugarCraft\Video\Source\VideoSource;

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
        // [review CON-37] VideoSource::probe() fails soft: an absent, hung or
        // erroring ffprobe (or a file with no video stream) comes back as a
        // zeroed default. Printing that as `0x0  0.00fps  0.0s` fabricated
        // metadata for a source nobody measured — say what actually happened.
        if ($v->width <= 0 || $v->height <= 0) {
            return sprintf(
                'source: %s (no video metadata — ffprobe unavailable or found no video stream)',
                basename($src),
            );
        }

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
     * A request for at least one frame that yields none is a failure, not an
     * empty success [review CON-37]: the decoder's end-of-stream is the only
     * signal a dead ffmpeg (unreadable/corrupt source, unsupported codec)
     * gives, so it throws naming ffmpeg's exit status — the same rc=1 path
     * PosterSpike's missing-file throw takes through bin/phlix.
     *
     * @return list<string>
     * @throws \RuntimeException when $count > 0 and the decoder yields no frame
     */
    public function frames(string $src, int $count = 1, int $cols = 60, int $rows = 20, ?string $mode = null): array
    {
        $m = $this->mode($mode);
        $renderer = RendererFactory::new($m);
        $decoder = DecoderFactory::new($src, $cols, $rows, 1.0, $m);

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

        if ($count > 0 && $out === []) {
            throw new \RuntimeException(sprintf(
                'no frames decoded from %s (%s)',
                $src,
                self::decoderOutcome($decoder),
            ));
        }

        return $out;
    }

    /**
     * Name how the (closed) decoder ended, for the zero-frame error.
     */
    private static function decoderOutcome(Decoder $decoder): string
    {
        if (!$decoder instanceof FfmpegDecoder) {
            return 'gif decoder yielded no frames';
        }
        $code = $decoder->exitCode();

        return $code === null
            ? 'ffmpeg exit status unknown'
            : "ffmpeg exited with code {$code}";
    }

    private function mode(?string $mode): Mode
    {
        return match ($mode) {
            null, 'auto' => RendererFactory::autoMode(),
            default      => Mode::from($mode),
        };
    }
}
