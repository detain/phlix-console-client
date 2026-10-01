<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit\Api\SyncPlay;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\SyncPlay\Messages;
use Phlix\Console\Api\SyncPlay\SyncPlayService;
use Phlix\Console\Api\SyncPlay\WorkermanEventBridge;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use Workerman\Timer;
use Workerman\Worker;

/**
 * Carrier-flip proof (phlix-server 424c14d0 dual-carrier law): the REAL
 * production dial path — `SyncPlayService::createRoom()` over a REAL
 * Workerman `AsyncTcpConnection` — must
 *
 *   1. offer the JWT in the handshake as `Sec-WebSocket-Protocol: bearer, <jwt>`
 *      (vendor seam: the `websocketClientProtocol` property read by
 *      `Protocols\Ws::sendHandshake()`, vendor v5.2.2 Ws.php:372/:379), and
 *   2. carry NO credential in the URL (the RETIRED `?token=` query must be
 *      gone — sending both carriers with differing values is refused
 *      pre-101 by `SyncPlayAuthMiddleware::resolveHandshakeToken()`), and
 *   3. ACCEPT a server that echoes only the SUBSET `bearer` in the 101 —
 *      the vendored client handshake parser (`Ws::dealHandshake()`) validates
 *      only `Sec-WebSocket-Accept` and never inspects the echoed protocol,
 *      so no transitional query fallback is needed.
 *
 * Harness idiom mirrors phlix-server `NatPmpClientTest` (b620e4e1): a forked
 * child drives the production client against a real loopback socket; the
 * parent is a hand-rolled minimal WS responder asserting on the WIRE BYTES it
 * receives; the child hard-SIGKILLs itself so PHPUnit shutdown handlers never
 * run twice. Success is proven by BOTH sides: the parent sees the offer
 * header, the clean path, and the buffered join frame flushed only AFTER the
 * subset-echo 101 (the vendor's `tmpWebsocketData` replay at Ws.php:440-443);
 * the child's service fires `onGroupState` from the post-101 parent frame and
 * writes the marker file.
 */
final class SyncPlayBearerCarrierHandshakeTest extends TestCase
{
    private const GROUP_ID = 'sp_carrier_harness_1';

    private const JWT = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJoYXJuZXNzIn0.c3JjLWp3VA';

    private const WS_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    public function testProductionDialOffersBearerSubprotocolAndAcceptsSubsetEcho(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('fork-based harness needs pcntl_fork + posix_kill.');
        }

        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, "loopback listener bind failed: [$errno] $errstr");
        $name = (string) stream_socket_get_name($server, false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $this->assertGreaterThan(0, $port, 'OS-assigned harness port');

        $marker = sys_get_temp_dir() . '/phlix-ws-carrier-' . bin2hex(random_bytes(6)) . '.marker';

        // Built BEFORE the fork so both the parent's expectations (member id)
        // and the child's execution share one production service instance.
        // wsPort override aims the real dial at the harness listener; the
        // http (not https) base makes the production scheme logic pick ws://.
        $transport = (new FakeTransport())->json(200, $this->createEnvelope());
        $api = new ApiClient("http://127.0.0.1:$port", $transport);
        $api->setToken(new TokenBundle(self::JWT, 'r1'));
        $service = new SyncPlayService($api, null, null, $port);
        $memberId = $service->getMemberId();

        $client = null;
        $pid = -1;
        $status = 0;
        try {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $this->runChild($service, $marker, $server);
                // runChild never returns (SIGKILL); belt for a fatal before it:
                self::suicide();
            }

            $client = $this->acceptWithTimeout($server, 10.0);
            $this->assertNotFalse($client, 'the production client never dialled the harness listener');

            $request = $this->readHttpRequest($client);

            // --- (1)+(2) the wire the SERVER actually sees -------------------
            $this->assertStringNotContainsString(
                'token=',
                $request,
                "the retired query carrier must be gone from the whole handshake:\n$request",
            );
            $this->assertStringStartsWith(
                'GET /syncplay/' . rawurlencode(self::GROUP_ID) . ' HTTP/1.1',
                $request,
                'room-path law unchanged: /syncplay/{room}, no query string',
            );
            $this->assertMatchesRegularExpression(
                '/^Sec-WebSocket-Protocol: bearer, ' . preg_quote(self::JWT, '/') . '\r?$/mi',
                $request,
                "the bearer sub-protocol offer (marker, credential) must be on the wire:\n$request",
            );
            $this->assertSame(1, preg_match('/^Sec-WebSocket-Key: (\S+)\r?$/mi', $request, $m), 'client must send a Sec-WebSocket-Key');

            // --- (3) subset echo: 101 offers ONLY 'bearer', never the jwt ---
            $accept = base64_encode(sha1($m[1] . self::WS_GUID, true));
            $written = @fwrite(
                $client,
                "HTTP/1.1 101 Switching Protocols\r\n"
                . "Upgrade: websocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Accept: $accept\r\n"
                . "Sec-WebSocket-Protocol: bearer\r\n\r\n",
            );
            $this->assertIsInt($written, 'failed to write the 101 subset echo');

            // The join frame was buffered pre-101 by Ws::encode and is replayed
            // by dealHandshake ONLY after accepting the echoed-subset 101 —
            // receiving it here proves the vendor client accepted the response.
            $joinPayload = $this->readClientFrame($client);
            $join = json_decode($joinPayload, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(Messages::TYPE_GROUP_JOIN, $join['type'] ?? null, 'first post-101 frame must be the buffered join');
            $this->assertSame(self::GROUP_ID, $join['group_id'] ?? null);
            $this->assertSame($memberId, $join['member_id'] ?? null);

            // --- child-side sentinel: production message path over the socket
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

            $status = -1;
            $this->assertSame($pid, pcntl_waitpid($pid, $status), 'child did not exit');
            $this->assertTrue(file_exists($marker), 'child never observed the post-handshake group_state via the production onGroupState path');
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
        }
    }

    // ---- child side -------------------------------------------------------

    /**
     * Hard suicide: SIGKILL is unmaskable and synchronous for the signalling
     * process on Linux, but declare `never` defensively so no forked child can
     * ever fall through into parent assertions.
     */
    private static function suicide(): never
    {
        posix_kill(posix_getpid(), SIGKILL);
        throw new \LogicException('SIGKILL did not terminate this process');
    }

    /**
     * Never returns: the child either proves the round-trip (marker + SIGKILL)
     * or the watchdog SIGKILLs it. SIGKILL (not exit) so inherited PHPUnit
     * shutdown handlers never fire a second time.
     *
     * Pump law (event-loop bridge lane): the child runs the REAL handshake on
     * the WorkermanEventBridge over the React loop — the exact pairing the
     * interactive TUI installs — not on a Workerman Select. The watchdog is
     * armed through Workerman's Timer facade to prove installOnce wired it
     * onto the same pump.
     *
     * @param resource $server
     */
    private function runChild(SyncPlayService $service, string $marker, $server): never
    {
        fclose($server);

        Worker::$globalEvent = null;
        WorkermanEventBridge::installOnce(\React\EventLoop\Loop::get());

        // Watchdog: a stalled handshake (e.g. a vendor-side strict-echo
        // rejection) must surface as a parent-side failure, not a hang.
        Timer::add(8.0, static function (): never {
            self::suicide();
        }, [], false);

        $service->onGroupState(static function () use ($marker): never {
            file_put_contents($marker, 'OK');
            self::suicide();
        });

        // Production dial path, REAL factory (default), REAL AsyncTcpConnection.
        $service->createRoom('Carrier Harness');

        \React\EventLoop\Loop::run();
        self::suicide();
    }

    // ---- parent-side wire helpers ----------------------------------------

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
                $this->fail('handshake request stream ended before headers were complete: ' . var_export($request, true));
            }
            $request .= $line;
        }

        return $request;
    }

    /**
     * Read and unmask exactly one client→server text frame.
     *
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
                $this->fail("client stream closed/timed out after " . strlen($buf) . "/$n bytes" . (($meta['timed_out'] ?? false) ? ' (timed out)' : ''));
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
        // Server→client frames are unmasked, per RFC 6455.
        $this->assertIsInt(@fwrite($client, $frame . $payload), 'failed to write the group_state frame');
    }

    /** @return array<string,mixed> */
    private function createEnvelope(): array
    {
        return json_decode(<<<'JSON'
        {"success": true, "group": {"group_id": "sp_carrier_harness_1", "group_name": "Carrier Harness", "member_count": 1, "members": {"member_host": {"id": "member_host", "name": "Host One", "is_host": true, "joined_at": 1788300111}}, "host_id": "member_host", "current_media_id": null, "current_media_duration": 0, "playback_position": 0, "playback_state": "stopped", "queue": [], "created_at": 1788300111, "last_activity_at": 1788300111}}
        JSON, true, 512, JSON_THROW_ON_ERROR);
    }
}
