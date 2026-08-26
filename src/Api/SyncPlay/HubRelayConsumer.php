<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\SyncPlay;

use JsonException;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;

/**
 * Hub-relay `pending_command` consumer (S298, console half).
 *
 * The ONLY Phlix surface that can receive "Alexa, play X" is the hub's SyncPlay
 * relay WebSocket (`ws://<hub>:8804/syncplay/{server_id}`). This class is the
 * console's consumer for that surface:
 *
 * - **URL** — `ws(s)://<hub-host>:8804/syncplay/<server_id>` (the path the
 *   relay's `onWebSocketConnect()` parses the server id from). No room/group
 *   API: the hub delivers `pending_command` to an authenticated (user, server)
 *   socket regardless of room membership.
 * - **Token carrier** — the `Authorization: Bearer <token>` upgrade header.
 *   The relay accepts `Authorization: Bearer` OR the `bearer, <token>`
 *   subprotocol (S237); a PHP Workerman client CAN set request headers, so the
 *   header is the natural carrier here (the Roku half chose the same side of
 *   the S237 fork; only browsers are forced onto the subprotocol). Query-string
 *   tokens are refused by design — this class never puts one there.
 * - **Vocabulary** — the relay's own JSON frames (`group_join`, `playback_*`,
 *   `room_state`, `pending_command`, …). ONLY `pending_command` /
 *   `play_media` is consumed here (S93's frame); everything else is ignored.
 *   The frame is parsed at this boundary (parse-don't-validate): a typed
 *   command comes out, garbage yields `null` and is dropped.
 * - **Lifecycle** — open-whenever: `open()` connects immediately and keeps the
 *   socket up with a capped, self-terminating reconnect ladder until `close()`.
 *   The token is re-read on EVERY (re)connect attempt because relay tokens
 *   expire (1h default); a provider that returns `null` (or throws) is a
 *   reconnectable failure — the ladder retries on the same capped backoff and
 *   emits `exhausted` once the budget is spent.
 *
 * The class owns no event loop (mirrors {@see SyncPlayService}): the caller
 * must run one. The `bin/phlix watch` command wires the loop, the token
 * provider and the load-a-new-title dispatch.
 */
final class HubRelayConsumer
{
    /**
     * The hub relay's SyncPlay WebSocket port
     * (hub `SyncPlayRelayWorker::DEFAULT_PORT`).
     */
    public const DEFAULT_PORT = 8804;

    /**
     * Maximum reconnect attempts before giving up (mirrors the ui consumer).
     */
    public const MAX_RECONNECT_ATTEMPTS = 5;

    /**
     * Base delay in seconds for the exponential backoff ladder.
     */
    public const RECONNECT_BASE_DELAY_SECONDS = 1.0;

    private ?AsyncTcpConnection $socket = null;
    private int $reconnectAttempts = 0;
    private ?int $reconnectTimerId = null;
    private bool $opened = false;
    private bool $closing = false;

    /**
     * Whether the WebSocket HANDSHAKE is open (set on the 101, cleared on
     * close) — distinct from the TCP socket state.
     */
    private bool $wsOpen = false;

    /** @var \Closure(): ?string */
    private \Closure $tokenProvider;

    /** @var \Closure(PendingPlayMediaCommand): void */
    private \Closure $onPendingCommand;

    /** @var \Closure(string): void|null */
    private ?\Closure $onStatusChange;

    /**
     * @param string  $hubBaseUrl     Hub base origin, e.g. `https://hub.example.com`.
     *                                The relay listens on port 8804 regardless of
     *                                this origin's own port.
     * @param string  $serverId       Server the socket is bound to (the
     *                                `/syncplay/{server_id}` path AND the token's
     *                                server scope). The media ids in delivered
     *                                commands belong to this server.
     * @param \Closure(): ?string $tokenProvider
     *                                Returns the hub relay token to present on this
     *                                (re)connect attempt. Re-read on EVERY attempt
     *                                because relay tokens are short-lived (1h
     *                                default); returning `null` (or throwing)
     *                                schedules a capped retry instead of stalling.
     * @param \Closure(PendingPlayMediaCommand): void $onPendingCommand
     *                                Called once per delivered `pending_command` /
     *                                `play_media` frame — the dispatch point the
     *                                watch command wires its load-a-new-title path
     *                                to.
     * @param \Closure(string): void|null $onStatusChange
     *                                Optional lifecycle visibility: `connecting`,
     *                                `reconnecting`, `open`, `closed`, `exhausted`.
     */
    public function __construct(
        private readonly string $hubBaseUrl,
        private readonly string $serverId,
        \Closure $tokenProvider,
        \Closure $onPendingCommand,
        ?\Closure $onStatusChange = null,
    ) {
        $this->tokenProvider = $tokenProvider;
        $this->onPendingCommand = $onPendingCommand;
        $this->onStatusChange = $onStatusChange;
    }

    // ---- lifecycle -------------------------------------------------------

    /**
     * Open the hub relay socket (open-whenever).
     *
     * NOT gated on a SyncPlay room join — the hub delivers `pending_command`
     * to an authenticated (user, server) socket regardless of room membership,
     * and the primary "Alexa, play X" case has no room at all. The socket stays
     * up with a capped reconnect ladder until {@see close()}; calling `open()`
     * again restarts the ladder from a fresh budget.
     */
    public function open(): void
    {
        $this->opened = true;
        $this->closing = false;
        $this->reconnectAttempts = 0;
        $this->connect();
    }

    /**
     * Close the socket and stop the reconnect ladder.
     */
    public function close(): void
    {
        $this->closing = true;
        $this->opened = false;
        $this->wsOpen = false;

        if ($this->reconnectTimerId !== null) {
            Timer::del($this->reconnectTimerId);
            $this->reconnectTimerId = null;
        }

        if ($this->socket !== null) {
            $this->socket->close();
            $this->socket = null;
        }

        $this->reconnectAttempts = 0;
        $this->setStatus('closed');
    }

    /**
     * Whether the WebSocket handshake is currently open (the 101 has been
     * accepted and verified).
     */
    public function isOpen(): bool
    {
        return $this->wsOpen;
    }

    // ---- parsing ---------------------------------------------------------

    /**
     * Coerce one raw relay frame into a typed play-media command.
     *
     * The parse boundary: the hub's `PendingCommandDispatcher` emits
     * `{type:'pending_command', command:'play_media', server_id, media_id,
     * title, issued_at, source}` — this validates that shape; anything else
     * (unknown frame types, malformed bodies, a missing/non-int `issued_at`)
     * returns `null` and is dropped. Exported for tests and for consumers
     * that want to parse a frame without opening a socket.
     */
    public static function parsePendingCommandFrame(string $raw): ?PendingPlayMediaCommand
    {
        try {
            $frame = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($frame)) {
            return null;
        }

        if (($frame['type'] ?? null) !== 'pending_command') {
            return null;
        }
        if (($frame['command'] ?? null) !== 'play_media') {
            return null;
        }

        $serverId = $frame['server_id'] ?? null;
        $mediaId = $frame['media_id'] ?? null;
        $title = $frame['title'] ?? null;
        if (!is_string($serverId) || $serverId === '') {
            return null;
        }
        if (!is_string($mediaId) || $mediaId === '') {
            return null;
        }
        if (!is_string($title) || $title === '') {
            return null;
        }

        // `issued_at` is always present on the hub's frame (the dispatcher
        // stamps `time()`); a missing or non-int value marks a malformed
        // frame, not a reason to fabricate a timestamp (parse-don't-validate).
        $issuedAt = $frame['issued_at'] ?? null;
        if (!is_int($issuedAt)) {
            return null;
        }

        $source = $frame['source'] ?? null;

        return new PendingPlayMediaCommand(
            serverId: $serverId,
            mediaId: $mediaId,
            title: $title,
            issuedAt: $issuedAt,
            source: is_string($source) && $source !== '' ? $source : 'unknown',
        );
    }

    /**
     * Build the relay URL: `ws(s)://<host>:8804/syncplay/<server_id>`.
     *
     * The scheme follows `$hubBaseUrl` (`https:` → `wss:`); the port is the
     * relay's own 8804, never the origin's. Exported for tests.
     */
    public static function buildRelayUrl(string $hubBaseUrl, string $serverId): string
    {
        $scheme = str_starts_with($hubBaseUrl, 'https://') ? 'wss://' : 'ws://';
        $host = parse_url($hubBaseUrl, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            $host = 'localhost';
        }

        return $scheme . $host . ':' . self::DEFAULT_PORT . '/syncplay/' . rawurlencode($serverId);
    }

    // ---- internals -------------------------------------------------------

    /**
     * Connect (or reconnect) the relay socket with the current token.
     *
     * Deliberately NOT the caller-initiated entry point — the reconnect timer
     * must not reset the backoff budget it is computed from (ui consumer
     * lesson, same pattern as the syncplay socket).
     */
    private function connect(): void
    {
        if (!$this->opened || $this->closing) {
            return;
        }

        try {
            $token = ($this->tokenProvider)();
        } catch (\Throwable) {
            // A throwing provider (e.g. a mint failure) is a null token.
            $token = null;
        }

        if ($token === null || $token === '') {
            // No token is a RECONNECTABLE failure: re-read (re-mint) on the
            // capped backoff ladder instead of stalling the watch forever.
            // The ladder emits `exhausted` once the budget is spent.
            $this->scheduleReconnect();
            return;
        }

        $url = self::buildRelayUrl($this->hubBaseUrl, $this->serverId);
        $this->setStatus($this->reconnectAttempts > 0 ? 'reconnecting' : 'connecting');

        /** @var AsyncTcpConnection $socket */
        $socket = new AsyncTcpConnection($url);
        // S237/S298: the relay token travels in the upgrade request's
        // `Authorization: Bearer` header — the carrier the hub accepts and the
        // one a PHP Workerman client can set (the browser-only subprotocol
        // carrier is unnecessary here). Query-string tokens are refused by
        // design.
        $socket->headers = ['Authorization' => 'Bearer ' . $token];

        $socket->onWebSocketConnect = function (): void {
            // Measured OPEN: Workerman's client Ws protocol fired this only
            // after the 101 response arrived AND its Sec-WebSocket-Accept
            // hash matched the key we sent.
            $this->wsOpen = true;
            $this->reconnectAttempts = 0;
            $this->setStatus('open');
        };

        $socket->onMessage = function (AsyncTcpConnection $connection, string $data): void {
            $this->handleFrame($data);
        };

        $socket->onError = function (AsyncTcpConnection $connection, int $code, string $msg): void {
            // `onClose` follows `onError` for a failed socket; the ladder lives there.
        };

        $socket->onClose = function () use ($socket): void {
            if ($this->socket === $socket) {
                $this->socket = null;
            }
            $this->wsOpen = false;
            $this->setStatus('closed');
            $this->scheduleReconnect();
        };

        $this->socket = $socket;
        $socket->connect();
    }

    /**
     * Schedule the next reconnect attempt with exponential backoff.
     *
     * Capped and self-terminating: after {@see MAX_RECONNECT_ATTEMPTS} the
     * ladder gives up and emits the terminal `exhausted` status — the caller
     * re-invokes {@see open()} to restart it with a fresh budget.
     */
    private function scheduleReconnect(): void
    {
        if (!$this->opened || $this->closing) {
            return;
        }

        if ($this->reconnectAttempts >= self::MAX_RECONNECT_ATTEMPTS) {
            $this->setStatus('exhausted');
            return;
        }

        $delay = self::RECONNECT_BASE_DELAY_SECONDS * (2 ** $this->reconnectAttempts);
        $this->reconnectAttempts++;
        $this->setStatus('reconnecting');

        $this->reconnectTimerId = Timer::add($delay, function (): void {
            $this->reconnectTimerId = null;
            $this->connect();
        }, [], false);
    }

    /**
     * Route one inbound relay frame to the dispatch point.
     *
     * Frames that are not a valid `pending_command` / `play_media` are dropped
     * at the parse boundary. A throwing consumer must not kill the socket's
     * message handler — the hub keeps the connection either way.
     */
    private function handleFrame(string $raw): void
    {
        $command = self::parsePendingCommandFrame($raw);
        if ($command === null) {
            return;
        }

        try {
            ($this->onPendingCommand)($command);
        } catch (\Throwable) {
            // ignore consumer errors; the socket stays open
        }
    }

    private function setStatus(string $status): void
    {
        if ($this->onStatusChange !== null) {
            ($this->onStatusChange)($status);
        }
    }
}
