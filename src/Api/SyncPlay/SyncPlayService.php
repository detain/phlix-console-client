<?php

declare(strict_types=1);

namespace Phlix\Console\Api\SyncPlay;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\SyncPlayRoom;
use Phlix\Console\Api\Dto\SyncPlaySession;
use Phlix\Console\Api\Dto\SyncPlayPlaybackCommand;
use Phlix\Console\Api\Dto\SyncPlayUser;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Cmd;

/**
 * SyncPlay manager handling room lifecycle and WebSocket communication.
 *
 * Uses Workerman's AsyncTcpConnection for WebSocket communication with the
 * SyncPlay relay server. Owns the protocol state machine and time sync.
 */
final class SyncPlayService
{
    private ?SyncPlaySession $session = null;
    private ?SyncPlayRoom $currentRoom = null;
    private ?\Workerman\Connection\AsyncTcpConnection $wsConnection = null;
    private ?string $memberId = null;
    private ?string $memberName = null;
    private bool $isHost = false;

    /** @var list<SyncPlayUser> */
    private array $members = [];

    private string $playbackState = 'stopped';
    private int $playbackPosition = 0;
    private string $currentMediaId = '';

    private bool $connected = false;
    private bool $reconnecting = false;

    /** @var \Closure(SyncPlayPlaybackCommand): void */
    private \Closure $onPlaybackCommand;

    /** @var \Closure(string, string): void */
    private \Closure $onError;

    /** @var \Closure(SyncPlayUser): void */
    private \Closure $onMemberJoined;

    /** @var \Closure(string): void */
    private \Closure $onMemberLeft;

    /** @var \Closure(string): void */
    private \Closure $onHostChanged;

    /** @var \Closure(bool): void */
    private \Closure $onDisconnect;

    private ?LoopInterface $loop = null;
    private int $lastPingSendTime = 0;

    /** Time sync engine. */
    private TimeSync $timeSync;

    public function __construct(
        private readonly ApiClient $api,
        ?LoopInterface $loop = null,
    ) {
        $this->loop = $loop;
        $this->timeSync = new TimeSync();
        $this->memberId = $this->generateMemberId();
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
    public function getCurrentRoom(): ?SyncPlayRoom
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
        $this->onMemberLeft = $callback;
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

    // ---- Room Management ------------------------------------------------

    /**
     * Create a new SyncPlay room.
     *
     * @return PromiseInterface<SyncPlaySession>
     */
    public function createRoom(string $name, bool $isPublic = true): PromiseInterface
    {
        return $this->api->createSyncPlayRoom($name, $isPublic)->then(function (SyncPlaySession $session) use ($name) {
            $this->session = $session;
            $this->currentRoom = new SyncPlayRoom($session->roomId, $name, $isPublic, 1);
            $this->isHost = true;
            $this->members = [
                new SyncPlayUser($this->memberId ?? '', $this->memberName ?? 'You', true),
            ];
            $this->playbackState = 'stopped';

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
        return $this->api->joinSyncPlayRoom($roomId)->then(function (SyncPlaySession $session) {
            $this->session = $session;
            $this->isHost = false;
            $this->playbackState = 'stopped';

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

        $this->disconnectWebSocket(false);
        $this->session = null;
        $this->currentRoom = null;
        $this->members = [];
        $this->isHost = false;
        $this->playbackState = 'stopped';
        $this->playbackPosition = 0;
        $this->currentMediaId = '';
    }

    /**
     * List public rooms.
     *
     * @return PromiseInterface<list<SyncPlayRoom>>
     */
    public function listRooms(): PromiseInterface
    {
        return $this->api->listSyncPlayRooms();
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
            'group_id' => $this->session?->roomId ?? '',
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
            'group_id' => $this->session?->roomId ?? '',
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
            'group_id' => $this->session?->roomId ?? '',
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

        // Create async TCP connection (Workerman-style)
        $this->wsConnection = new \Workerman\Connection\AsyncTcpConnection($wsUrl);

        // Set up handlers
        $this->wsConnection->onConnect = function () use ($deferred, $session): void {
            $this->connected = true;
            $this->reconnecting = false;

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

        $this->wsConnection->onMessage = function (string $_, string $data): void {
            $this->handleMessage($data);
        };

        $this->wsConnection->onError = function (\Throwable $e) use ($deferred): void {
            $this->connected = false;
            ($this->onError ?? fn () => null)('websocket_error', $e->getMessage());

            if (!$deferred->isResolved()) {
                $deferred->reject(new \RuntimeException('WebSocket connection failed: ' . $e->getMessage()));
            }

            $this->attemptReconnect();
        };

        $this->wsConnection->onClose = function (): void {
            $this->connected = false;
            ($this->onDisconnect ?? fn () => null)($this->reconnecting);

            if (!$this->reconnecting) {
                $this->attemptReconnect();
            }
        };

        // Connect asynchronously
        $this->wsConnection->connect();

        return $deferred->promise();
    }

    /**
     * Disconnect from the WebSocket.
     */
    private function disconnectWebSocket(bool $isIntentional = true): void
    {
        if ($isIntentional) {
            $this->reconnecting = false;
        }

        if ($this->wsConnection !== null) {
            try {
                $this->wsConnection->close();
            } catch (\Throwable) {
                // Ignore close errors
            }
            $this->wsConnection = null;
        }

        $this->connected = false;
    }

    /**
     * Attempt to reconnect after a disconnect.
     */
    private function attemptReconnect(): void
    {
        if ($this->reconnecting || $this->session === null) {
            return;
        }

        $this->reconnecting = true;

        // Schedule reconnect after delay
        if ($this->loop !== null) {
            $loop = $this->loop;
            $loop->addTimer(3.0, function () use ($loop): void {
                if ($this->session === null || $this->reconnecting === false) {
                    return;
                }

                $session = $this->session;
                $this->wsConnection = null;
                $this->connectWebSocket($session);
            });
        }
    }

    /**
     * Build the WebSocket URL for a session.
     */
    private function buildWebSocketUrl(SyncPlaySession $session): string
    {
        // Replace http(s) with ws(s) and append the path
        $url = $session->serverUrl;
        $url = preg_replace('/^https:/', 'wss:', $url);
        $url = preg_replace('/^http:/', 'ws:', $url);
        $url = rtrim($url, '/');

        return $url . '/api/v1/syncplay/' . rawurlencode($session->roomId) . '?token=' . urlencode($this->getAuthToken());
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
        return $token->accessToken ?? '';
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
     */
    private function handleGroupState(array $message): void
    {
        $group = $message['group'] ?? [];

        // Update room info
        $this->currentMediaId = $group['current_media_id'] ?? '';
        $this->playbackPosition = $group['playback_position'] ?? 0;
        $this->playbackState = $group['playback_state'] ?? 'stopped';

        // Update members
        $membersData = $group['members'] ?? [];
        $this->members = [];
        foreach ($membersData as $m) {
            $this->members[] = SyncPlayUser::fromArray($m);
        }

        // Update host status
        $hostId = $group['host_id'] ?? null;
        $this->isHost = ($hostId === $this->memberId);

        // Update member count
        if ($this->currentRoom !== null) {
            $memberCount = $group['member_count'] ?? count($this->members);
            $this->currentRoom = new SyncPlayRoom(
                $this->currentRoom->id,
                $this->currentRoom->name,
                $this->currentRoom->isPublic,
                $memberCount,
            );
        }
    }

    /**
     * Handle TIME_PONG message - update time sync.
     */
    private function handleTimePong(array $message): void
    {
        $clientTime = $message['client_time'] ?? 0;
        $serverTime = $message['server_time'] ?? 0;

        $this->timeSync->processPong($clientTime, $serverTime);
    }

    /**
     * Handle TIME_SYNC message - server-initiated drift correction.
     */
    private function handleTimeSync(array $message): void
    {
        $serverTime = $message['server_time'] ?? 0;
        $clientTime = $message['client_time'] ?? 0;

        $this->timeSync->applyDriftCorrection($serverTime, $clientTime);
    }

    /**
     * Handle a playback command from the host.
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
     * Handle ERROR message.
     */
    private function handleError(array $message): void
    {
        $code = $message['code'] ?? $message['error_code'] ?? 'unknown';
        $errorMsg = $message['message'] ?? 'Unknown error';

        ($this->onError ?? fn () => null)((string) $code, (string) $errorMsg);
    }

    /**
     * Handle INFO message - member join notifications.
     */
    private function handleInfo(array $message): void
    {
        // Check for member joined info
        $memberId = $message['member_id'] ?? null;
        $memberName = $message['member_name'] ?? null;

        if ($memberId !== null && $memberName !== null) {
            $user = new SyncPlayUser((string) $memberId, (string) $memberName);
            $this->members[] = $user;
            ($this->onMemberJoined ?? fn () => null)($user);
        }
    }

    /**
     * Handle HOST_ELECT message - host transfer.
     */
    private function handleHostElect(array $message): void
    {
        $newHostId = $message['elected_id'] ?? null;
        if ($newHostId !== null) {
            $this->isHost = ($newHostId === $this->memberId);
            ($this->onHostChanged ?? fn () => null)((string) $newHostId);
        }
    }

    /**
     * Start the periodic time sync ping.
     */
    private function startTimeSyncPing(): void
    {
        if ($this->loop === null) {
            return;
        }

        $loop = $this->loop;
        $loop->addPeriodicTimer(30.0, function () use ($loop): void {
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
