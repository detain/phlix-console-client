<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Reel;

use SugarCraft\Reel\Decode\RgbFrame;
use SugarCraft\Reel\Render\Mode;

/**
 * A client-side sugar-reel FakeDecoder double yielding a fixed frame
 * sequence — so PlayerScreen tests can build a real sugar-reel Player
 * (via Player::openForTest) without spawning ffmpeg.
 */
final class FakePlayerDecoder extends \SugarCraft\Reel\Tests\FakeDecoder
{
    private int $index = 0;
    private bool $closed = false;

    /** @param list<RgbFrame> $frames */
    public function __construct(protected array $frames)
    {
    }

    public function open(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0): void
    {
        $this->index = 0;
        $this->closed = false;
    }

    public function next(): ?RgbFrame
    {
        if ($this->closed) {
            return null;
        }

        return $this->frames[$this->index++] ?? null;
    }

    public function close(): void
    {
        $this->closed = true;
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
