<?php

declare(strict_types=1);

/**
 * Full-coverage recording LoopInterface double for the Workerman→React bridge
 * delegation proofs ({@see \Phlix\Console\Api\SyncPlay\WorkermanEventBridge}).
 *
 * Sibling of {@see FakeClockLoop} — which deliberately REFUSES stream/signal
 * work to keep the reconnect-ladder tests inside their unit boundary. The
 * bridge's whole contract is stream and signal delegation, so it needs a loop
 * that records everything instead of throwing.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Tests\Api;

use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\Timer;
use React\EventLoop\TimerInterface;

/**
 * Records every React-level registration the bridge delegates, with fire
 * helpers so tests can drive callbacks exactly as the real loop would.
 */
final class RecordingEventLoop implements LoopInterface
{
    /** @var list<float> Every one-shot delay ever armed, in arming order. */
    public array $oneShotIntervals = [];

    /** @var list<float> Every periodic interval ever armed, in arming order. */
    public array $periodicIntervals = [];

    /** @var list<float> Intervals of timers passed to cancelTimer(), in order. */
    public array $cancelledIntervals = [];

    /** @var list<TimerInterface> One-shots armed and not yet fired or cancelled. */
    public array $pendingOneShots = [];

    /** @var list<TimerInterface> Periodics armed and not yet cancelled. */
    public array $pendingPeriodics = [];

    /** @var array<int, callable> Read listeners keyed by (int)$stream. */
    public array $readListeners = [];

    /** @var array<int, callable> Write listeners keyed by (int)$stream. */
    public array $writeListeners = [];

    /** @var list<int> Streams passed to removeReadStream(), in order. */
    public array $readRemovals = [];

    /** @var list<int> Streams passed to removeWriteStream(), in order. */
    public array $writeRemovals = [];

    /** @var array<int, callable> Signal listeners keyed by signal number. */
    public array $signalListeners = [];

    /** @var list<array{int, callable}> (signal, listener) pairs passed to removeSignal(). */
    public array $signalRemovals = [];

    public function addTimer($interval, $callback): TimerInterface
    {
        $timer = new Timer($interval, $callback, false);
        $this->oneShotIntervals[] = (float) $interval;
        $this->pendingOneShots[] = $timer;

        return $timer;
    }

    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        $timer = new Timer($interval, $callback, true);
        $this->periodicIntervals[] = (float) $interval;
        $this->pendingPeriodics[] = $timer;

        return $timer;
    }

    public function cancelTimer(TimerInterface $timer): void
    {
        $this->cancelledIntervals[] = (float) $timer->getInterval();
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
     * Fire the earliest pending one-shot exactly as the loop would (passing
     * the Timer object, which the bridge wrapper must ignore).
     */
    public function fireNextOneShot(): void
    {
        $timer = array_shift($this->pendingOneShots);
        if ($timer === null) {
            throw new \LogicException('RecordingEventLoop: no pending one-shot timer to fire.');
        }

        ($timer->getCallback())($timer);
    }

    /**
     * Invoke a pending periodic N times without removing it (persistent),
     * exactly as the loop re-arms it every tick.
     */
    public function firePeriodic(int $index, int $times = 1): void
    {
        $timer = $this->pendingPeriodics[$index] ?? null;
        if ($timer === null) {
            throw new \LogicException("RecordingEventLoop: no pending periodic at index {$index}.");
        }

        for ($i = 0; $i < $times; $i++) {
            ($timer->getCallback())($timer);
        }
    }

    public function fireReadable($stream): void
    {
        $listener = $this->readListeners[(int) $stream] ?? null;
        if ($listener === null) {
            throw new \LogicException('RecordingEventLoop: stream is not registered readable.');
        }

        $listener($stream);
    }

    public function fireWritable($stream): void
    {
        $listener = $this->writeListeners[(int) $stream] ?? null;
        if ($listener === null) {
            throw new \LogicException('RecordingEventLoop: stream is not registered writable.');
        }

        $listener($stream);
    }

    public function fireSignal(int $signal): void
    {
        $listener = $this->signalListeners[$signal] ?? null;
        if ($listener === null) {
            throw new \LogicException("RecordingEventLoop: no listener registered for signal {$signal}.");
        }

        $listener($signal);
    }

    public function addReadStream($stream, $listener)
    {
        $this->readListeners[(int) $stream] = $listener;
    }

    public function addWriteStream($stream, $listener)
    {
        $this->writeListeners[(int) $stream] = $listener;
    }

    public function removeReadStream($stream)
    {
        $this->readRemovals[] = (int) $stream;
        unset($this->readListeners[(int) $stream]);
    }

    public function removeWriteStream($stream)
    {
        $this->writeRemovals[] = (int) $stream;
        unset($this->writeListeners[(int) $stream]);
    }

    public function futureTick($listener)
    {
        throw new \LogicException('RecordingEventLoop: tick queueing is outside unit-test scope.');
    }

    public function addSignal($signal, $listener)
    {
        $this->signalListeners[$signal] = $listener;
    }

    public function removeSignal($signal, $listener)
    {
        $this->signalRemovals[] = [$signal, $listener];
        unset($this->signalListeners[$signal]);
    }

    public function run()
    {
        throw new \LogicException('RecordingEventLoop: the test drives callbacks explicitly; run() must not be reached.');
    }

    public function stop()
    {
        throw new \LogicException('RecordingEventLoop: the test drives callbacks explicitly; stop() must not be reached.');
    }
}
