<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Hub;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Hub\HubClient;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

/**
 * Wire-shape pins for the hub surface at the CLIENT seam — the request body
 * that crosses the transport, not just method+URL [review w01L2console C1].
 *
 * The invite-create fiction (a `{label, expires_in_seconds?}` body against a
 * hub that requires `server_id` and names its expiry key `expires_in`) was
 * concealed by a test that queued a canned 201 regardless of what was sent.
 * These tests make the REQUEST direction observable: every POST is asserted
 * against the hub's parsed body contract, verbatim.
 */
final class HubClientTest extends TestCase
{
    private function hubWith(FakeTransport $transport): HubClient
    {
        $api = new ApiClient('https://hub', $transport);
        $api->setToken(new TokenBundle('access-1', 'refresh-1', 'Bearer', time() + 3600));

        return new HubClient($api);
    }

    /** @param array<string,mixed> $expected */
    private function assertJsonBody(string $actual, array $expected, string $message): void
    {
        self::assertSame($expected, json_decode($actual, true), $message);
    }

    public function testCreateInvitePostsTheHubRequiredServerIdBody(): void
    {
        // Hub authority: src/Http/Controllers/InviteLinkController.php
        // createInviteLink — `$body['server_id'] ?? null`; empty ⇒ 400
        // missing_server_id. Nothing else is required: permission/max_uses/
        // library_id/expires_in all fall back to hub-side defaults.
        $transport = (new FakeTransport())->json(201, [
            'url' => 'https://hub/invite/t0ken',
            'token' => 't0ken',
            'expires_at' => 1780000000,
            'id' => 'inv-1',
            'permission' => 'read',
            'max_uses' => 1,
            'library_id' => null,
        ]);

        $row = $this->await($this->hubWith($transport)->createInvite('srv-1'));

        self::assertSame('inv-1', $row['id']);
        $this->assertJsonBody(
            $transport->lastRequest()['body'],
            ['server_id' => 'srv-1'],
            'the create body is exactly {server_id} — no fabricated label/expires_in_seconds keys',
        );
    }

    public function testCreateInviteCarriesExpiryOnTheHubKeyNamedExpiresIn(): void
    {
        // The hub reads `expires_in`; `expires_in_seconds` was fiction. This
        // pin keeps the key NAME on the wire, not just its presence.
        $transport = (new FakeTransport())->json(201, ['id' => 'inv-2']);

        $this->await($this->hubWith($transport)->createInvite('srv-1', 3600));

        $this->assertJsonBody(
            $transport->lastRequest()['body'],
            ['server_id' => 'srv-1', 'expires_in' => 3600],
            'expiry rides the hub-parsed key name expires_in',
        );
    }

    public function testServersDelegatesToTheCanonicalMyServersRoster(): void
    {
        // One canonical GET /api/v1/me/servers site lives in ApiClient
        // (GateScanner HUB_TUPLES law); HubClient rides it, rows pass through
        // as the hub's camelCase ServerInfoDto payloads.
        $transport = (new FakeTransport())->json(200, ['servers' => [
            ['serverId' => 'srv-1', 'serverName' => 'Main', 'status' => 'active'],
        ]]);

        $rows = $this->await($this->hubWith($transport)->servers());

        self::assertSame('GET', $transport->lastRequest()['method']);
        self::assertStringContainsString('/api/v1/me/servers', $transport->lastRequest()['url']);
        self::assertCount(1, $rows);
        self::assertSame('srv-1', $rows[0]['serverId']);
    }

    // ---- helpers -------------------------------------------------------

    private function await(PromiseInterface $promise, float $timeout = 2.0): mixed
    {
        $state = ['done' => false, 'value' => null, 'error' => null];
        $promise->then(
            function ($value) use (&$state): void {
                $state['value'] = $value;
                $state['done'] = true;
                Loop::stop();
            },
            function ($error) use (&$state): void {
                $state['error'] = $error;
                $state['done'] = true;
                Loop::stop();
            },
        );

        if (!$state['done']) {
            $timer = Loop::addTimer($timeout, static fn () => Loop::stop());
            Loop::run();
            Loop::cancelTimer($timer);
        }

        if ($state['error'] !== null) {
            throw $state['error'];
        }

        return $state['value'];
    }
}
