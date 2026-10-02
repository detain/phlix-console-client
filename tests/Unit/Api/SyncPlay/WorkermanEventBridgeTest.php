<?php

declare(strict_types=1);

/**
 * Delegation and installation proofs for the Workerman→React event bridge.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Tests\Unit\Api\SyncPlay;

use LogicException;
use Phlix\Console\Api\SyncPlay\WorkermanEventBridge;
use Phlix\Console\Tests\Api\RecordingEventLoop;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use Workerman\Events\EventInterface;
use Workerman\Events\Select;
use Workerman\Timer;
use Workerman\Worker;

/**
 * Pins the bridge contract that fixed the interactive TUI: every Workerman
 * EventInterface concern (timers, streams, signals) must land on the React
 * loop with exact values, installation must be once-only and non-clobbering,
 * and the pump stopguard must fail loud where a TypeError used to die
 * silently. No Workerman Select is ever touched by the delegation tests.
 */
final class WorkermanEventBridgeTest extends TestCase
{
    private RecordingEventLoop $loop;

    private WorkermanEventBridge $bridge;

    private ?EventInterface $globalEventBefore = null;

    protected function setUp(): void
    {
        $this->globalEventBefore = Worker::$globalEvent;
        $this->loop = new RecordingEventLoop();
        $this->bridge = new WorkermanEventBridge($this->loop);

        // Isolate the process-wide Timer facade: installOnce writes through
        // it, and a leaked static would reroute other tests' Timer::add.
        $this->writeTimerEvent(null);
    }

    protected function tearDown(): void
    {
        Worker::$globalEvent = $this->globalEventBefore;
        $this->writeTimerEvent(null);
    }

    // ---- timer delegation ----------------------------------------------------

    public function testDelayDelegatesToAddTimerWithExactInterval(): void
    {
        $timerId = $this->bridge->delay(2.5, static function (): void {});

        self::assertSame(1, $timerId, 'Workerman timer ids start at 1 (Events\Select parity)');
        self::assertSame([2.5], $this->loop->oneShotIntervals);
    }

    public function testRepeatDelegatesToAddPeriodicTimerWithExactInterval(): void
    {
        $timerId = $this->bridge->repeat(0.7, static function (): void {});

        self::assertSame(1, $timerId);
        self::assertSame([0.7], $this->loop->periodicIntervals);
    }

    public function testDelayAndRepeatShareOneMonotonicIdSpace(): void
    {
        self::assertSame(1, $this->bridge->delay(1.0, static function (): void {}));
        self::assertSame(2, $this->bridge->repeat(2.0, static function (): void {}));
        self::assertSame(3, $this->bridge->delay(3.0, static function (): void {}));
    }

    public function testFiredOneShotReceivesTheStoredWorkermanArgs(): void
    {
        $received = null;
        $this->bridge->delay(1.0, static function (string $first, int $second) use (&$received): void {
            $received = [$first, $second];
        }, ['a', 7]);

        // Fake-loop parity: the loop hands the Timer object to its callback —
        // the wrapper must translate that back to the stored Workerman args.
        $this->loop->fireNextOneShot();

        self::assertSame(['a', 7], $received);
        self::assertSame(0, $this->bridge->getTimerCount(), 'a fired one-shot leaves the table');
    }

    public function testPeriodicRepeatsUntilOffRepeatStopsIt(): void
    {
        $fired = 0;
        $timerId = $this->bridge->repeat(0.5, static function () use (&$fired): void {
            $fired++;
        });

        $this->loop->firePeriodic(0, times: 2);
        self::assertSame(2, $fired);

        self::assertTrue($this->bridge->offRepeat($timerId));
        self::assertSame([0.5], $this->loop->cancelledIntervals);
        self::assertSame([], $this->loop->pendingPeriodics, 'cancel reached the React loop');
    }

    public function testOffDelayOffRepeatShareOneTableAndReportTruthfully(): void
    {
        $oneShot = $this->bridge->delay(1.0, static function (): void {});
        $periodic = $this->bridge->repeat(2.0, static function (): void {});

        self::assertTrue($this->bridge->offDelay($oneShot));
        self::assertFalse($this->bridge->offDelay($oneShot), 'double-cancel must report false');
        self::assertTrue($this->bridge->offRepeat($periodic), 'offRepeat looks up the shared table');
        self::assertFalse($this->bridge->offDelay(99), 'unknown ids report false, never throw');
    }

    public function testDeleteAllTimerCancelsEverythingWithoutStoppingTheLoop(): void
    {
        $this->bridge->delay(1.0, static function (): void {});
        $this->bridge->delay(2.0, static function (): void {});
        $this->bridge->repeat(3.0, static function (): void {});
        self::assertSame(3, $this->bridge->getTimerCount());

        // FAITHFUL to Select: Select::deleteAllTimer() (Select.php:376-381)
        // only resets its scheduler/eventTimer tables — it never stops the
        // loop (stopping is Select::stop()'s job, which calls deleteAllTimer
        // first). loop->run/loop->stop throw LogicException in the double,
        // proving the bridge halts nothing here either.
        $this->bridge->deleteAllTimer();

        self::assertSame(0, $this->bridge->getTimerCount());
        self::assertSame([1.0, 2.0, 3.0], $this->loop->cancelledIntervals);
    }

    // ---- stream delegation ---------------------------------------------------

    public function testOnReadableRegistersWithReactAndDeliversTheStream(): void
    {
        $stream = fopen('php://temp', 'rb');
        self::assertIsResource($stream);

        $received = null;
        $this->bridge->onReadable($stream, static function ($s) use (&$received): void {
            $received = $s;
        });

        self::assertArrayHasKey((int) $stream, $this->loop->readListeners);
        $this->loop->fireReadable($stream);
        self::assertSame($stream, $received, 'React passes the stream back — the bridge forwards it verbatim');

        self::assertTrue($this->bridge->offReadable($stream));
        self::assertSame([(int) $stream], $this->loop->readRemovals);
        self::assertFalse($this->bridge->offReadable($stream), 'second removal reports false');
    }

    public function testOnWritableRegistersWithReactAndDeliversTheStream(): void
    {
        $stream = fopen('php://temp', 'wb');
        self::assertIsResource($stream);

        $received = null;
        $this->bridge->onWritable($stream, static function ($s) use (&$received): void {
            $received = $s;
        });

        self::assertArrayHasKey((int) $stream, $this->loop->writeListeners);
        $this->loop->fireWritable($stream);
        self::assertSame($stream, $received);

        self::assertTrue($this->bridge->offWritable($stream));
        self::assertFalse($this->bridge->offWritable($stream));
    }

    public function testReRegisteringAStreamRemovesThePreviousReactListenerFirst(): void
    {
        $stream = fopen('php://temp', 'rb');
        self::assertIsResource($stream);

        $first = 0;
        $second = 0;
        $this->bridge->onReadable($stream, static function () use (&$first): void {
            $first++;
        });
        $this->bridge->onReadable($stream, static function () use (&$second): void {
            $second++;
        });

        self::assertSame([(int) $stream], $this->loop->readRemovals, 'replacement must unregister React-side first — no zombie listener');
        $this->loop->fireReadable($stream);
        self::assertSame(0, $first);
        self::assertSame(1, $second);
    }

    // ---- signal delegation ---------------------------------------------------

    public function testSignalRoundTripPassesTheIdenticalListenerToRemoveSignal(): void
    {
        // React's removeSignal contract is identity: it only detaches when the
        // callable is the one that was added. The bridge must store its wrapper,
        // not the raw Workerman callback.
        $raised = null;
        $this->bridge->onSignal(12, static function (int $signal) use (&$raised): void {
            $raised = $signal;
        });

        $listener = $this->loop->signalListeners[12];
        $this->loop->fireSignal(12);
        self::assertSame(12, $raised);

        self::assertTrue($this->bridge->offSignal(12));
        self::assertCount(1, $this->loop->signalRemovals);
        self::assertSame([12, $listener], $this->loop->signalRemovals[0]);
        self::assertFalse($this->bridge->offSignal(12));
    }

    public function testReregisteringASignalReplacesTheReactListener(): void
    {
        $this->bridge->onSignal(15, static function (): void {});
        $this->bridge->onSignal(15, static function (): void {});

        self::assertCount(1, $this->loop->signalRemovals, 'replacement must detach the previous listener');
        self::assertCount(1, $this->loop->signalListeners);
    }

    // ---- safeCall semantics ----------------------------------------------------

    public function testThrowingTimerCallbackGoesToTheErrorHandlerNotThePump(): void
    {
        $captured = null;
        $this->bridge->setErrorHandler(static function (Throwable $e) use (&$captured): void {
            $captured = $e;
        });

        $this->bridge->delay(1.0, static function (): void {
            throw new RuntimeException('handler exploded');
        });

        // A throw escaping here would kill the React timer dispatch — i.e. the
        // whole TUI. The safeCall mirror must absorb it instead.
        $this->loop->fireNextOneShot();

        self::assertInstanceOf(Throwable::class, $captured);
        self::assertSame('handler exploded', $captured->getMessage());
    }

    public function testThrowingStreamListenerIsRoutedToo(): void
    {
        $stream = fopen('php://temp', 'rb');
        self::assertIsResource($stream);

        $captured = null;
        $this->bridge->setErrorHandler(static function (Throwable $e) use (&$captured): void {
            $captured = $e;
        });
        $this->bridge->onReadable($stream, static function (): void {
            throw new RuntimeException('socket exploded');
        });

        $this->loop->fireReadable($stream);

        self::assertSame('socket exploded', $captured?->getMessage());
    }

    // ---- loop ownership ---------------------------------------------------------

    public function testRunRefusesToOwnTheLoop(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not own the event loop');

        $this->bridge->run();
    }

    /**
     * stop() must NEVER throw. This assertion was previously the inverse
     * ('stop also refuses') — that pinned the design the Worker.php:2087
     * shutdown-ordering fix retires: Workerman calls globalEvent->stop()
     * immediately before exit(), so a throw here strands the process.
     */
    public function testStopNeverThrowsAndDetachesOwnedTimers(): void
    {
        $noop = static function (): void {
            // Never fires: registration is the subject, not invocation.
        };
        $this->bridge->repeat(0.01, $noop);

        $this->bridge->stop();
        $this->bridge->stop();

        self::assertSame(0, $this->bridge->getTimerCount(), 'stop() detached the bridge-owned timer');
        self::assertSame([], $this->loop->pendingPeriodics, 'and the React loop holds nothing pending');
    }

    public function testStopCancelsEveryOwnedRegistrationWithoutTouchingTheLoop(): void
    {
        $read = fopen('php://temp', 'rb');
        $write = fopen('php://temp', 'wb');
        self::assertIsResource($read);
        self::assertIsResource($write);

        $noop = static function (): void {
            // Never fires: stop() must detach before any callback runs.
        };
        $this->bridge->delay(1.0, $noop);
        $this->bridge->repeat(0.5, $noop);
        $this->bridge->onReadable($read, $noop);
        $this->bridge->onWritable($write, $noop);
        $this->bridge->onSignal(12, $noop);

        $this->bridge->stop();

        self::assertSame(0, $this->bridge->getTimerCount());
        self::assertSame([1.0, 0.5], $this->loop->cancelledIntervals, 'every timer cancelled in table order');
        self::assertSame([], $this->loop->pendingOneShots);
        self::assertSame([], $this->loop->pendingPeriodics);
        self::assertSame([], $this->loop->readListeners);
        self::assertSame([], $this->loop->writeListeners);
        self::assertSame([], $this->loop->signalListeners);
        self::assertSame([(int) $read], $this->loop->readRemovals);
        self::assertSame([(int) $write], $this->loop->writeRemovals);
        self::assertCount(1, $this->loop->signalRemovals);
        // The loop itself is untouched: run/stop on the double would throw
        // LogicException — reaching them here would fail the test loudly.
    }

    public function testStopIsIdempotentAndDetachesOnlyOnce(): void
    {
        $read = fopen('php://temp', 'rb');
        self::assertIsResource($read);

        $noop = static function (): void {
            // Never fires: only detach bookkeeping is asserted.
        };
        $this->bridge->repeat(0.25, $noop);
        $this->bridge->onReadable($read, $noop);
        $this->bridge->onSignal(15, $noop);

        $this->bridge->stop();
        $this->bridge->stop();

        self::assertSame([0.25], $this->loop->cancelledIntervals, 'second stop() cancels nothing again');
        self::assertSame([(int) $read], $this->loop->readRemovals);
        self::assertCount(1, $this->loop->signalRemovals);
    }

    /**
     * The actual failure mode: Worker::stopAll()'s child branch arms
     * Timer::repeat(0.01, cb) (Worker.php:2097) whose cb calls
     * globalEvent->stop() (Worker.php:2087) BEFORE exit (Worker.php:2088-2090).
     * This reproduces that sequence with the exit() call replaced by an
     * assignment marker — PHPUnit cannot exit the runner. A throwing stop()
     * is swallowed by safeCall, the marker is never set, and the repeat keeps
     * flooding: exactly the zombie-TUI defect, now pinned red on regression.
     */
    public function testStopAllChildBranchSequenceReachesExitWhenFiredThroughTheBridge(): void
    {
        Worker::$globalEvent = $this->bridge;

        $fireCount = 0;
        $exitCode = null;
        $this->bridge->repeat(0.01, static function () use (&$fireCount, &$exitCode): void {
            $fireCount++;
            // Worker.php:2087 — stop-before-exit ordering verbatim.
            Worker::$globalEvent?->stop();
            // Worker.php:2088-2090 — the exit($code) this lane must keep reachable.
            $exitCode = 250;
        });

        // One tick of the vendor's 0.01s watchdog.
        $this->loop->firePeriodic(0);

        self::assertSame(250, $exitCode, 'a throwing stop() would be swallowed by safeCall and strand exit()');
        self::assertSame(1, $fireCount);
        self::assertSame([], $this->loop->pendingPeriodics, 'self-cancel froze the repeat from firing again');
        self::assertSame(0, $this->bridge->getTimerCount());
    }

    public function testLateWorkAfterStopIsRefusedLoudly(): void
    {
        $this->bridge->stop();

        $stream = fopen('php://temp', 'rb');
        self::assertIsResource($stream);

        $noop = static function (): void {
            // Never runs: every late registration below must throw first.
        };

        $lateCalls = [
            'delay()' => fn () => $this->bridge->delay(1.0, $noop),
            'repeat()' => fn () => $this->bridge->repeat(1.0, $noop),
            'onReadable()' => fn () => $this->bridge->onReadable($stream, $noop),
            'onWritable()' => fn () => $this->bridge->onWritable($stream, $noop),
            'onSignal()' => fn () => $this->bridge->onSignal(12, $noop),
        ];

        foreach ($lateCalls as $label => $call) {
            try {
                $call();
                self::fail("late {$label} after stop() must throw LogicException, not leak onto a dead loop");
            } catch (LogicException $e) {
                self::assertStringContainsString($label, $e->getMessage());
                self::assertStringContainsString('after stop()', $e->getMessage());
            }
        }

        self::assertSame(0, $this->bridge->getTimerCount(), 'refusals registered nothing');
        self::assertSame([], $this->loop->readListeners);
        self::assertSame([], $this->loop->writeListeners);
        self::assertSame([], $this->loop->signalListeners);
    }

    /**
     * Finding 2 vendor-truth pin: Select::deleteAllTimer() does NOT stop its
     * loop (Select.php:376-381 resets only the scheduler/eventTimer tables);
     * stopping belongs to Select::stop() (:443-457), which calls
     * deleteAllTimer(). The bridge's deleteAllTimer() is faithful to this, so
     * the old 'documented divergence' docblock claim was corrected — this test
     * fails the moment vendor behavior changes under the pinned coordinates.
     */
    public function testSelectDeleteAllTimerNeverStopsItsLoopPinningTheDocblockTruth(): void
    {
        $select = new Select();
        $select->repeat(10.0, static function (): void {
            // Never fires: deleteAllTimer() runs before any tick.
        });

        $select->deleteAllTimer();

        self::assertSame(0, $select->getTimerCount());
        $running = new \ReflectionProperty(Select::class, 'running');
        self::assertTrue(
            $running->getValue($select),
            'Select::deleteAllTimer() must leave the loop running (Select.php:376-381)'
        );
    }

    // ---- installation -------------------------------------------------------------

    public function testInstallOnceSetsBridgeAndWiresTheTimerFacade(): void
    {
        Worker::$globalEvent = null;

        WorkermanEventBridge::installOnce($this->loop);

        self::assertInstanceOf(WorkermanEventBridge::class, Worker::$globalEvent);
        self::assertSame(Worker::$globalEvent, $this->readTimerEvent(), 'Timer::add must route through the same pump');
    }

    public function testInstallOnceIsIdempotentAndNeverStacksBridges(): void
    {
        Worker::$globalEvent = null;

        WorkermanEventBridge::installOnce($this->loop);
        $first = Worker::$globalEvent;
        WorkermanEventBridge::installOnce(new RecordingEventLoop());

        self::assertSame($first, Worker::$globalEvent, 'a second boot keeps the first bridge — no stacking');
    }

    public function testInstallOnceNeverClobbersTheWatchCommandSelect(): void
    {
        $select = new Select();
        Worker::$globalEvent = $select;

        WorkermanEventBridge::installOnce($this->loop);

        self::assertSame($select, Worker::$globalEvent, 'runWatch owns its loop; the bridge must stand down');
        self::assertNull($this->readTimerEvent(), 'standing down must not touch the Timer facade either');
    }

    // ---- stopguard ------------------------------------------------------------------

    public function testPumpOrThrowRejectsNullPumpWithActionableMessage(): void
    {
        Worker::$globalEvent = null;

        try {
            WorkermanEventBridge::pumpOrThrow();
            self::fail('expected the loud RuntimeException');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('React-loop event bridge', $e->getMessage());
            self::assertStringContainsString('bypassed App boot', $e->getMessage());
        }
    }

    public function testPumpOrThrowAcceptsTheBridgeAndASelectPump(): void
    {
        Worker::$globalEvent = $this->bridge;
        WorkermanEventBridge::pumpOrThrow();

        Worker::$globalEvent = new Select();
        WorkermanEventBridge::pumpOrThrow();

        $this->expectNotToPerformAssertions();
    }

    private function readTimerEvent(): ?EventInterface
    {
        $property = new \ReflectionProperty(Timer::class, 'event');

        return $property->getValue();
    }

    private function writeTimerEvent(?EventInterface $event): void
    {
        $property = new \ReflectionProperty(Timer::class, 'event');
        $property->setValue(null, $event);
    }
}
