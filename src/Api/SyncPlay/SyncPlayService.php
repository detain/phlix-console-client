<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\SyncPlay;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\SyncPlayGroup;
use Phlix\Console\Api\Dto\SyncPlaySession;
use Phlix\Console\Api\Dto\SyncPlayPlaybackCommand;
use Phlix\Console\Api\Dto\SyncPlayUser;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Cmd;
use Workerman\Connection\AsyncTcpConnection;

/**
 * SyncPlay manager handling room lifecycle and WebSocket communication.
 *
 * Uses Workerman's AsyncTcpConnection for WebSocket communication with the
 * SyncPlay server. Owns the protocol state machine and time sync.
 *
 * Endpoint law (phlix-server): the SyncPlay WebSocket lives on the DEDICATED
 * plaintext worker at port {@see DEFAULT_WS_PORT} (:8097), NOT on the HTTP
 * API port (:8096) — the HTTP worker does not upgrade `/api/v1/*`. The
 * handshake requires a valid `?token=` query parameter (the server rejects
 * the upgrade pre-101 without it when JWT enforcement is on); the bearer
 * sub-protocol is tracked estate debt and deliberately NOT adopted here.
 *
 * Reconnect law: capped exponential backoff mirroring {@see HubRelayConsumer}
 * — base delay doubling per attempt, at most {@see MAX_RECONNECT_ATTEMPTS},
 * then a terminal stop until the room is (re)joined.
 */
final class SyncPlayService
{
    /**
     * Dedicated plaintext SyncPlay WebSocket port on phlix-server
     * (server `config/server.php` → `websocket.port`). Overridable per
     * server entry via {@see \Phlix\Console\Config\ServerEntry::$wsPort}.
     */
    public const DEFAULT_WS_PORT = 8097;

    /** Reconnect ladder budget — same idiom as {@see HubRelayConsumer}. */
    public const MAX_RECONNECT_ATTEMPTS = 5;

    /** First rung of the doubling reconnect ladder, in seconds. */
    public const RECONNECT_BASE_DELAY_SECONDS = 1.0;

    /** Protocol ping cadence while connected, in seconds. */
    private const TIME_SYNC_INTERVAL_SECONDS = 30.0;

    private ?SyncPlaySession $session = null;
    private ?SyncPlayGroup $currentRoom = null;
    private ?\Workerman\Connection\AsyncTcpConnection $wsConnection = null;
    private ?string $memberId = null;
    private ?string $memberName = null;
    private bool $isHost = false;

    /** @var list<SyncPlayUser> */
    private array $members = [];

    private string $playbackState = 'stopped';
    /** @internal */
    /** @phpstan-ignore-next-line property.onlyWritten */
    private int $_playbackPosition = 0;
    /** @internal */
    /** @phpstan-ignore-next-line property.onlyWritten */
    private string $_currentMediaId = '';

    private bool $connected = false;
    private bool $reconnecting = false;
    private int $reconnectAttempts = 0;
    private ?TimerInterface $reconnectTimer = null;
    private ?TimerInterface $pingTimer = null;

    /** @var \Closure(SyncPlayPlaybackCommand): void */
    private \Closure $onPlaybackCommand;

    /** @var \Closure(string, string): void */
    private \Closure $onError;

    /** @var \Closure(SyncPlayUser): void */
    private \Closure $onMemberJoined;

    /** @var \Closure(string): void */
    /** @internal */
    /** @phpstan-ignore-next-line property.onlyWritten */
    private \Closure $_onMemberLeft;

    /** @var \Closure(string): void */
    private \Closure $onHostChanged;

    /** @var \Closure(bool): void */
    private \Closure $onDisconnect;

    /** @var \Closure(): void */
    private \Closure $onGroupState;

    private ?LoopInterface $loop = null;
    private int $lastPingSendTime = 0;

    /** Time sync engine. */
    private TimeSync $timeSync;

    /**
     * WebSocket connection factory seam.
     *
     * Production default builds a real Workerman `AsyncTcpConnection`; tests
     * inject a recording stand-in so the REST→DTO→onConnect→frame path can be
     * driven end-to-end WITHOUT a socket (S414 wire-shape tests). The seam
     * replaces the connection object ONLY — every byte the captured frames
     * assert is built by the real {@see Framing} + real DTO fields.
     *
     * @var \Closure(string):AsyncTcpConnection
     */
    private \Closure $connectionFactory;

    /**
     * @param (callable(string):AsyncTcpConnection)|null $connectionFactory
     * @param int|null                                    $wsPort SyncPlay WebSocket port override;
     *                                                            null → {@see DEFAULT_WS_PORT}.
     */
    public function __construct(
        private readonly ApiClient $api,
        ?LoopInterface $loop = null,
        ?callable $connectionFactory = null,
        private readonly ?int $wsPort = null,
    ) {
        $this->loop = $loop;
        $this->timeSync = new TimeSync();
        $this->memberId = $this->generateMemberId();
        $this->connectionFactory = $connectionFactory !== null
            ? \Closure::fromCallable($connectionFactory)
            : static fn (string $url): AsyncTcpConnection => new AsyncTcpConnection($url);
    }

    // ---- Public API ----------------------------------------------------

    /**
     * Set the display name for this member.
     */
    public function setMemberName(string $name): void
    {
        $this->memberName = $name;
    }

    /**
     * Get the current session.
     */
    public function getSession(): ?SyncPlaySession
    {
        return $this->session;
    }

    /**
     * Get the current room.
     */
    public function getCurrentRoom(): ?SyncPlayGroup
    {
        return $this->currentRoom;
    }

    /**
     * Get the member id for this client.
     */
    public function getMemberId(): string
    {
        return $this->memberId ?? '';
    }

    /**
     * Check if currently in a SyncPlay room.
     */
    public function isInRoom(): bool
    {
        return $this->session !== null;
    }

    /**
     * Check if this client is the room host.
     */
    public function isHost(): bool
    {
        return $this->isHost;
    }

    /**
     * Get current members in the room.
     *
     * @return list<SyncPlayUser>
     */
    public function getMembers(): array
    {
        return $this->members;
    }

    /**
     * Get member count.
     */
    public function getMemberCount(): int
    {
        return count($this->members);
    }

    /**
     * Get current sync status string for UI display.
     */
    public function getSyncStatus(): string
    {
        if (!$this->isInRoom()) {
            return 'Not in room';
        }

        if (!$this->connected) {
            return 'Connecting...';
        }

        if ($this->playbackState === 'playing') {
            return 'Synced';
        }

        if ($this->playbackState === 'paused') {
            return 'Paused';
        }

        return 'Ready';
    }

    /**
     * Register callback for playback commands from other members.
     *
     * @param \Closure(SyncPlayPlaybackCommand): void $callback
     */
    public function onPlaybackCommand(\Closure $callback): void
    {
        $this->onPlaybackCommand = $callback;
    }

    /**
     * Register callback for errors.
     *
     * @param \Closure(string, string): void $callback (code, message)
     */
    public function onError(\Closure $callback): void
    {
        $this->onError = $callback;
    }

    /**
     * Register callback for member joined events.
     *
     * @param \Closure(SyncPlayUser): void $callback
     */
    public function onMemberJoined(\Closure $callback): void
    {
        $this->onMemberJoined = $callback;
    }

    /**
     * Register callback for member left events.
     *
     * @param \Closure(string): void $callback (member id)
     */
    public function onMemberLeft(\Closure $callback): void
    {
        $this->_onMemberLeft = $callback;
    }

    /**
     * Register callback for host changed events.
     *
     * @param \Closure(string): void $callback (new host id)
     */
    public function onHostChanged(\Closure $callback): void
    {
        $this->onHostChanged = $callback;
    }

    /**
     * Register callback for disconnect events.
     *
     * @param \Closure(bool): void $callback (was intentional)
     */
    public function onDisconnect(\Closure $callback): void
    {
        $this->onDisconnect = $callback;
    }

    /**
     * Register callback for group state sync events.
     *
     * @param \Closure(): void $callback
     */
    public function onGroupState(\Closure $callback): void
    {
        $this->onGroupState = $callback;
    }

    // ---- Room Management ------------------------------------------------

    /**
     * Create a new SyncPlay room.
     *
     * @return PromiseInterface<SyncPlaySession>
     */
    public function createRoom(string $name, bool $isPublic = true): PromiseInterface
    {
        return $this->api->createSyncPlayGroup($name, $isPublic)->then(function (SyncPlaySession $session) use ($name, $isPublic) {
            $this->session = $session;
            $this->currentRoom = new SyncPlayGroup($session->roomId, $name, $isPublic, 1);
            $this->isHost = true;
            $this->members = [
                new SyncPlayUser($this->memberId ?? '', $this->memberName ?? 'You', true),
            ];
            $this->playbackState = 'stopped';
            $this->startFreshLadder();

            return $this->connectWebSocket($session);
        });
    }

    /**
     * Join an existing SyncPlay room.
     *
     * @return PromiseInterface<SyncPlaySession>
     */
    public function joinRoom(string $roomId): PromiseInterface
    {
        return $this->api->joinSyncPlayGroup($roomId)->then(function (SyncPlaySession $session) {
            $this->session = $session;
            $this->isHost = false;
            $this->playbackState = 'stopped';
            $this->startFreshLadder();

            return $this->connectWebSocket($session);
        });
    }

    /**
     * Leave the current SyncPlay room.
     */
    public function leaveRoom(): void
    {
        if ($this->session === null) {
            return;
        }

        // Send leave message if connected
        if ($this->connected && $this->wsConnection !== null) {
            $leaveMessage = Framing::frame(Messages::TYPE_GROUP_LEAVE, [
                'group_id' => $this->session->roomId,
                'member_id' => $this->memberId ?? '',
            ]);

            try {
                $this->wsConnection->send($leaveMessage);
            } catch (\Throwable) {
                // Ignore send errors during disconnect
            }
        }

        $this->disconnectWebSocket();
        $this->session = null;
        $this->currentRoom = null;
        $this->members = [];
        $this->isHost = false;
        $this->playbackState = 'stopped';
        $this->_playbackPosition = 0;
        $this->_currentMediaId = '';
    }

    /**
     * List public rooms.
     *
     * @return PromiseInterface<list<SyncPlayGroup>>
     */
    public function listRooms(): PromiseInterface
    {
        return $this->api->listSyncPlayGroups();
    }

    // ---- Playback Commands (Host Only) ----------------------------------

    /**
     * Send a play command to all members.
     *
     * @param int $position Position in milliseconds
     */
    public function sendPlay(int $position): void
    {
        if (!$this->isHost || !$this->connected) {
            return;
        }

        $serverTime = $this->timeSync->getSynchronizedTime();

        $message = Framing::frame(Messages::TYPE_PLAYBACK_PLAY, [
            'group_id' => $this->session->roomId ?? '',
            'member_id' => $this->memberId ?? '',
            'position' => $position,
            'server_time' => $serverTime,
        ]);

        $this->wsConnection?->send($message);
    }

    /**
     * Send a pause command to all members.
     *
     * @param int $position Position in milliseconds
     */
    public function sendPause(int $position): void
    {
        if (!$this->isHost || !$this->connected) {
            return;
        }

        $serverTime = $this->timeSync->getSynchronizedTime();

        $message = Framing::frame(Messages::TYPE_PLAYBACK_PAUSE, [
            'group_id' => $this->session->roomId ?? '',
            'member_id' => $this->memberId ?? '',
            'position' => $position,
            'server_time' => $serverTime,
        ]);

        $this->wsConnection?->send($message);
    }

    /**
     * Send a seek command to all members.
     *
     * @param int $fromPosition Position being seeked from (ms)
     * @param int $toPosition Target position (ms)
     */
    public function sendSeek(int $fromPosition, int $toPosition): void
    {
        if (!$this->isHost || !$this->connected) {
            return;
        }

        $serverTime = $this->timeSync->getSynchronizedTime();

        $message = Framing::frame(Messages::TYPE_PLAYBACK_SEEK, [
            'group_id' => $this->session->roomId ?? '',
            'member_id' => $this->memberId ?? '',
            'from_position' => $fromPosition,
            'to_position' => $toPosition,
            'server_time' => $serverTime,
        ]);

        $this->wsConnection?->send($message);
    }

    // ---- Internal -------------------------------------------------------

    /**
     * Connect to the SyncPlay WebSocket relay.
     *
     * @return PromiseInterface<SyncPlaySession>
     */
    private function connectWebSocket(SyncPlaySession $session): PromiseInterface
    {
        $deferred = new Deferred();

        // Build WebSocket URL
        $wsUrl = $this->buildWebSocketUrl($session);

        // Create async TCP connection (Workerman-style; via the injectable
        // seam — S414. Production path constructs the identical object).
        $conn = ($this->connectionFactory)($wsUrl);
        $this->wsConnection = $conn;

        // Set up handlers. onClose/onError carry the connection as Workerman's
        // first argument — a handler from a socket the service has already
        // replaced (or torn down on purpose) is a stale echo and is ignored,
        // so old sockets can never resurrect the ladder for a newer one.
        $conn->onConnect = function () use ($deferred, $session): void {
            $this->connected = true;
            $this->reconnecting = false;
            $this->reconnectAttempts = 0;
            $this->cancelReconnectTimer();

            // Start time sync ping loop
            $this->startTimeSyncPing();

            // Join the group
            $joinMessage = Framing::frame(Messages::TYPE_GROUP_JOIN, [
                'group_id' => $session->roomId,
                'member_id' => $this->memberId ?? '',
                'member_name' => $this->memberName ?? 'Anonymous',
            ]);

            $this->wsConnection?->send($joinMessage);
            $deferred->resolve($session);
        };

        $conn->onMessage = function (string $_, string $data): void {
            $this->handleMessage($data);
        };

        $conn->onError = function (\Throwable $e) use ($deferred, $conn): void {
            if ($conn !== $this->wsConnection) {
                return;
            }

            $this->connected = false;
            ($this->onError ?? fn () => null)('websocket_error', $e->getMessage());

            try {
                $deferred->reject(new \RuntimeException('WebSocket connection failed: ' . $e->getMessage()));
            } catch (\Throwable) {
                // Already resolved, ignore
            }

            $this->attemptReconnect();
        };

        $conn->onClose = function () use ($conn): void {
            if ($conn !== $this->wsConnection) {
                return;
            }

            $this->connected = false;
            $this->wsConnection = null;
            ($this->onDisconnect ?? fn () => null)($this->reconnecting);

            $this->attemptReconnect();
        };

        // Connect asynchronously
        $conn->connect();

        /** @var PromiseInterface<SyncPlaySession> */
        return $deferred->promise();
    }

    /**
     * Tear the socket down on purpose: cancel the ladder, silence the
     * handlers, close. Intentional exits must never re-arm a reconnect
     * loop nor surface a 'disconnected' event for our own goodbyes.
     */
    private function disconnectWebSocket(): void
    {
        $this->reconnecting = false;
        $this->cancelReconnectTimer();
        $this->cancelPingTimer();

        $conn = $this->wsConnection;
        $this->wsConnection = null;

        if ($conn !== null) {
            $conn->onMessage = null;
            $conn->onError = null;
            $conn->onClose = null;

            try {
                $conn->close();
            } catch (\Throwable) {
                // Ignore close errors
            }
        }

        $this->connected = false;
    }

    /**
     * Arm a fresh reconnect budget for a (re)joined room.
     */
    private function startFreshLadder(): void
    {
        $this->cancelReconnectTimer();
        $this->reconnectAttempts = 0;
        $this->reconnecting = false;
    }

    /**
     * Attempt to reconnect after an unexpected disconnect — capped
     * exponential ladder (1s, 2s, 4s, …), same budget idiom as
     * {@see HubRelayConsumer}. Exhaustion fails loud, not forever.
     */
    private function attemptReconnect(): void
    {
        if ($this->session === null || $this->reconnectTimer !== null) {
            return;
        }

        if ($this->reconnectAttempts >= self::MAX_RECONNECT_ATTEMPTS) {
            $this->reconnecting = false;
            ($this->onError ?? fn () => null)(
                'reconnect_exhausted',
                sprintf('SyncPlay reconnect stopped after %d failed attempts', self::MAX_RECONNECT_ATTEMPTS),
            );

            return;
        }

        if ($this->loop === null) {
            // No scheduler wired — honest no-op (App injects the global
            // react loop; a service built without one simply never retries).
            return;
        }

        $this->reconnecting = true;
        $delay = self::RECONNECT_BASE_DELAY_SECONDS * (2 ** $this->reconnectAttempts);
        $this->reconnectAttempts++;

        $this->reconnectTimer = $this->loop->addTimer($delay, function (): void {
            $this->reconnectTimer = null;

            if ($this->session === null || $this->reconnecting === false) {
                return;
            }

            /** @var SyncPlaySession $session */
            $session = $this->session;
            $this->wsConnection = null;
            $this->connectWebSocket($session);
        });
    }

    private function cancelReconnectTimer(): void
    {
        if ($this->loop !== null && $this->reconnectTimer !== null) {
            $this->loop->cancelTimer($this->reconnectTimer);
        }

        $this->reconnectTimer = null;
    }

    private function cancelPingTimer(): void
    {
        if ($this->loop !== null && $this->pingTimer !== null) {
            $this->loop->cancelTimer($this->pingTimer);
        }

        $this->pingTimer = null;
    }

    /**
     * Build the WebSocket URL for a session.
     *
     * Scheme and host follow the configured server base; the PORT is the
     * dedicated SyncPlay worker ({@see DEFAULT_WS_PORT}, overridable per
     * server entry) — never the API port baked into serverUrl, because the
     * :8096 HTTP worker does not perform the WebSocket upgrade. The
     * `?token=` query carrier is current phlix-server handshake law.
     */
    private function buildWebSocketUrl(SyncPlaySession $session): string
    {
        $scheme = str_starts_with($session->serverUrl, 'https://') ? 'wss://' : 'ws://';
        $host = parse_url($session->serverUrl, PHP_URL_HOST) ?? 'localhost';
        $port = $this->wsPort ?? self::DEFAULT_WS_PORT;

        return sprintf(
            '%s%s:%d/syncplay/%s?token=%s',
            $scheme,
            $host,
            $port,
            rawurlencode($session->roomId),
            urlencode($this->getAuthToken()),
        );
    }

    /**
     * Get the auth token for WebSocket connection.
     */
    private function getAuthToken(): string
    {
        // Get token from ApiClient
        $token = $this->api->token();

        if ($token === null) {
            return '';
        }

        // Return the access token
        return $token->accessToken;
    }

    /**
     * Handle an incoming WebSocket message.
     */
    private function handleMessage(string $raw): void
    {
        try {
            $message = Framing::decode($raw);
        } catch (\Throwable) {
            return; // Ignore malformed messages
        }

        $type = $message['type'] ?? '';

        switch ($type) {
            case Messages::TYPE_GROUP_STATE:
                $this->handleGroupState($message);
                break;

            case Messages::TYPE_TIME_PONG:
                $this->handleTimePong($message);
                break;

            case Messages::TYPE_TIME_SYNC:
                $this->handleTimeSync($message);
                break;

            case Messages::TYPE_PLAYBACK_PLAY:
            case Messages::TYPE_PLAYBACK_PAUSE:
            case Messages::TYPE_PLAYBACK_SEEK:
                $this->handlePlaybackCommand($message);
                break;

            case Messages::TYPE_ERROR:
            case Messages::LEGACY_TYPE_ERROR:
                $this->handleError($message);
                break;

            case Messages::TYPE_INFO:
                $this->handleInfo($message);
                break;

            case Messages::TYPE_HOST_ELECT:
                $this->handleHostElect($message);
                break;
        }
    }

    /**
     * Handle GROUP_STATE message - full group sync.
     * @param array<string, mixed> $message
     */
    private function handleGroupState(array $message): void
    {
        $group = $message['group'] ?? [];
        if (!is_array($group)) {
            return;
        }

        // Update room info
        $currentMediaId = $group['current_media_id'] ?? '';
        $this->_currentMediaId = is_string($currentMediaId) ? $currentMediaId : '';
        $playbackPosition = $group['playback_position'] ?? 0;
        $this->_playbackPosition = is_int($playbackPosition) ? $playbackPosition : 0;
        $playbackState = $group['playback_state'] ?? 'stopped';
        $this->playbackState = is_string($playbackState) ? $playbackState : 'stopped';

        // Update members
        $membersData = $group['members'] ?? [];
        if (is_array($membersData)) {
            $this->members = [];
            foreach ($membersData as $m) {
                $this->members[] = SyncPlayUser::fromArray(is_array($m) ? $m : []);
            }
        }

        // Update host status
        $hostId = $group['host_id'] ?? null;
        $this->isHost = ($hostId === $this->memberId);

        // Update member count
        if ($this->currentRoom !== null) {
            $memberCount = is_int($group['member_count'] ?? null) ? $group['member_count'] : count($this->members);
            $this->currentRoom = new SyncPlayGroup(
                $this->currentRoom->id,
                $this->currentRoom->name,
                $this->currentRoom->isPublic,
                $memberCount,
            );
        }

        ($this->onGroupState ?? fn () => null)();
    }

    /**
     * Handle TIME_PONG message - update time sync.
     * @param array<string, mixed> $message
     */
    private function handleTimePong(array $message): void
    {
        $clientTime = is_int($message['client_time']) ? $message['client_time'] : 0;
        $serverTime = is_int($message['server_time']) ? $message['server_time'] : 0;

        /** @var int */
        $clientTimeInt = $clientTime;
        /** @var int */
        $serverTimeInt = $serverTime;

        $this->timeSync->processPong($clientTimeInt, $serverTimeInt);
    }

    /**
     * Handle TIME_SYNC message - server-initiated drift correction.
     * @param array<string, mixed> $message
     */
    private function handleTimeSync(array $message): void
    {
        $serverTime = is_int($message['server_time']) ? $message['server_time'] : 0;
        $clientTime = is_int($message['client_time']) ? $message['client_time'] : 0;

        /** @var int */
        $serverTimeInt = $serverTime;
        /** @var int */
        $clientTimeInt = $clientTime;

        $this->timeSync->applyDriftCorrection($serverTimeInt, $clientTimeInt);
    }

    /**
     * Handle a playback command from the host.
     * @param array<string, mixed> $message
     */
    private function handlePlaybackCommand(array $message): void
    {
        $type = match ($message['type']) {
            Messages::TYPE_PLAYBACK_PAUSE => 'pause',
            Messages::TYPE_PLAYBACK_SEEK => 'seek',
            default => 'play',
        };

        $command = SyncPlayPlaybackCommand::fromArray($message);
        ($this->onPlaybackCommand ?? fn () => null)($command);
    }

    /**
     * Handle an error frame.
     *
     * Per SPEC.md read-order doctrine, server `syncplay_error` frames carry
     * `error_code` (via Messages::error()) while legacy sendError() frames
     * carry `code` — so `error_code` wins, `code` is the fallback. The
     * message stays raw server English here; an empty string is passed when
     * absent so the presentation layer can localize the generic fallback.
     * @param array<string, mixed> $message
     */
    private function handleError(array $message): void
    {
        $code = $message['error_code'] ?? $message['code'] ?? 'unknown';
        $errorMsg = $message['message'] ?? $this->legacyEnvelopeMessage($message) ?? '';

        $codeStr = is_string($code) ? $code : (is_int($code) ? (string) $code : 'unknown');
        $errorStr = is_string($errorMsg) ? $errorMsg : '';
        ($this->onError ?? fn () => null)($codeStr, $errorStr);
    }

    /**
     * Extract the message text from a deprecated `{type:'error', data:{...}}`
     * envelope, if present.
     * @param array<string, mixed> $message
     */
    private function legacyEnvelopeMessage(array $message): ?string
    {
        $data = $message['data'] ?? null;

        if (!is_array($data)) {
            return null;
        }

        $inner = $data['message'] ?? null;

        return is_string($inner) ? $inner : null;
    }

    /**
     * Handle INFO message - member join notifications.
     * @param array<string, mixed> $message
     */
    private function handleInfo(array $message): void
    {
        // Check for member joined info
        $memberId = $message['member_id'] ?? null;
        $memberName = $message['member_name'] ?? null;

        if ($memberId !== null && $memberName !== null) {
            $memberIdStr = is_string($memberId) ? $memberId : (is_int($memberId) ? (string) $memberId : '');
            $memberNameStr = is_string($memberName) ? $memberName : '';
            $user = new SyncPlayUser($memberIdStr, $memberNameStr);
            $this->members[] = $user;
            ($this->onMemberJoined ?? fn () => null)($user);
        }
    }

    /**
     * Handle HOST_ELECT message - host transfer.
     * @param array<string, mixed> $message
     */
    private function handleHostElect(array $message): void
    {
        $newHostId = $message['elected_id'] ?? null;
        if ($newHostId !== null) {
            $this->isHost = ($newHostId === $this->memberId);
            $newHostIdStr = is_string($newHostId) ? $newHostId : (is_int($newHostId) ? (string) $newHostId : '');
            ($this->onHostChanged ?? fn () => null)($newHostIdStr);
        }
    }

    /**
     * Start the periodic time sync ping.
     *
     * Single-arm: the timer survives reconnects (it self-guards on
     * `connected`), so a reconnect ladder must never stack copies of it.
     */
    private function startTimeSyncPing(): void
    {
        if ($this->loop === null || $this->pingTimer !== null) {
            return;
        }

        $this->pingTimer = $this->loop->addPeriodicTimer(self::TIME_SYNC_INTERVAL_SECONDS, function (): void {
            if (!$this->connected || $this->session === null) {
                return;
            }

            $this->lastPingSendTime = (int) (microtime(true) * 1000);

            $pingMessage = Framing::frame(Messages::TYPE_TIME_PING, [
                'client_time' => $this->lastPingSendTime,
            ]);

            $this->wsConnection?->send($pingMessage);
        });
    }

    /**
     * Generate a stable member ID for this client.
     */
    private function generateMemberId(): string
    {
        return sprintf(
            'console-%s-%s',
            substr(sha1((string) gethostname()), 0, 8),
            substr(bin2hex(random_bytes(4)), 0, 8),
        );
    }
}
