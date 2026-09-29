<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api;

use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\Timer;
use React\EventLoop\TimerInterface;

/**
 * A deterministic fake {@see LoopInterface} for scheduler tests: every armed
 * delay is recorded verbatim, and pending one-shot callbacks are fired by the
 * test — no wall clock, no sleeps. Built for the SyncPlay reconnect-ladder
 * pins (exact 1s×2ⁿ sequence, single-arm dedupe, cancellation bookkeeping).
 *
 * Stream/signal/tick plumbing is deliberately unsupported: a test that needs
 * it has left the unit boundary this double guards.
 */
final class FakeClockLoop implements LoopInterface
{
    /** @var list<float> Every one-shot delay ever armed, in arming order. */
    public array $oneShotDelays = [];

    /** @var list<float> Every periodic interval ever armed, in arming order. */
    public array $periodicDelays = [];

    /** @var list<float> Intervals of timers passed to cancelTimer(), in order. */
    public array $cancelledDelays = [];

    /** @var list<TimerInterface> One-shots armed and not yet fired or cancelled. */
    public array $pendingOneShots = [];

    /** @var list<TimerInterface> Periodics armed and not yet cancelled. */
    public array $pendingPeriodics = [];

    public function addTimer($interval, $callback): TimerInterface
    {
        $timer = new Timer($interval, $callback, false);
        $this->oneShotDelays[] = (float) $interval;
        $this->pendingOneShots[] = $timer;

        return $timer;
    }

    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        $timer = new Timer($interval, $callback, true);
        $this->periodicDelays[] = (float) $interval;
        $this->pendingPeriodics[] = $timer;

        return $timer;
    }

    public function cancelTimer(TimerInterface $timer): void
    {
        $this->cancelledDelays[] = (float) $timer->getInterval();
        $this->pendingOneShots = array_values(array_filter(
            $this->pendingOneShots,
            static fn (TimerInterface $candidate): bool => $candidate !== $timer,
        ));
        $this->pendingPeriodics = array_values(array_filter(
            $this->pendingPeriodics,
            static fn (TimerInterface $candidate): bool => $candidate !== $timer,
        ));
    }

    /**
     * Fire the earliest pending one-shot exactly as the loop would and drop it
     * from the pending set. Fails loud when nothing is armed — a missing rung
     * is a ladder bug, never a silent no-op.
     */
    public function fireNextOneShot(): void
    {
        $timer = array_shift($this->pendingOneShots);

        if ($timer === null) {
            throw new \LogicException('FakeClockLoop: no pending one-shot timer to fire.');
        }

        ($timer->getCallback())($timer);
    }

    /** @return int Number of one-shots currently armed and unfired. */
    public function pendingOneShotCount(): int
    {
        return count($this->pendingOneShots);
    }

    public function addReadStream($stream, $listener)
    {
        throw new \LogicException('FakeClockLoop: stream scheduling is outside unit-test scope.');
    }

    public function addWriteStream($stream, $listener)
    {
        throw new \LogicException('FakeClockLoop: stream scheduling is outside unit-test scope.');
    }

    public function removeReadStream($stream)
    {
        throw new \LogicException('FakeClockLoop: stream scheduling is outside unit-test scope.');
    }

    public function removeWriteStream($stream)
    {
        throw new \LogicException('FakeClockLoop: stream scheduling is outside unit-test scope.');
    }

    public function futureTick($listener)
    {
        throw new \LogicException('FakeClockLoop: tick queueing is outside unit-test scope.');
    }

    public function addSignal($signal, $listener)
    {
        throw new \LogicException('FakeClockLoop: signal handling is outside unit-test scope.');
    }

    public function removeSignal($signal, $listener)
    {
        throw new \LogicException('FakeClockLoop: signal handling is outside unit-test scope.');
    }

    public function run()
    {
        throw new \LogicException('FakeClockLoop: the test drives timers explicitly; run() must not be reached.');
    }

    public function stop()
    {
        throw new \LogicException('FakeClockLoop: the test drives timers explicitly; stop() must not be reached.');
    }
}
