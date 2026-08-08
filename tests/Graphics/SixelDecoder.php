<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Graphics;

use SugarCraft\Reel\Decode\Decoder;
use SugarCraft\Reel\Decode\RgbFrame;
use SugarCraft\Reel\Render\Mode;

/**
 * Test double for the SIXEL (DEC Standard Graphics) protocol decoder.
 *
 * Simulates a SIXEL-capable decoder that yields pre-encoded PNG RgbFrame
 * objects. SIXEL encodes images as 6-pixel-tall bitmap decks with a color
 * palette index; this double does not produce actual Sixel bitstreams —
 * it exercises the Decoder interface contract used by PlayerScreen.
 *
 * @implements Decoder<RgbFrame>
 */
final class SixelDecoder implements Decoder
{
    private int $index = 0;
    private bool $opened = false;
    private bool $closed = false;

    /** @param list<RgbFrame> $frames */
    public function __construct(
        private readonly array $frames,
    ) {
    }

    public function open(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0): void
    {
        $this->opened = true;
        $this->closed = false;
        $this->index = 0;
    }

    public function reopen(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0): void
    {
        $this->opened = true;
        $this->closed = false;
        $this->index = 0;
    }

    public function next(): ?RgbFrame
    {
        if ($this->closed) {
            return null;
        }

        if (!$this->opened) {
            return null;
        }

        return $this->frames[$this->index++] ?? null;
    }

    public function close(): void
    {
        $this->closed = true;
        $this->opened = false;
    }

    public function getIterator(): \Generator
    {
        while (($frame = $this->next()) !== null) {
            yield $frame;
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }
}
