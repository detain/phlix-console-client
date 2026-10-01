<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit\Api\SyncPlay;

use InvalidArgumentException;
use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\SyncPlay\HubRelayConsumer;
use Phlix\Console\Api\SyncPlay\SyncPlayService;
use Phlix\Console\Api\SyncPlay\WebSocketDialer;
use Phlix\Console\Api\SyncPlay\WorkermanEventBridge;
use Phlix\Console\Tests\Api\FakeTransport;
use Phlix\Console\Tests\Api\RecordingEventLoop;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Events\EventInterface;
use Workerman\Events\Select;
use Workerman\Protocols\Ws;
use Workerman\Worker;

/**
 * Construction-level proof for the TLS-websocket fix: the pinned Workerman
 * v5.2.2 has NO `Protocols\Wss` class (AsyncTcpConnection.php:58-68 lists
 * only tcp/udp/unix/ssl/sslv2/sslv3/tls as built-in transports; :212-224
 * resolves any other scheme via `class_exists` and THROWS otherwise), so a
 * raw `wss://` dial crashed at construction. The dialer must produce the
 * vendor's sanctioned two-layer form — `ws` scheme (→ `Protocols\Ws`
 * framing) + public `$transport = 'ssl'` (:87, applied by connect()/
 * checkConnection() at :289-296/:476-478) — with SNI/peer_name set, and
 * both production consumers must route through it by default.
 */
final class WebSocketDialerTest extends TestCase
{
    private ?EventInterface $globalEventBefore = null;

    protected function setUp(): void
    {
        // Dialing is only legal with a pump installed for the process — the
        // stopguard law. Construction tests run against the honest production
        // precondition (interactive bridge); the stopguard tests below remove
        // it deliberately to pin the loud failure.
        $this->globalEventBefore = Worker::$globalEvent;
        Worker::$globalEvent = new WorkermanEventBridge(new RecordingEventLoop());
    }

    protected function tearDown(): void
    {
        Worker::$globalEvent = $this->globalEventBefore;
    }

    public function testDialWithoutEventPumpFailsLoudInsteadOfDyingAtConnect(): void
    {
        Worker::$globalEvent = null;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SyncPlay WS requires the React-loop event bridge');

        WebSocketDialer::dial('ws://127.0.0.1:8097/syncplay/room');
    }

    public function testArgumentErrorsPrecedeThePumpGuard(): void
    {
        // Ordering law: a malformed URL is a deterministic programmer error
        // and must keep reporting InvalidArgumentException whether or not a
        // pump exists — the fail-fast tests above stay pump-independent.
        Worker::$globalEvent = null;

        $this->expectException(InvalidArgumentException::class);

        WebSocketDialer::dial('http://hub.example.com:8800/x');
    }

    public function testDialAcceptsTheWatchCommandsSelectPump(): void
    {
        // The stopguard predicate is "a pump exists for this process", not
        // "the pump is our bridge" — the watch path's Select stays legal.
        Worker::$globalEvent = new Select();

        $connection = WebSocketDialer::dial('ws://127.0.0.1:8097/syncplay/room');

        self::assertInstanceOf(AsyncTcpConnection::class, $connection);
    }

    public function testRawVendorConstructionOfWssThrowsRegressionPin(): void
    {
        // WHY the dialer exists: the vendor itself cannot construct wss.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('class \Protocols\Wss not exist');

        new AsyncTcpConnection('wss://hub.example.com:8804/syncplay/srv-1');
    }

    public function testWssUrlConstructsWsFramingOverSslTransportWithoutThrowing(): void
    {
        $connection = WebSocketDialer::dial('wss://hub.example.com:8804/syncplay/srv-1');

        self::assertInstanceOf(AsyncTcpConnection::class, $connection);
        self::assertSame('ssl', $connection->transport, 'crypto layer must be armed pre-connect()');
        self::assertSame(
            Ws::class,
            ltrim((string) $connection->protocol, '\\'),
            'framing layer must be the vendor Ws client protocol (vendor stores a leading-backslash class literal)',
        );

        self::assertSame('hub.example.com', self::readProperty($connection, 'remoteHost'));
        self::assertSame(8804, self::readProperty($connection, 'remotePort'));
        self::assertSame(
            '/syncplay/srv-1',
            self::readProperty($connection, 'remoteURI'),
            'path law must survive the scheme swap',
        );

        self::assertSame(
            ['ssl' => ['SNI_enabled' => true, 'peer_name' => 'hub.example.com']],
            self::readProperty($connection, 'socketContext'),
            'SNI + peer_name pinned to the URL host, verification left ON by default',
        );
    }

    public function testUppercaseSchemeStillSwapsCorrectly(): void
    {
        $connection = WebSocketDialer::dial('WSS://Hub.Example.COM:8097/syncplay/room%20a');

        self::assertSame('ssl', $connection->transport);
        self::assertSame(Ws::class, ltrim((string) $connection->protocol, '\\'));
        self::assertSame('/syncplay/room%20a', self::readProperty($connection, 'remoteURI'));
    }

    public function testWsUrlPassesThroughUntranslated(): void
    {
        $connection = WebSocketDialer::dial('ws://127.0.0.1:8097/syncplay/room');

        self::assertSame('tcp', $connection->transport, 'plain ws must NOT arm the crypto layer');
        self::assertSame(Ws::class, ltrim((string) $connection->protocol, '\\'));
        self::assertSame([], self::readProperty($connection, 'socketContext'));
    }

    public function testSslOptionsMergeOverTheSecureDefaultsForTestHarnessesOnly(): void
    {
        $connection = WebSocketDialer::dial('wss://127.0.0.1:8804/syncplay/srv', [
            'verify_peer' => false,
            'allow_self_signed' => true,
        ]);

        self::assertSame(
            [
                'ssl' => [
                    'SNI_enabled' => true,
                    'peer_name' => '127.0.0.1',
                    'verify_peer' => false,
                    'allow_self_signed' => true,
                ],
            ],
            self::readProperty($connection, 'socketContext'),
        );
    }

    /** @return array<string, array{string}> */
    public static function nonWebSocketUrlProvider(): array
    {
        return [
            'http origin' => ['http://hub.example.com:8800/x'],
            'raw ssl transport (no ws framing)' => ['ssl://hub.example.com:443'],
            'no scheme' => ['hub.example.com:8804/syncplay/srv'],
            'wss without host' => ['wss:///syncplay/srv'],
            'garbage' => ['not a url'],
        ];
    }

    #[DataProvider('nonWebSocketUrlProvider')]
    public function testRejectsAnythingThatIsNotAHostedWsUrlFailFast(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('WebSocketDialer requires a ws(s):// URL with a host');

        WebSocketDialer::dial($url);
    }

    // ---- production wiring: both consumers default to the dialer ---------

    public function testSyncPlayServiceDefaultFactoryRoutesThroughTheDialer(): void
    {
        $service = new SyncPlayService(new ApiClient('https://server.example.com', new FakeTransport()));
        $factory = self::readProperty($service, 'connectionFactory');

        self::assertInstanceOf(\Closure::class, $factory);
        $connection = $factory('wss://server.example.com:8097/syncplay/room');

        self::assertInstanceOf(AsyncTcpConnection::class, $connection);
        self::assertSame('ssl', $connection->transport);
        self::assertSame(Ws::class, ltrim((string) $connection->protocol, '\\'));
    }

    public function testHubRelayConsumerDefaultFactoryRoutesThroughTheDialer(): void
    {
        $consumer = new HubRelayConsumer(
            hubBaseUrl: 'https://hub.example.com',
            serverId: 'srv-1',
            tokenProvider: static fn (): ?string => 'token',
            onPendingCommand: static function (): void {
            },
        );
        $factory = self::readProperty($consumer, 'connectionFactory');

        self::assertInstanceOf(\Closure::class, $factory);
        $connection = $factory('wss://hub.example.com:8804/syncplay/srv-1');

        self::assertInstanceOf(AsyncTcpConnection::class, $connection);
        self::assertSame('ssl', $connection->transport);
        self::assertSame(Ws::class, ltrim((string) $connection->protocol, '\\'));
    }

    // ---- helpers ----------------------------------------------------------

    private static function readProperty(object $subject, string $name): mixed
    {
        $property = new ReflectionProperty($subject, $name);

        return $property->getValue($subject);
    }
}
