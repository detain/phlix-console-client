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
    private bool $ended = false;

    /** @param list<RgbFrame> $frames */
    public function __construct(protected array $frames)
    {
    }

    public function open(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0): void
    {
        $this->index = 0;
        $this->closed = false;
        $this->ended = false;
    }

    public function reopen(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0): void
    {
        $this->index = 0;
        $this->closed = false;
        $this->ended = false;
        $this->opened = true;
        $this->everOpened = true;
    }

    public function next(): ?RgbFrame
    {
        if ($this->closed) {
            return null;
        }

        $frame = $this->frames[$this->index++] ?? null;
        if ($frame === null) {
            $this->ended = true;
        }

        return $frame;
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

    public function isEnded(): bool
    {
        return $this->ended;
    }
}
