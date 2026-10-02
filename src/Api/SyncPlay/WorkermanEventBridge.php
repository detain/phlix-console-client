<?php

/**
 * Workerman EventInterface adapter pumped by the React event loop.
 *
 * The interactive TUI (`bin/phlix run`) pumps a React LoopInterface, never
 * Workerman's own select loop. Workerman's AsyncTcpConnection::connect()
 * resolves its loop via Worker::getEventLoop() → Worker::$globalEvent, which
 * only runWatch-style setups populate. Without a pump, every SyncPlay WS dial
 * died with a TypeError swallowed by a discarded promise. This bridge
 * implements EventInterface by delegating every concern to the injected React
 * loop, making Workerman client sockets run on the loop the TUI already spins.
 */

declare(strict_types=1);

namespace Phlix\Console\Api\SyncPlay;

use LogicException;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use RuntimeException;
use Throwable;
use Workerman\Events\EventInterface;
use Workerman\Timer;
use Workerman\Worker;

/**
 * Bridges Workerman's event-loop contract onto a React event loop.
 *
 * Timer mapping: Workerman's single integer id space (delay() and repeat()
 * share one counter, mirroring Events\Select) over React TimerInterface
 * objects held in one map. Stream mapping keys by (int)$stream exactly like
 * Events\Select so a resource may carry one readable and one writable
 * listener at a time. Signals delegate to React's addSignal/removeSignal.
 *
 * run() intentionally throws: the React loop is owned by the SugarCraft
 * Program, which calls Loop::run() itself; a socket library must never start
 * the application loop from a callback. stop() by contrast must NEVER throw:
 * Workerman's shutdown path calls static::$globalEvent?->stop() immediately
 * BEFORE its exit (Worker.php:2087-2090), so a throwing stop() would make
 * exit() unreachable and strand the process in STATUS_SHUTDOWN (see stop()).
 * It detaches every bridge-owned registration and then refuses late work
 * loudly — it simply never halts the React loop itself.
 *
 * Behavioural notes vs Events\Select (v5.2.2):
 * - deleteAllTimer() is FAITHFUL to Select: Select::deleteAllTimer()
 *   (Select.php:376-381) only resets its scheduler/eventTimer tables without
 *   stopping the loop — stopping is the job of Select::stop() (:443-457),
 *   which calls deleteAllTimer(), exactly mirroring this class.
 * - Callbacks are wrapped in a safeCall mirroring Select's try/catch — one
 *   throwing handler must never kill the pump — but the fallback logs via
 *   error_log() instead of Select's echo, because stdout is the TUI canvas.
 * - installOnce() arms a watchdog error handler (watch-path parity with
 *   bin/phlix runWatch → Select::setErrorHandler): a throwing dispatch is
 *   error_log'd in full and the bridge stop()s, so the pump drains instead
 *   of spinning on. A bare bridge (manual construction, unit tests) keeps
 *   the log-only safeCall fallback.
 */
final class WorkermanEventBridge implements EventInterface
{
    /** @var array<int, TimerInterface> React timers keyed by Workerman-style id. */
    private array $timers = [];

    /** @var int Monotonic timer-id counter starting at 1, mirroring Events\Select. */
    private int $nextTimerId = 1;

    /** @var array<int, resource> Readable streams currently registered with React. */
    private array $readStreams = [];

    /** @var array<int, resource> Writable streams currently registered with React. */
    private array $writeStreams = [];

    /** @var array<int, callable> Signal listeners currently registered with React. */
    private array $signals = [];

    /** @var callable(\Throwable): mixed|null safeCall dispatch target; installOnce() arms pumpWatchdog() here. */
    private $errorHandler = null;

    /** @var bool Set by stop(); every later registration attempt is refused loudly. */
    private bool $stopped = false;

    public function __construct(private readonly LoopInterface $loop)
    {
    }

    /**
     * Install this bridge as Workerman's global event loop exactly once.
     *
     * Idempotent and conservative: when a pump already exists — this bridge
     * from an earlier call, or a real Events\Select installed by the `watch`
     * command — the existing loop is respected and left untouched. Only a
     * null globalEvent (the interactive crash state) triggers installation.
     *
     * On first install it also wires Workerman's static Timer facade onto the
     * bridge (Timer::init), mirroring what bin/phlix does for the Select loop
     * in the watch path. Without it, vendor-internal Timer::add() calls (e.g.
     * AsyncTcpConnection::reconnect) fall through to the pcntl-alarm tick
     * path — which in a no-worker process throws outright and in any case
     * would install a SIGALRM handler the React pump never services.
     *
     * Finally it arms the pump watchdog (setErrorHandler), completing the
     * watch-path parity: bin/phlix gives its Select loop a handler that
     * "logs and stops cleanly"; without one here a throwing dispatch would
     * only reach safeCall's log-only fallback and the pump would keep
     * ticking. See pumpWatchdog().
     */
    public static function installOnce(LoopInterface $loop): void
    {
        if (Worker::$globalEvent !== null) {
            return;
        }

        $bridge = new self($loop);
        $bridge->setErrorHandler(self::pumpWatchdog($bridge));
        Worker::$globalEvent = $bridge;
        Timer::init($bridge);
    }

    /**
     * Watch-path error handler: log the throwable in full, then drain the
     * bridge via the existing stop() machinery — reuse, never re-invent.
     *
     * Why it matters (the diagnostics the zombie eats): the vendor funnel
     * ConnectionInterface::error() with no per-connection errorHandler calls
     * Worker::stopAll(250, $e). In an interactive process its child branch
     * first log()s the original through safeEcho, which dies on
     * feof(self::$outputStream) (Worker.php:2427 — runAll() never ran, so
     * $outputStream is null): the original exception is swallowed, stopAll
     * aborts before arming its exit watchdog, and the TUI keeps pumping in
     * STATUS_SHUTDOWN. Whatever finally reaches safeCall (the original on a
     * direct throw, or the vendor TypeError on that funnel) is error_log'd
     * here in full — class, message, file:line, stack — into error_log's
     * configured sink rather than stdout/stderr, which are the TUI canvas.
     *
     * Then stop(): the bridge's cancel-everything path (22faf00 law: it
     * detaches every timer/stream/signal and refuses late work loudly, and
     * it never throws — so the handler cannot re-enter safeCall, and a
     * repeated dispatch just re-logs and no-ops). The pump drains; nothing
     * broken re-fires.
     *
     * No exit() from interactive code: the SugarCraft Program owns the
     * process lifecycle and terminal teardown; a loop callback pulling the
     * plug would strand the TUI mid-frame — the watch path stops its own
     * loop rather than killing the process, and this mirrors that policy.
     */
    private static function pumpWatchdog(self $bridge): callable
    {
        return static function (Throwable $throwable) use ($bridge): void {
            error_log(
                'WorkermanEventBridge watchdog: unhandled error in pump-dispatched callback; draining the bridge. '
                . (string) $throwable
            );
            $bridge->stop();
        };
    }

    /**
     * Fail-loud stopguard for dial paths: Workerman client sockets resolve
     * their event loop from Worker::$globalEvent at connect() time, and a null
     * there means no pump exists for this process — the socket would die with
     * an opaque TypeError deep inside the vendor. Call before constructing any
     * AsyncTcpConnection outside a runWatch-managed process.
     *
     * @throws RuntimeException when no event pump is installed
     */
    public static function pumpOrThrow(): void
    {
        if (Worker::$globalEvent !== null) {
            return;
        }

        throw new RuntimeException(
            'SyncPlay WS requires the React-loop event bridge; interactive boot installs it — '
            . 'this call site bypassed App boot (no Workerman event pump is set for this process).'
        );
    }

    /**
     * @param list<mixed> $args forwarded positionally, as Events\Select does
     */
    public function delay(float $delay, callable $func, array $args = []): int
    {
        return $this->scheduleTimer($delay, $func, $args, periodic: false);
    }

    public function offDelay(int $timerId): bool
    {
        return $this->cancelTimer($timerId);
    }

    /**
     * @param list<mixed> $args forwarded positionally, as Events\Select does
     */
    public function repeat(float $interval, callable $func, array $args = []): int
    {
        return $this->scheduleTimer($interval, $func, $args, periodic: true);
    }

    /**
     * Workerman's Select routes offRepeat into the same single timer table;
     * this bridge keeps one map as well, so both offsets share one cancel path.
     */
    public function offRepeat(int $timerId): bool
    {
        return $this->cancelTimer($timerId);
    }

    public function onReadable($stream, callable $func): void
    {
        $this->refuseLateWork('onReadable()');

        $key = (int) $stream;
        if (isset($this->readStreams[$key])) {
            $this->loop->removeReadStream($stream);
        }

        $this->readStreams[$key] = $stream;
        $this->loop->addReadStream($stream, function ($reactStream) use ($func): void {
            $this->safeCall($func, [$reactStream]);
        });
    }

    public function offReadable($stream): bool
    {
        $key = (int) $stream;
        if (!isset($this->readStreams[$key])) {
            return false;
        }

        unset($this->readStreams[$key]);
        $this->loop->removeReadStream($stream);

        return true;
    }

    public function onWritable($stream, callable $func): void
    {
        $this->refuseLateWork('onWritable()');

        $key = (int) $stream;
        if (isset($this->writeStreams[$key])) {
            $this->loop->removeWriteStream($stream);
        }

        $this->writeStreams[$key] = $stream;
        $this->loop->addWriteStream($stream, function ($reactStream) use ($func): void {
            $this->safeCall($func, [$reactStream]);
        });
    }

    public function offWritable($stream): bool
    {
        $key = (int) $stream;
        if (!isset($this->writeStreams[$key])) {
            return false;
        }

        unset($this->writeStreams[$key]);
        $this->loop->removeWriteStream($stream);

        return true;
    }

    public function onSignal(int $signal, callable $func): void
    {
        $this->refuseLateWork('onSignal()');

        if (isset($this->signals[$signal])) {
            $this->loop->removeSignal($signal, $this->signals[$signal]);
        }

        // React's removeSignal demands the identical callable, so the map
        // stores the wrapper, not the raw Workerman callback.
        $listener = function (int $raised) use ($func): void {
            $this->safeCall($func, [$raised]);
        };

        $this->signals[$signal] = $listener;
        $this->loop->addSignal($signal, $listener);
    }

    public function offSignal(int $signal): bool
    {
        if (!isset($this->signals[$signal])) {
            return false;
        }

        $listener = $this->signals[$signal];
        unset($this->signals[$signal]);
        $this->loop->removeSignal($signal, $listener);

        return true;
    }

    public function deleteAllTimer(): void
    {
        foreach (array_keys($this->timers) as $timerId) {
            $this->cancelTimer($timerId);
        }
    }

    /**
     * @throws RuntimeException Always — the React loop is driven by the
     *                          SugarCraft Program, not by Workerman internals.
     */
    public function run(): void
    {
        throw new RuntimeException(
            'WorkermanEventBridge does not own the event loop; the React loop is pumped by the application.'
        );
    }

    /**
     * Detaches everything the bridge owns and refuses late work — but never
     * throws and never halts the React loop itself.
     *
     * Why stop() must be non-throwing: Workerman's shutdown sequence calls
     * static::$globalEvent?->stop() (Worker.php:2087) immediately BEFORE its
     * exit() (Worker.php:2088-2090) inside a Timer::repeat(0.01) callback
     * armed by Worker::stopAll()'s child branch (Worker.php:2065-2098). A
     * throw here is caught by the bridge's own safeCall and error_log'd on
     * every 10 ms tick, exit() stays unreachable, and the process rots as a
     * zombie STATUS_SHUTDOWN TUI flooding the log. Pre-bridge Select::stop()
     * (Select.php:443-457) simply cancelled and returned, letting exit() run.
     *
     * Idempotent: a second stop() is a no-op. Any registration attempted
     * after stop() throws LogicException — the loop is winding down, so late
     * work fails loud instead of silently leaking onto a detached loop.
     */
    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }

        $this->stopped = true;

        foreach (array_keys($this->timers) as $timerId) {
            $this->cancelTimer($timerId);
        }

        foreach ($this->readStreams as $stream) {
            $this->loop->removeReadStream($stream);
        }
        $this->readStreams = [];

        foreach ($this->writeStreams as $stream) {
            $this->loop->removeWriteStream($stream);
        }
        $this->writeStreams = [];

        foreach ($this->signals as $signal => $listener) {
            $this->loop->removeSignal($signal, $listener);
        }
        $this->signals = [];
    }

    public function getTimerCount(): int
    {
        return count($this->timers);
    }

    public function setErrorHandler(callable $errorHandler): void
    {
        $this->errorHandler = $errorHandler;
    }

    /**
     * Fail-loud guard for registrations attempted after stop(): the loop is
     * winding down, so late work would leak onto a loop nothing pumps for it
     * anymore. Throw instead of accepting the silence.
     *
     * @throws LogicException When stop() has already been called.
     */
    private function refuseLateWork(string $what): void
    {
        if ($this->stopped) {
            throw new LogicException(
                'WorkermanEventBridge refuses late ' . $what . ' after stop(); the event loop is winding down.'
            );
        }
    }

    /**
     * @param list<mixed> $args
     */
    private function scheduleTimer(float $delay, callable $func, array $args, bool $periodic): int
    {
        $this->refuseLateWork($periodic ? 'repeat()' : 'delay()');

        $timerId = $this->nextTimerId++;
        // React hands the Timer object to callbacks; Workerman callbacks take
        // only the stored args, so the wrapper discards React's argument.
        // One-shots also self-forget on fire — Select drops fired delays from
        // its table, and offDelay(id) after the fire must report false.
        $callback = function () use ($func, $args, $timerId, $periodic): void {
            if (!$periodic) {
                unset($this->timers[$timerId]);
            }

            $this->safeCall($func, $args);
        };

        $timer = $periodic
            ? $this->loop->addPeriodicTimer($delay, $callback)
            : $this->loop->addTimer($delay, $callback);

        $this->timers[$timerId] = $timer;

        return $timerId;
    }

    private function cancelTimer(int $timerId): bool
    {
        $timer = $this->timers[$timerId] ?? null;
        if ($timer === null) {
            return false;
        }

        unset($this->timers[$timerId]);
        $this->loop->cancelTimer($timer);

        return true;
    }

    /**
     * Mirror of Events\Select::safeCall — one throwing handler must never
     * kill the shared pump. Fallback goes to error_log rather than Select's
     * echo because stdout renders the TUI.
     *
     * @param array<int, mixed> $args
     */
    private function safeCall(callable $func, array $args): void
    {
        try {
            $func(...$args);
        } catch (Throwable $throwable) {
            $handler = $this->errorHandler;
            if ($handler !== null) {
                $handler($throwable);

                return;
            }

            error_log((string) $throwable);
        }
    }
}
