<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Reel;

use SugarCraft\Reel\AudioPlayer;

/**
 * A sugar-reel {@see AudioPlayer} double that records lifecycle calls WITHOUT
 * spawning ffplay/mpv — so AlbumScreen audio tests assert start/stop/pause/
 * resume behaviour deterministically. Injected via the screen's audio factory.
 *
 * The parent ctor is fed a dummy URL (never used, since no subprocess starts);
 * the URL the screen actually resolved is captured separately by the factory.
 */
final class FakeAudioPlayer extends AudioPlayer
{
    public int $startCalls = 0;
    public int $stopCalls = 0;
    public int $pauseCalls = 0;
    public int $resumeCalls = 0;
    private bool $playing = false;

    public function __construct(public readonly string $url)
    {
        parent::__construct($url);
    }

    public function start(): void
    {
        $this->startCalls++;
        $this->playing = true;
    }

    public function stop(): void
    {
        $this->stopCalls++;
        $this->playing = false;
    }

    public function pause(): void
    {
        $this->pauseCalls++;
    }

    public function resume(): void
    {
        $this->resumeCalls++;
    }

    public function isPlaying(): bool
    {
        return $this->playing;
    }
}
