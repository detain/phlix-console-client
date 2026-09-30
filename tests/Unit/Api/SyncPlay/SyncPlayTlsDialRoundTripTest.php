<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit\Api\SyncPlay;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\SyncPlay\Messages;
use Phlix\Console\Api\SyncPlay\SyncPlayService;
use Phlix\Console\Api\SyncPlay\WebSocketDialer;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Events\Select;
use Workerman\Worker;

/**
 * End-to-end TLS proof for {@see WebSocketDialer} (forked loopback harness,
 * phlix-server NatPmpClientTest idiom b620e4e1 / console 04a1590): the REAL
 * production dial path driven by `SyncPlayService::createRoom()` over a REAL
 * Workerman `AsyncTcpConnection` built from a `wss://` URL (https server
 * base) must complete a genuine TLS handshake with a self-signed listener,
 * then the HTTP upgrade, then replay the pre-101-buffered join frame through
 * the encrypted layer.
 *
 * Pre-fix this died before any packet left the process: Workerman v5.2.2 has
 * no `Protocols\Wss` class, so `new AsyncTcpConnection('wss://…')` throws
 * RuntimeException at construction. The dialer's two-layer form (`ws`
 * framing + `transport = 'ssl'`) is what is under test here — the ONLY
 * loosened part is CA trust on the loopback harness (verify_peer off,
 * self-signed allowed); the construction path is byte-for-byte the
 * production one.
 *
 * Success is proven from BOTH sides: the parent (hand-rolled TLS WS
 * responder) asserts on the decrypted wire bytes (bearer offer header,
 * no query token, clean room path, masked join after the 101), and the
 * child's production message path fires `onGroupState` from a post-101
 * frame and writes the marker file before hard-SIGKILLing itself (so
 * inherited PHPUnit shutdown handlers never run twice).
 */
final class SyncPlayTlsDialRoundTripTest extends TestCase
{
    private const GROUP_ID = 'sp_tls_harness_1';

    private const JWT = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJoYXJuZXNzIn0.c3JjLWp3VA';

    private const WS_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    public function testProductionWssDialCompletesTlsHandshakeAndFrameRoundTrip(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('fork-based harness needs pcntl_fork + posix_kill.');
        }
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('TLS harness needs the openssl extension.');
        }

        $certFile = null;
        $keyFile = null;
        $this->createSelfSignedPair($certFile, $keyFile);

        // The accepted stream inherits the LISTENER's context: server crypto
        // material must live there for stream_socket_enable_crypto() to work.
        $listenerContext = stream_context_create(['ssl' => [
            'local_cert' => $certFile,
            'local_pk' => $keyFile,
        ]]);
        $server = @stream_socket_server(
            'tcp://127.0.0.1:0',
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $listenerContext,
        );
        $this->assertNotFalse($server, "loopback listener bind failed: [$errno] $errstr");
        $name = (string) stream_socket_get_name($server, false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $this->assertGreaterThan(0, $port, 'OS-assigned harness port');

        $marker = sys_get_temp_dir() . '/phlix-ws-tls-' . bin2hex(random_bytes(6)) . '.marker';

        // Built BEFORE the fork: the https base makes the production scheme
        // logic pick wss://; the seam loosens ONLY CA trust; wsPort aims the
        // real dial at the harness listener.
        $transport = (new FakeTransport())->json(200, $this->createEnvelope());
        $api = new ApiClient("https://127.0.0.1:$port", $transport);
        $api->setToken(new TokenBundle(self::JWT, 'r1'));
        $service = new SyncPlayService(
            $api,
            null,
            static fn (string $url): AsyncTcpConnection => WebSocketDialer::dial($url, [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ]),
            $port,
        );
        $memberId = $service->getMemberId();

        $client = null;
        $pid = -1;
        $status = 0;
        try {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $this->runChild($service, $marker, $server);
                self::suicide();
            }

            $client = $this->acceptWithTimeout($server, 10.0);
            $this->assertNotFalse($client, 'the production client never dialled the harness listener');

            $this->completeServerTlsHandshake($client);

            // The HTTP upgrade travels INSIDE the encrypted layer: reading it
            // back successfully proves stream_socket_enable_crypto completed
            // on the vendor client socket (transport='ssl' mechanism).
            $request = $this->readHttpRequest($client);
            $this->assertStringStartsWith(
                'GET /syncplay/' . rawurlencode(self::GROUP_ID) . ' HTTP/1.1',
                $request,
                "room-path law unchanged over TLS:\n$request",
            );
            $this->assertStringNotContainsString('token=', $request, 'the retired query carrier must stay gone');
            $this->assertMatchesRegularExpression(
                '/^Sec-WebSocket-Protocol: bearer, ' . preg_quote(self::JWT, '/') . '\r?$/mi',
                $request,
                "the bearer offer must survive the TLS layer:\n$request",
            );
            $this->assertSame(
                1,
                preg_match('/^Sec-WebSocket-Key: (\S+)\r?$/mi', $request, $m),
                'client must send a Sec-WebSocket-Key',
            );

            $accept = base64_encode(sha1($m[1] . self::WS_GUID, true));
            $written = @fwrite(
                $client,
                "HTTP/1.1 101 Switching Protocols\r\n"
                . "Upgrade: websocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Accept: $accept\r\n\r\n",
            );
            $this->assertIsInt($written, 'failed to write the 101');

            // Buffered join replays ONLY after the client accepted the 101 —
            // through the encrypted layer.
            $joinPayload = $this->readClientFrame($client);
            $join = json_decode($joinPayload, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(Messages::TYPE_GROUP_JOIN, $join['type'] ?? null);
            $this->assertSame(self::GROUP_ID, $join['group_id'] ?? null);
            $this->assertSame($memberId, $join['member_id'] ?? null);

            $state = json_encode([
                'type' => Messages::TYPE_GROUP_STATE,
                'group' => [
                    'group_id' => self::GROUP_ID,
                    'host_id' => $memberId,
                    'members' => [],
                    'playback_state' => 'playing',
                ],
            ], JSON_THROW_ON_ERROR);
            $this->writeServerTextFrame($client, $state);

            $this->assertSame($pid, pcntl_waitpid($pid, $status), 'child did not exit');
            $this->assertTrue(
                file_exists($marker),
                'child never observed the post-handshake group_state over the TLS socket',
            );
            $this->assertSame('OK', (string) file_get_contents($marker));
            $pid = -1;
        } finally {
            if (is_resource($client)) {
                fclose($client);
            }
            if (is_resource($server)) {
                fclose($server);
            }
            if ($pid > 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
            @unlink($marker);
            if ($certFile !== null) {
                @unlink($certFile);
            }
            if ($keyFile !== null) {
                @unlink($keyFile);
            }
        }
    }

    // ---- cert material ------------------------------------------------------

    private function createSelfSignedPair(?string &$certFile, ?string &$keyFile): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key, 'openssl key generation failed: ' . (string) openssl_error_string());

        $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($csr, 'openssl CSR failed: ' . (string) openssl_error_string());

        $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($cert, 'openssl self-sign failed: ' . (string) openssl_error_string());

        $this->assertTrue(openssl_x509_export($cert, $certPem));
        $this->assertTrue(openssl_pkey_export($key, $keyPem));

        $certFile = sys_get_temp_dir() . '/phlix-ws-tls-' . bin2hex(random_bytes(6)) . '.crt';
        $keyFile = sys_get_temp_dir() . '/phlix-ws-tls-' . bin2hex(random_bytes(6)) . '.key';
        $this->assertIsInt(file_put_contents($certFile, $certPem));
        $this->assertIsInt(file_put_contents($keyFile, $keyPem));
    }

    /**
     * Server-side crypto accept on the blocking accepted stream. The client
     * (vendor doSslHandshake, TcpConnection.php:912-924) drives a flexible
     * client method; we pin modern TLS server methods. 0 means "need more
     * data" — poll with a wall deadline so a stalled client fails the test
     * instead of hanging the suite.
     *
     * @param resource $client
     */
    private function completeServerTlsHandshake($client): void
    {
        $methods = STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER;
        $deadline = microtime(true) + 10.0;
        stream_set_timeout($client, 5);

        while (true) {
            $result = @stream_socket_enable_crypto($client, true, $methods);
            if ($result === true) {
                return;
            }
            if ($result === false || microtime(true) > $deadline) {
                $this->fail('TLS server handshake failed/stalled: ' . (string) openssl_error_string());
            }
            usleep(10_000);
        }
    }

    // ---- child side ---------------------------------------------------------

    /**
     * Hard suicide: SIGKILL is unmaskable; declare `never` defensively so a
     * forked child can never fall through into parent assertions.
     */
    private static function suicide(): never
    {
        posix_kill(posix_getpid(), SIGKILL);
        throw new \LogicException('SIGKILL did not terminate this process');
    }

    /**
     * Never returns: the child either proves the TLS round-trip (marker +
     * SIGKILL) or the watchdog SIGKILLs it.
     *
     * @param resource $server
     */
    private function runChild(SyncPlayService $service, string $marker, $server): never
    {
        fclose($server);

        $loop = new Select();
        Worker::$globalEvent = $loop;

        $loop->repeat(12.0, static function (): never {
            self::suicide();
        });

        $service->onGroupState(static function () use ($marker): never {
            file_put_contents($marker, 'OK');
            self::suicide();
        });

        // Production dial path, REAL AsyncTcpConnection via the dialer seam.
        $service->createRoom('TLS Harness');

        $loop->run();
        self::suicide();
    }

    // ---- parent-side wire helpers (same idiom as SyncPlayBearerCarrierHandshakeTest) ----

    /**
     * @param resource $server
     * @return resource|false
     */
    private function acceptWithTimeout($server, float $timeout)
    {
        $read = [$server];
        $write = null;
        $except = null;
        $sec = (int) $timeout;
        if (stream_select($read, $write, $except, $sec, (int) (($timeout - $sec) * 1_000_000)) < 1) {
            return false;
        }

        return @stream_socket_accept($server, 5);
    }

    /**
     * @param resource $client
     */
    private function readHttpRequest($client): string
    {
        stream_set_timeout($client, 10);
        $request = '';
        while (!str_contains($request, "\r\n\r\n")) {
            $line = fgets($client, 8192);
            if ($line === false) {
                $this->fail(
                    'handshake request stream ended before headers were complete: '
                    . var_export($request, true),
                );
            }
            $request .= $line;
        }

        return $request;
    }

    /**
     * @param resource $client
     */
    private function readClientFrame($client): string
    {
        $header = $this->readBytes($client, 2);
        $this->assertSame(0x81, ord($header[0]), 'fin+text opcode expected on the flushed frame');
        $b1 = ord($header[1]);
        $this->assertSame(1, ($b1 >> 7) & 1, 'client frames MUST be masked (RFC 6455)');
        $len = $b1 & 0x7F;
        if ($len === 126) {
            $len = unpack('n', $this->readBytes($client, 2))[1];
        } elseif ($len === 127) {
            $len = unpack('J', $this->readBytes($client, 8))[1];
        }
        $mask = $this->readBytes($client, 4);
        $payload = $this->readBytes($client, $len);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $payload[$i] ^ $mask[$i % 4];
        }

        return $out;
    }

    /**
     * @param resource $client
     */
    private function readBytes($client, int $n): string
    {
        stream_set_timeout($client, 10);
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($client, $n - strlen($buf));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($client);
                $this->fail(
                    'client stream closed/timed out after ' . strlen($buf) . "/$n bytes"
                    . (($meta['timed_out'] ?? false) ? ' (timed out)' : ''),
                );
            }
            $buf .= $chunk;
        }

        return $buf;
    }

    /**
     * @param resource $client
     */
    private function writeServerTextFrame($client, string $payload): void
    {
        $frame = chr(0x81);
        $len = strlen($payload);
        if ($len < 126) {
            $frame .= chr($len);
        } elseif ($len < 65536) {
            $frame .= chr(126) . pack('n', $len);
        } else {
            $frame .= chr(127) . pack('J', $len);
        }
        $this->assertIsInt(@fwrite($client, $frame . $payload), 'failed to write the group_state frame');
    }

    /**
     * Same envelope shape as SyncPlayBearerCarrierHandshakeTest::createEnvelope(),
     * written as an array literal to stay under the line-length sniff.
     *
     * @return array<string,mixed>
     */
    private function createEnvelope(): array
    {
        return [
            'success' => true,
            'group' => [
                'group_id' => self::GROUP_ID,
                'group_name' => 'TLS Harness',
                'member_count' => 1,
                'members' => [
                    'member_host' => [
                        'id' => 'member_host',
                        'name' => 'Host One',
                        'is_host' => true,
                        'joined_at' => 1788300111,
                    ],
                ],
                'host_id' => 'member_host',
                'current_media_id' => null,
                'current_media_duration' => 0,
                'playback_position' => 0,
                'playback_state' => 'stopped',
                'queue' => [],
                'created_at' => 1788300111,
                'last_activity_at' => 1788300111,
            ],
        ];
    }
}
