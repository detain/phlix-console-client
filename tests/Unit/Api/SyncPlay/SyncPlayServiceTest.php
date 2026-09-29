<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit\Api\SyncPlay;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\SyncPlayPlaybackCommand;
use Phlix\Console\Api\Dto\SyncPlayUser;
use Phlix\Console\Api\SyncPlay\Messages;
use Phlix\Console\Api\SyncPlay\SyncPlayService;
use Phlix\Console\Tests\Api\FakeClockLoop;
use Phlix\Console\Tests\Api\FakeTransport;
use Phlix\Console\Tests\Api\RecordingConnection;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\AsyncTcpConnection;

/**
 * Tests the SyncPlayService callback registration and invocation, plus the
 * C6 reconnect ladder (capped 1s×2ⁿ dialing, budget resets, cancellation
 * and stale-socket guards) driven through the injected loop seam.
 */
final class SyncPlayServiceTest extends TestCase
{
    public function testOnPlaybackCommandCallbackIsInvoked(): void
    {
        $api = new ApiClient('https://srv', new FakeTransport());
        $service = new SyncPlayService($api);

        $receivedCommand = null;
        $service->onPlaybackCommand(function (SyncPlayPlaybackCommand $cmd) use (&$receivedCommand): void {
            $receivedCommand = $cmd;
        });

        // Simulate the callback being invoked with a playback command
        $command = SyncPlayPlaybackCommand::fromArray([
            'type' => 'play',
            'position' => 5000,
            'server_time' => time() * 1000,
        ]);

        // Access the private onPlaybackCommand property and invoke it
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('onPlaybackCommand');
        $property->setAccessible(true);
        /** @var \Closure $callback */
        $callback = $property->getValue($service);
        $callback($command);

        $this->assertInstanceOf(SyncPlayPlaybackCommand::class, $receivedCommand);
        $this->assertSame('play', $receivedCommand->type);
        $this->assertSame(5000, $receivedCommand->position);
    }

    public function testOnMemberJoinedCallbackIsInvoked(): void
    {
        $api = new ApiClient('https://srv', new FakeTransport());
        $service = new SyncPlayService($api);

        $receivedUser = null;
        $service->onMemberJoined(function (SyncPlayUser $user) use (&$receivedUser): void {
            $receivedUser = $user;
        });

        $user = new SyncPlayUser('member-123', 'Alice', false);

        // Access the private onMemberJoined property and invoke it
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('onMemberJoined');
        $property->setAccessible(true);
        /** @var \Closure $callback */
        $callback = $property->getValue($service);
        $callback($user);

        $this->assertInstanceOf(SyncPlayUser::class, $receivedUser);
        $this->assertSame('member-123', $receivedUser->sessionId);
        $this->assertSame('Alice', $receivedUser->displayName);
    }

    public function testOnMemberLeftCallbackIsInvoked(): void
    {
        $api = new ApiClient('https://srv', new FakeTransport());
        $service = new SyncPlayService($api);

        $receivedMemberId = null;
        $service->onMemberLeft(function (string $memberId) use (&$receivedMemberId): void {
            $receivedMemberId = $memberId;
        });

        // Access the private _onMemberLeft property and invoke it
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('_onMemberLeft');
        $property->setAccessible(true);
        /** @var \Closure $callback */
        $callback = $property->getValue($service);
        $callback('member-456');

        $this->assertSame('member-456', $receivedMemberId);
    }

    public function testOnHostChangedCallbackIsInvoked(): void
    {
        $api = new ApiClient('https://srv', new FakeTransport());
        $service = new SyncPlayService($api);

        $receivedHostId = null;
        $service->onHostChanged(function (string $newHostId) use (&$receivedHostId): void {
            $receivedHostId = $newHostId;
        });

        // Access the private onHostChanged property and invoke it
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('onHostChanged');
        $property->setAccessible(true);
        /** @var \Closure $callback */
        $callback = $property->getValue($service);
        $callback('new-host-789');

        $this->assertSame('new-host-789', $receivedHostId);
    }

    public function testOnDisconnectCallbackIsInvoked(): void
    {
        $api = new ApiClient('https://srv', new FakeTransport());
        $service = new SyncPlayService($api);

        $receivedWasIntentional = null;
        $service->onDisconnect(function (bool $wasIntentional) use (&$receivedWasIntentional): void {
            $receivedWasIntentional = $wasIntentional;
        });

        // Access the private onDisconnect property and invoke it
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('onDisconnect');
        $property->setAccessible(true);
        /** @var \Closure $callback */
        $callback = $property->getValue($service);
        $callback(true);

        $this->assertTrue($receivedWasIntentional);
    }

    public function testOnErrorCallbackIsInvoked(): void
    {
        $api = new ApiClient('https://srv', new FakeTransport());
        $service = new SyncPlayService($api);

        $receivedCode = null;
        $receivedMessage = null;
        $service->onError(function (string $code, string $message) use (&$receivedCode, &$receivedMessage): void {
            $receivedCode = $code;
            $receivedMessage = $message;
        });

        // Access the private onError property and invoke it
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('onError');
        $property->setAccessible(true);
        /** @var \Closure $callback */
        $callback = $property->getValue($service);
        $callback('test_error', 'Something went wrong');

        $this->assertSame('test_error', $receivedCode);
        $this->assertSame('Something went wrong', $receivedMessage);
    }

    // ---- C6 reconnect ladder (fake-clock pins) ------------------------------

    public function testLadderDoublesExactDelaysAndExhaustsLoudAfterFiveRungs(): void
    {
        $harness = $this->createLadderHarness();
        $harness['join']();

        $harness['closeLive']();
        for ($rung = 1; $rung <= 5; $rung++) {
            self::assertSame(
                1,
                $harness['clock']->pendingOneShotCount(),
                sprintf('exactly one rung may be armed at a time (before firing rung %d)', $rung),
            );
            self::assertSame($rung, count($harness['connections']), 'firing the previous rung dialed the next socket');
            $harness['clock']->fireNextOneShot();
            $harness['closeLive']();
        }

        self::assertSame([1.0, 2.0, 4.0, 8.0, 16.0], $harness['clock']->oneShotDelays, 'ladder must double from 1.0s: 1s, 2s, 4s, 8s, 16s');
        self::assertSame(6, count($harness['connections']), 'initial dial plus five ladder dials');
        self::assertSame(0, $harness['clock']->pendingOneShotCount(), 'exhaustion arms no sixth rung');
        self::assertSame([], $harness['clock']->cancelledDelays, 'the natural ladder never cancels a timer');
        self::assertSame(['reconnect_exhausted'], $harness['errorCodes'], 'the terminal state must surface exactly one loud error');
        self::assertSame(
            sprintf('SyncPlay reconnect stopped after %d failed attempts', SyncPlayService::MAX_RECONNECT_ATTEMPTS),
            $harness['errorMessages'][0],
        );
    }

    public function testSuccessfulReconnectResetsTheBudgetSoTheNextLadderStartsAtOneSecond(): void
    {
        $harness = $this->createLadderHarness();
        $harness['join']();

        $harness['closeLive']();
        $harness['clock']->fireNextOneShot();
        $harness['closeLive']();
        $harness['clock']->fireNextOneShot();

        // Second ladder dial succeeds: onConnect resets reconnecting/attempts.
        self::assertSame(3, count($harness['connections']));
        $successSocket = $harness['connections'][2];
        $onConnect = $successSocket->onConnect;
        self::assertIsCallable($onConnect);
        $onConnect($successSocket);

        $harness['closeLive']();

        self::assertSame([1.0, 2.0, 1.0], $harness['clock']->oneShotDelays, 'a success resets the ladder — the next failure waits 1.0s again');
        // The surface contract of the three closes: first drop (not yet
        // reconnecting), ladder-dial drop (reconnecting), post-success drop
        // (budget was reset, so again "not reconnecting" at echo time).
        self::assertSame([false, true, false], $harness['disconnects']);
    }

    public function testRejoinCancelsTheArmedRungAndRefusesTheCancelledCallback(): void
    {
        $harness = $this->createLadderHarness();
        $harness['join']();
        $harness['closeLive']();

        /** @var \Closure $armedCallback */
        $armedCallback = $harness['clock']->pendingOneShots[0]->getCallback();

        $harness['join']();

        self::assertSame([1.0], $harness['clock']->cancelledDelays, 'a fresh join cancels the outstanding rung');
        self::assertSame([1.0], $harness['clock']->oneShotDelays, 'the rejoin itself arms nothing — the new socket has not dropped yet');
        self::assertSame(2, count($harness['connections']), 'the rejoin dialed exactly one new socket');
        // Production abandons a dropped socket without closing it (the slot is
        // already null by the time a rejoin replaces it) — pin that the ladder
        // machinery never surprises the caller with a mid-ladder close.
        self::assertSame(0, $harness['connections'][0]->closeCalls);
        self::assertSame(0, $harness['connections'][1]->closeCalls);

        // A stale fire of the cancelled rung must dial nothing: the budget was
        // reset, so the rung's `reconnecting === false` guard turns it away.
        $armedCallback();

        self::assertSame(2, count($harness['connections']), 'the cancelled rung dialed nothing');
        self::assertSame([1.0], $harness['clock']->oneShotDelays, 'the cancelled rung armed nothing');
        self::assertSame(0, $harness['clock']->pendingOneShotCount());

        // The new join owns a brand-new budget: its first drop waits 1.0s again.
        $harness['closeLive']();

        self::assertSame([1.0, 1.0], $harness['clock']->oneShotDelays, 'the fresh join restarted the ladder at 1.0s');
        self::assertSame(1, $harness['clock']->pendingOneShotCount());
        self::assertSame([false, false], $harness['disconnects'], 'both drops echoed while not reconnecting');
    }

    public function testIntentionalLeaveFromConnectedRoomSilencesAndClosesTheSocket(): void
    {
        $harness = $this->createLadderHarness();
        $harness['join']();

        $socket = $harness['connections'][0];
        $onConnect = $socket->onConnect;
        self::assertIsCallable($onConnect);
        $onConnect($socket);

        self::assertSame([30.0], $harness['clock']->periodicDelays, 'connecting arms the 30s time-sync ping');

        $harness['service']->leaveRoom();

        self::assertSame([30.0], $harness['clock']->cancelledDelays, 'leave cancels the ping; no rung exists to cancel');
        self::assertSame(0, $harness['clock']->pendingOneShotCount());
        self::assertSame([], $harness['clock']->pendingPeriodics);
        self::assertNull($socket->onClose, 'the departing socket is silenced — its echoes must not resurrect the ladder');
        self::assertNull($socket->onError);
        self::assertNull($socket->onMessage);
        self::assertSame(1, $socket->closeCalls, 'leave closes the current socket explicitly');
        $leave = json_decode($socket->sent[1] ?? '', true);
        self::assertSame(Messages::TYPE_GROUP_LEAVE, $leave['type'] ?? null, 'a goodbye frame precedes the goodbye');
        self::assertSame('sp_cca927fbf4ba11f9', $leave['group_id'] ?? null);
        self::assertFalse($harness['service']->isInRoom());
        self::assertSame([], $harness['errorCodes'], 'a goodbye must never surface an error');
        self::assertSame([], $harness['disconnects'], 'an intentional leave surfaces no disconnect');
    }

    public function testIntentionalLeaveWhileRungArmedCancelsLadderAndLeavesEchoesInert(): void
    {
        $harness = $this->createLadderHarness();
        $harness['join']();

        $socket = $harness['connections'][0];
        $onConnect = $socket->onConnect;
        self::assertIsCallable($onConnect);
        $onConnect($socket);

        $harness['closeLive']();
        self::assertSame(1, $harness['clock']->pendingOneShotCount(), 'the dropped socket armed exactly one reconnect rung');
        /** @var \Closure $armedCallback */
        $armedCallback = $harness['clock']->pendingOneShots[0]->getCallback();

        $harness['service']->leaveRoom();

        self::assertSame([1.0, 30.0], $harness['clock']->cancelledDelays, 'leave cancels BOTH the armed ladder rung and the ping timer');
        self::assertSame(0, $harness['clock']->pendingOneShotCount());
        self::assertSame([], $harness['clock']->pendingPeriodics);
        self::assertFalse($harness['service']->isInRoom());
        self::assertSame([], $harness['errorCodes'], 'a goodbye must never surface an error');
        self::assertSame([false], $harness['disconnects'], 'only the accidental drop echoed, before the leave');

        // The slot was already empty at leave time, so this abandoned socket is
        // NOT handler-silenced — its late rung fire and stray close echo must
        // be stopped by the session-null and identity guards instead.
        $armedCallback();
        $staleClose = $socket->onClose;
        self::assertIsCallable($staleClose);
        $staleClose($socket);

        self::assertSame(1, count($harness['connections']), 'neither the cancelled rung nor the echo dialed anything');
        self::assertSame([1.0], $harness['clock']->oneShotDelays, 'neither the cancelled rung nor the echo armed anything');
        self::assertSame([false], $harness['disconnects'], 'the abandoned socket echo surfaces no further disconnect');
    }

    public function testStaleSocketCallbacksCannotReArmTheLadder(): void
    {
        $harness = $this->createLadderHarness();
        $harness['join']();
        $stale = $harness['connections'][0];
        $harness['closeLive']();
        $harness['clock']->fireNextOneShot();

        // The replacement dial left wsConnection on the NEW socket; the stale
        // one's captured handlers must now be inert.
        $staleClose = $stale->onClose;
        self::assertIsCallable($staleClose);
        $staleClose($stale);

        $staleError = $stale->onError;
        self::assertIsCallable($staleError);
        $staleError($stale, AsyncTcpConnection::CONNECT_FAIL, 'stale socket echo');

        self::assertSame(2, count($harness['connections']), 'a stale echo dials nothing');
        self::assertSame([1.0], $harness['clock']->oneShotDelays, 'a stale echo arms nothing');
        self::assertSame([false], $harness['disconnects'], 'only the genuine first drop echoed');
        self::assertSame([], $harness['errorCodes'], 'a stale echo surfaces no websocket_error');

        // The live socket still drives the ladder normally.
        $harness['closeLive']();

        self::assertSame([1.0, 2.0], $harness['clock']->oneShotDelays, 'the identity guard must not mute the live socket');
        self::assertSame([false, true], $harness['disconnects'], 'the live drop echoes with reconnecting=true');
    }

    public function testTimeSyncPingTimerIsArmedOnceAndSurvivesReconnects(): void
    {
        $harness = $this->createLadderHarness();
        $harness['join']();

        $first = $harness['connections'][0];
        $onConnect = $first->onConnect;
        self::assertIsCallable($onConnect);
        $onConnect($first);

        $harness['closeLive']();
        $harness['clock']->fireNextOneShot();

        $second = $harness['connections'][1];
        $onConnect = $second->onConnect;
        self::assertIsCallable($onConnect);
        $onConnect($second);

        self::assertSame([30.0], $harness['clock']->periodicDelays, 'two successful connects must share ONE ping timer — no stacking');
        self::assertCount(1, $harness['clock']->pendingPeriodics);
        self::assertSame([], $harness['clock']->cancelledDelays, 'the ping survives reconnects untouched');

        // Drive the periodic once: it self-guards on connected/session, so a
        // single tick must send exactly one time_ping frame on the NEW socket.
        $ping = $harness['clock']->pendingPeriodics[0];
        ($ping->getCallback())($ping);

        $decoded = json_decode($second->sent[1] ?? '', true);
        self::assertSame(Messages::TYPE_TIME_PING, $decoded['type'] ?? null, 'the surviving ping timer keeps ticking on the new socket');
    }

    public function testErrorThenCloseOnLiveSocketArmsOnlyOneRung(): void
    {
        $harness = $this->createLadderHarness();
        $harness['join']();

        $harness['fireErrorOnLive'](AsyncTcpConnection::CONNECT_FAIL, 'ECONNRESET');
        $harness['closeLive']();

        self::assertSame(['websocket_error'], $harness['errorCodes'], 'the error callback surfaces exactly once');
        self::assertSame(
            ['connect failed: ECONNRESET'],
            $harness['errorMessages'],
            'the Workerman code/message pair is normalized into one detail line',
        );
        self::assertSame([1.0], $harness['clock']->oneShotDelays, 'error+close pair dedupes to a single armed rung');
        self::assertSame(1, $harness['clock']->pendingOneShotCount());
        self::assertSame([true], $harness['disconnects'], 'only the close surfaces a disconnect');
    }

    // ---- Vendor invocation-shape pins (the TypeError class of defect) --------

    public function testInitialDialFailureThroughVendorEmitErrorSurfacesEventAndRejectsTheJoinPromise(): void
    {
        $transport = (new FakeTransport())->json(200, self::joinEnvelope());
        $clock = new FakeClockLoop();
        $connection = new RecordingConnection();
        $service = new SyncPlayService(
            new ApiClient('https://srv', $transport),
            $clock,
            static fn (string $url): AsyncTcpConnection => $connection,
        );

        /** @var list<string> $errorCodes */
        $errorCodes = [];
        /** @var list<string> $errorMessages */
        $errorMessages = [];

        $service->onError(static function (string $code, string $message) use (&$errorCodes, &$errorMessages): void {
            $errorCodes[] = $code;
            $errorMessages[] = $message;
        });

        $rejection = null;
        $service->joinRoom('sp_cca927fbf4ba11f9')
            ->then(null, static function (\Throwable $e) use (&$rejection): void {
                $rejection = $e;
            });

        // Drive the vendor's REAL call site, not a hand-written arity:
        // emitError() is the single funnel every Workerman connect/send
        // failure passes through (AsyncTcpConnection.php:304/:453/:512,
        // TcpConnection.php:513/:1130) and it invokes the handler as
        // ($this, $code, $msg) at AsyncTcpConnection.php:336. Pre-fix the
        // Throwable-typed handler turned this into a TypeError that
        // Worker::error() caught and routed to Worker::stopAll(250) — the
        // event below never fired and this promise never rejected.
        $emitError = new \ReflectionMethod(RecordingConnection::class, 'emitError');
        $emitError->setAccessible(true);
        $emitError->invoke($connection, AsyncTcpConnection::CONNECT_FAIL, 'Connection refused');

        self::assertSame(
            ['websocket_error'],
            $errorCodes,
            'a genuine Workerman error must reach the service-level onError exactly once',
        );
        self::assertSame(
            ['connect failed: Connection refused'],
            $errorMessages,
            'the (code, mixed message) pair normalizes into one detail line',
        );
        self::assertInstanceOf(
            \RuntimeException::class,
            $rejection,
            'the initial-dial promise must reject when the dial fails',
        );
        self::assertStringContainsString(
            'WebSocket connection failed: connect failed: Connection refused',
            $rejection?->getMessage() ?? '',
        );
    }

    public function testVendorShapedOnMessageInvocationDispatchesFrameData(): void
    {
        $harness = $this->createLadderHarness();
        $harness['join']();

        /** @var RecordingConnection $connection */
        $connection = $harness['connections'][0];
        $onMessage = $connection->onMessage;
        self::assertIsCallable($onMessage);

        // TcpConnection invokes onMessage as ($connection, $data) on every
        // path (TcpConnection.php:715-831). Pre-fix the string-typed first
        // parameter made every inbound frame a TypeError.
        $onMessage($connection, (string) json_encode([
            'type' => 'error',
            'data' => ['message' => 'Websocket authentication failed'],
            'timestamp' => 1771000000,
        ], JSON_THROW_ON_ERROR));

        self::assertSame(
            ['unknown'],
            $harness['errorCodes'],
            'the frame must reach handleMessage through the vendor call shape',
        );
        self::assertSame(['Websocket authentication failed'], $harness['errorMessages']);
    }

    public function testSocketHandlerSignaturesMatchTheVendorInvocationShapes(): void
    {
        $harness = $this->createLadderHarness();
        $harness['join']();

        /** @var RecordingConnection $connection */
        $connection = $harness['connections'][0];

        $onError = $connection->onError;
        self::assertIsCallable($onError);
        $errorShape = new \ReflectionFunction($onError);
        self::assertSame(
            3,
            $errorShape->getNumberOfParameters(),
            'vendor emits onError($connection, $code, $message) — AsyncTcpConnection.php:336',
        );
        self::assertSame(
            AsyncTcpConnection::class,
            (string) $errorShape->getParameters()[0]->getType(),
            'the first parameter must accept the connection — typing it \\Throwable '
            . 'is the shipped production defect this pin retires',
        );

        $onMessage = $connection->onMessage;
        self::assertIsCallable($onMessage);
        $messageShape = new \ReflectionFunction($onMessage);
        self::assertSame(
            2,
            $messageShape->getNumberOfParameters(),
            'vendor emits onMessage($connection, $data) — TcpConnection.php:831',
        );
        self::assertSame(AsyncTcpConnection::class, (string) $messageShape->getParameters()[0]->getType());

        // onConnect/onClose are invoked as ($connection) (vendor
        // AsyncTcpConnection.php:497 / TcpConnection.php:1180). Zero-parameter
        // closures legally ignore that extra positional argument, so pin only
        // that neither handler DEMANDS more than the vendor ever sends.
        $onConnect = $connection->onConnect;
        self::assertIsCallable($onConnect);
        self::assertLessThanOrEqual(1, (new \ReflectionFunction($onConnect))->getNumberOfRequiredParameters());

        $onClose = $connection->onClose;
        self::assertIsCallable($onClose);
        self::assertLessThanOrEqual(1, (new \ReflectionFunction($onClose))->getNumberOfRequiredParameters());
    }

    // ---- Ladder harness ------------------------------------------------------

    /**
     * @return array{
     *     service: SyncPlayService,
     *     clock: FakeClockLoop,
     *     connections: list<RecordingConnection>,
     *     errorCodes: list<string>,
     *     errorMessages: list<string>,
     *     disconnects: list<bool>,
     *     join: callable(): mixed,
     *     closeLive: callable(): mixed,
     *     fireErrorOnLive: callable(int|string, mixed): mixed
     * }
     */
    private function createLadderHarness(): array
    {
        $transport = new FakeTransport();
        $clock = new FakeClockLoop();

        /** @var list<RecordingConnection> $connections */
        $connections = [];
        $service = new SyncPlayService(new ApiClient('https://srv', $transport), $clock, static function (string $url) use (&$connections): AsyncTcpConnection {
            $connection = new RecordingConnection();
            $connections[] = $connection;

            return $connection;
        });

        /** @var list<string> $errorCodes */
        $errorCodes = [];
        /** @var list<string> $errorMessages */
        $errorMessages = [];
        /** @var list<bool> $disconnects */
        $disconnects = [];

        $service->onError(static function (string $code, string $message) use (&$errorCodes, &$errorMessages): void {
            $errorCodes[] = $code;
            $errorMessages[] = $message;
        });
        $service->onDisconnect(static function (bool $wasReconnecting) use (&$disconnects): void {
            $disconnects[] = $wasReconnecting;
        });

        $join = static function () use ($transport, $service): mixed {
            $transport->json(200, self::joinEnvelope());

            return $service->joinRoom('sp_cca927fbf4ba11f9')
                ->then(null, static fn (): null => null);
        };

        // Drive the handlers with Workerman's REAL invocation arity:
        // onClose is invoked as ($connection) (TcpConnection.php:1180) and
        // onError as ($connection, $code, $message)
        // (AsyncTcpConnection.php:336). Pre-fix these pins baked the wrong
        // signatures and stayed green while production threw TypeErrors.
        $closeLive = static function () use (&$connections): void {
            /** @var AsyncTcpConnection $connection */
            $connection = end($connections);
            /** @var \Closure $onClose */
            $onClose = $connection->onClose;
            $onClose($connection);
        };

        $fireErrorOnLive = static function (
            int|string $code = AsyncTcpConnection::CONNECT_FAIL,
            mixed $message = 'connect fail',
        ) use (&$connections): void {
            /** @var AsyncTcpConnection $connection */
            $connection = end($connections);
            /** @var \Closure $onError */
            $onError = $connection->onError;
            $onError($connection, $code, $message);
        };

        return [
            'service' => $service,
            'clock' => $clock,
            'connections' => &$connections,
            'errorCodes' => &$errorCodes,
            'errorMessages' => &$errorMessages,
            'disconnects' => &$disconnects,
            'join' => $join,
            'closeLive' => $closeLive,
            'fireErrorOnLive' => $fireErrorOnLive,
        ];
    }

    /**
     * Real create/join envelope (group block, golden-vector shape, S414 law:
     * feed the REAL envelope bytes, never mocks-of-own-shape).
     *
     * @return array<string,mixed>
     */
    private static function joinEnvelope(): array
    {
        return json_decode(<<<'JSON'
        {"success": true, "group": {"group_id": "sp_cca927fbf4ba11f9", "group_name": "Movie Night", "member_count": 1, "members": {"member_host": {"id": "member_host", "name": "Host One", "is_host": true, "joined_at": 1788300111}}, "host_id": "member_host", "current_media_id": null, "current_media_duration": 0, "playback_position": 0, "playback_state": "stopped", "queue": [], "created_at": 1788300111, "last_activity_at": 1788300111}}
        JSON, true, 512, JSON_THROW_ON_ERROR);
    }
}
