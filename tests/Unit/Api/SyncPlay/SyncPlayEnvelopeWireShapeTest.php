<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit\Api\SyncPlay;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\SyncPlayGroup;
use Phlix\Console\Api\Dto\SyncPlaySession;
use Phlix\Console\Api\SyncPlay\Framing;
use Phlix\Console\Api\SyncPlay\Messages;
use Phlix\Console\Api\SyncPlay\SyncPlayService;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\AsyncTcpConnection;

/**
 * S414 wire-shape gate — the console's SyncPlay DTOs must unwrap the envelope
 * the server ACTUALLY emits.
 *
 * Authority ruling (phlix-server `01340633`, live paths only — re-verified at
 * dispatch): create/join emit `{success:true, group:{…}}` with the
 * `GroupState::getState()` payload (identity key `group_id`); list rows are
 * `{groups:[{id, name, member_count, has_password, current_media,
 * is_playing}]}`. Top-level `room_id`/`session_id`/`server_url` are emitted by
 * NOTHING live (they only ever lived in the dead `WebSocket/SyncPlay` classes)
 * — the pre-S414 DTOs parsed exactly those, so `roomId` was always `''` and
 * every WS join frame carried `group_id => ''`.
 *
 * The envelope bytes below are COPIED VERBATIM from phlix-contracts
 * `test/fixtures/syncplay-envelope-vectors.json` (rails `createGroup` and
 * `listGroups`) — the real responses captured from the real
 * SyncPlayController/manager/snapshot-service at `01340633` by
 * `scripts/dump-server-syncplay-vectors.php` (S345 law: feed the REAL
 * envelope bytes, never mocks-of-own-shape — the S406 lesson).
 *
 * The frames asserted here are built by the REAL frame builders
 * (`Framing::frame` inside `SyncPlayService`'s onConnect/leave paths); the
 * injected stand-in replaces ONLY the socket object.
 */
final class SyncPlayEnvelopeWireShapeTest extends TestCase
{
    /** The real group id from the S415 golden vector. */
    private const REAL_GROUP_ID = 'sp_cca927fbf4ba11f9';

    /** @return array<string,mixed> */
    private static function createEnvelope(): array
    {
        return json_decode(<<<'JSON'
        {"success": true, "group": {"group_id": "sp_cca927fbf4ba11f9", "group_name": "Movie Night", "member_count": 1, "members": {"member_host": {"id": "member_host", "name": "Host One", "is_host": true, "joined_at": 1788300111}}, "host_id": "member_host", "current_media_id": null, "current_media_duration": 0, "playback_position": 0, "playback_state": "stopped", "queue": [], "created_at": 1788300111, "last_activity_at": 1788300111}}
        JSON, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private static function listEnvelope(): array
    {
        return json_decode(<<<'JSON'
        {"groups": [{"id": "sp_cca927fbf4ba11f9", "name": "Movie Night", "member_count": 2, "has_password": true, "current_media": null, "is_playing": false}]}
        JSON, true, 512, JSON_THROW_ON_ERROR);
    }

    // ---- DTO boundary ------------------------------------------------------

    public function testSessionUnwrapsTheGroupEnvelopeToTheRealGroupId(): void
    {
        $session = SyncPlaySession::fromArray(self::createEnvelope(), 'https://srv');

        self::assertSame(self::REAL_GROUP_ID, $session->roomId, 'roomId must ride group.group_id — never the empty string the phantom room_id read produced');
        self::assertSame('https://srv', $session->serverUrl, 'serverUrl is the DERIVED configured base, not a wire field');
    }

    public function testSessionFailsClosedOnThePhantomTopLevelKeys(): void
    {
        // The pre-S414 INPUT fiction: top-level room_id/session_id/server_url
        // (never emitted live). The DTO must refuse to synthesize roomId from
        // them — planted-broken guard against re-reading phantom keys.
        $session = SyncPlaySession::fromArray([
            'room_id' => 'phantom',
            'session_id' => 'phantom',
            'server_url' => 'wss://phantom',
        ], 'https://srv');

        self::assertSame('', $session->roomId, 'a payload with no group block carries NO room — fail closed');
        self::assertSame('https://srv', $session->serverUrl);
    }

    public function testIsPublicIsTheInvertedHasPasswordBothDirections(): void
    {
        $locked = SyncPlayGroup::fromArray(self::listEnvelope()['groups'][0]);
        self::assertFalse($locked->isPublic, 'has_password=true ⇒ NOT public (the S414 bug resolved every group public)');

        $open = SyncPlayGroup::fromArray([
            'id' => 'g2', 'name' => 'Open', 'member_count' => 1,
            'has_password' => false, 'current_media' => null, 'is_playing' => true,
        ]);
        self::assertTrue($open->isPublic, 'has_password=false ⇒ public');

        // The honest absent-key decision (docblock): the row never claims
        // privacy, so it reads public — and the retired `is_public` wire key
        // (never emitted) must NOT steer it.
        $absent = SyncPlayGroup::fromArray(['id' => 'g3', 'name' => 'X', 'is_public' => false]);
        self::assertTrue($absent->isPublic, 'absent has_password ⇒ public; the phantom is_public key is ignored');
    }

    // ---- ApiClient over the real envelopes ---------------------------------

    public function testCreateSyncPlayGroupResolvesTheRealIdFromFakeTransport(): void
    {
        $transport = (new FakeTransport())->json(200, self::createEnvelope());
        $api = new ApiClient('https://srv', $transport);

        $resolved = null;
        $api->createSyncPlayGroup('Movie Night')->then(
            static function (SyncPlaySession $s) use (&$resolved): void {
                $resolved = $s;
            },
        );

        self::assertInstanceOf(SyncPlaySession::class, $resolved);
        self::assertSame(self::REAL_GROUP_ID, $resolved->roomId);
        self::assertSame('https://srv', $resolved->serverUrl, 'derived from the configured base (no wire field exists)');
    }

    public function testListSyncPlayGroupsMapsTheRealListRow(): void
    {
        $transport = (new FakeTransport())->json(200, self::listEnvelope());
        $api = new ApiClient('https://srv', $transport);

        $groups = null;
        $api->listSyncPlayGroups()->then(
            static function (array $g) use (&$groups): void {
                $groups = $g;
            },
        );

        self::assertIsArray($groups);
        self::assertCount(1, $groups);
        self::assertSame(self::REAL_GROUP_ID, $groups[0]->id);
        self::assertFalse($groups[0]->isPublic, 'the listed password group must NOT masquerade public');
    }

    // ---- create/join → FRAME BUILDER (no client mock) ----------------------

    /**
     * A recording stand-in for the socket OBJECT ONLY (the class Workerman
     * would connect with). Constructed without touching the network: the
     * frames it records are built entirely by the production code path
     * (`Framing::frame(...)` on the DTO-parsed session fields).
     */
    private static function recordingConnection(): AsyncTcpConnection
    {
        return new class () extends AsyncTcpConnection {
            /** @var list<string> */
            public array $sent = [];

            public function __construct()
            {
                // Deliberately skips parent::__construct — no socket, no DNS,
                // no event loop. Only send()/onConnect participate below.
            }

            public function send(mixed $sendBuffer, bool $raw = false): bool|null
            {
                $this->sent[] = (string) $sendBuffer;

                return true;
            }

            public function connect(): void
            {
                // No event loop in tests — the onConnect callback is driven
                // manually by the test, exactly as Workerman would.
            }

            public function close(mixed $data = null, bool $raw = false): void
            {
            }

            public function destroy(): void
            {
            }
        };
    }

    public function testCreateRoomDrivesTheRealEnvelopeIntoTheJoinFrameBuilder(): void
    {
        $transport = (new FakeTransport())->json(200, self::createEnvelope());
        $api = new ApiClient('https://srv', $transport);

        $urlRef = null;
        $sinkRef = null;
        $service = new SyncPlayService($api, null, static function (string $url) use (&$urlRef, &$sinkRef): AsyncTcpConnection {
            $urlRef = $url;
            $sinkRef = self::recordingConnection();

            return $sinkRef;
        });

        $service->createRoom('Movie Night');

        // createRoom resolves through connectWebSocket's Deferred ONLY when
        // onConnect fires — drive the recorded callback the way Workerman
        // would, then observe the frames the REAL builder produced.
        self::assertNotNull($sinkRef, 'the injected factory built the connection stand-in');
        $onConnect = $sinkRef->onConnect;
        self::assertIsCallable($onConnect, 'the production path registered onConnect');
        $onConnect($sinkRef);

        self::assertNotSame('', (string) $urlRef);
        self::assertStringStartsWith('wss://srv/api/v1/syncplay/', (string) $urlRef, 'serverUrl DERIVED from the configured base makes the WS URL absolute (empty wire fiction produced a relative junk URL)');
        self::assertStringContainsString(rawurlencode(self::REAL_GROUP_ID), (string) $urlRef);

        $frames = $sinkRef->sent ?? [];
        self::assertNotEmpty($frames, 'onConnect must send the join frame');

        $join = json_decode($frames[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(Messages::TYPE_GROUP_JOIN, $join['type'] ?? null);
        self::assertSame(
            self::REAL_GROUP_ID,
            $join['group_id'] ?? null,
            'THE S414 bug: the join frame carried group_id => "" because the DTO read a phantom room_id; it must carry the REAL group id from the envelope',
        );

        // The leave frame rides the same session field.
        $service->leaveRoom();
        $leave = json_decode((string) ($sinkRef->sent[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(Messages::TYPE_GROUP_LEAVE, $leave['type'] ?? null);
        self::assertSame(self::REAL_GROUP_ID, $leave['group_id'] ?? null);

        self::assertSame($transport->requestCount(), 1, 'createRoom issued exactly one REST call');
        self::assertStringEndsWith('/api/v1/syncplay/groups', $transport->requestAt(0)['url']);
        // Framing bytes stay parseable JSON with the type FIRST key (wire law).
        self::assertSame('type', array_keys((array) json_decode((string) $frames[0], true))[0]);
        unset($frames, $join, $leave);
    }

    public function testJoinFrameGroupMatchesFramingDecoder(): void
    {
        // Belt for the belt: decode the recorded bytes through the console's
        // OWN Framing::decode to prove the frame is well-formed end-to-end.
        $envelope = self::createEnvelope();
        self::assertSame(self::REAL_GROUP_ID, $envelope['group']['group_id']);

        $frame = Framing::frame(Messages::TYPE_GROUP_JOIN, ['group_id' => SyncPlaySession::fromArray($envelope, 'https://srv')->roomId]);
        $decoded = Framing::decode($frame);

        self::assertSame(Messages::TYPE_GROUP_JOIN, $decoded['type'] ?? null);
        self::assertSame(self::REAL_GROUP_ID, $decoded['group_id'] ?? null);
    }
}
