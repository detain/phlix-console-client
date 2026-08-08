<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Graphics;

use SugarCraft\Reel\Decode\Decoder;
use SugarCraft\Reel\Decode\RgbFrame;
use SugarCraft\Reel\Render\Mode;

/**
 * Test double for the Kitty terminal graphics protocol decoder.
 *
 * Simulates a Kitty-protocol-capable decoder that yields pre-encoded PNG
 * RgbFrame objects. The Kitty protocol is a binary protocol with incremental
 * rendering and animation support; this double exercises the Decoder interface
 * contract without producing actual Kitty transmission data.
 *
 * @implements Decoder<RgbFrame>
 */
final class KittyDecoder implements Decoder
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
