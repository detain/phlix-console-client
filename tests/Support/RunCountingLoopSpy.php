<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Support;

use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\EventLoop\TimerInterface;

/**
 * A LoopInterface spy that counts how often run() is entered, delegating all
 * scheduling to a fresh internal StreamSelectLoop (which is final and cannot
 * be subclassed). Installed via `Loop::set()` it exposes nested-drain code:
 * a command closure that re-enters `Loop::get()->run()` bumps runCount past
 * the single outer pump (M-CONSOLE-36).
 */
final class RunCountingLoopSpy implements LoopInterface
{
    public int $runCount = 0;

    private StreamSelectLoop $inner;

    public function __construct()
    {
        $this->inner = new StreamSelectLoop();
    }

    public function addReadStream($stream, $listener)
    {
        $this->inner->addReadStream($stream, $listener);
    }

    public function addWriteStream($stream, $listener)
    {
        $this->inner->addWriteStream($stream, $listener);
    }

    public function removeReadStream($stream)
    {
        $this->inner->removeReadStream($stream);
    }

    public function removeWriteStream($stream)
    {
        $this->inner->removeWriteStream($stream);
    }

    public function addTimer($interval, $callback)
    {
        return $this->inner->addTimer($interval, $callback);
    }

    public function addPeriodicTimer($interval, $callback)
    {
        return $this->inner->addPeriodicTimer($interval, $callback);
    }

    public function cancelTimer(TimerInterface $timer)
    {
        $this->inner->cancelTimer($timer);
    }

    public function futureTick($listener)
    {
        $this->inner->futureTick($listener);
    }

    public function addSignal($signal, $listener)
    {
        $this->inner->addSignal($signal, $listener);
    }

    public function removeSignal($signal, $listener)
    {
        $this->inner->removeSignal($signal, $listener);
    }

    public function run()
    {
        ++$this->runCount;
        $this->inner->run();
    }

    public function stop()
    {
        $this->inner->stop();
    }
}
