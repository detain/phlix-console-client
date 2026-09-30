<?php

declare(strict_types=1);

namespace Phlix\Console\Api\SyncPlay;

use InvalidArgumentException;
use Workerman\Connection\AsyncTcpConnection;

/**
 * The single seam that turns a canonical `ws(s)://` URL into a constructed
 * (not yet dialled) Workerman client socket.
 *
 * Why the seam exists: the pinned Workerman v5.2.2 ships NO
 * `Protocols\Wss` class. `AsyncTcpConnection::__construct()` maps a URL
 * scheme to EITHER a built-in transport (`BUILD_IN_TRANSPORTS` =
 * tcp/udp/unix/ssl/sslv2/sslv3/tls, AsyncTcpConnection.php:58-68) OR a
 * `\Workerman\Protocols\{Ucfirst(scheme)}` framing class (:212-224).
 * `ws` resolves to `Protocols\Ws`; `wss` finds no class and the
 * constructor throws `RuntimeException("class \Protocols\Wss not
 * exist")` — every `https:`-based syncplay/relay dial died at
 * construction, before a socket existed.
 *
 * The vendor's sanctioned TLS-websocket form splits the two layers the
 * URL encodes:
 *  - framing: the `ws` scheme stays, so `Protocols\Ws` is chosen, and
 *  - crypto:  the PUBLIC `$transport` property (:87) is set to `'ssl'`
 *    BEFORE `connect()`. Construction never dials (:233 only stores the
 *    context); `connect()` opens a plain `tcp://` async socket with the
 *    stream context applied (:289-292) and `checkConnection()` upgrades
 *    the established stream via `doSslHandshake()` when
 *    `transport === 'ssl'` (:476-478 → TcpConnection.php:924).
 *
 * Call sites keep emitting canonical `wss://` URLs — the correct wire
 * notation, pinned by the URL-builder tests; only this construction
 * step translates it into the vendor's two-layer form.
 */
final class WebSocketDialer
{
    /**
     * Build — without dialling — the client socket for a canonical ws(s):// URL.
     *
     * Production passes no `$sslOptions`: peer verification stays ON and
     * `peer_name` pins the URL host (also sent as the SNI name). A test
     * harness dialing a self-signed loopback listener loosens trust HERE
     * only — the construction path itself is never bypassed.
     *
     * @param array<string, mixed> $sslOptions extra stream-context `ssl` options
     *
     * @throws InvalidArgumentException when the URL is not a ws(s):// URL with a host
     */
    public static function dial(string $url, array $sslOptions = []): AsyncTcpConnection
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme === 'ws') {
            return new AsyncTcpConnection($url);
        }

        $host = parse_url($url, PHP_URL_HOST);
        if ($scheme !== 'wss' || !is_string($host) || $host === '') {
            throw new InvalidArgumentException(
                sprintf('WebSocketDialer requires a ws(s):// URL with a host, got "%s"', $url),
            );
        }

        // Swap the scheme to `ws` so the vendor picks Protocols\Ws
        // framing, then arm the crypto transport on the same socket.
        $connection = new AsyncTcpConnection(
            'ws://' . substr($url, strlen($scheme) + 3),
            ['ssl' => array_merge(
                ['SNI_enabled' => true, 'peer_name' => $host],
                $sslOptions,
            )],
        );
        $connection->transport = 'ssl';

        return $connection;
    }
}
