<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Store;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\AuthUser;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Config\TokenStore;
use Phlix\Console\Store\AuthStore;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

final class AuthStoreTest extends TestCase
{
    private string $dir;
    private string $tokenPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/phlix-authstore-' . bin2hex(random_bytes(6));
        $this->tokenPath = $this->dir . '/token.json';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function authResponse(): array
    {
        return [
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'user' => ['id' => 'u1', 'username' => 'joe', 'status' => 'active'],
        ];
    }

    public function testLoginStoresUserAndPersistsToken(): void
    {
        $tokens = new TokenStore($this->tokenPath);
        $api = new ApiClient('https://srv', (new FakeTransport())->json(200, $this->authResponse()));
        $store = new AuthStore($api, $tokens);

        $user = $this->await($store->login('joe', 'pw'));

        self::assertInstanceOf(AuthUser::class, $user);
        self::assertTrue($store->isLoggedIn());
        self::assertSame('joe', $store->currentUser()?->username);
        // onTokenChanged persisted the bundle.
        self::assertSame('access-1', $tokens->load()?->accessToken);
    }

    public function testRestoreWithNoStoredTokenResolvesNull(): void
    {
        $store = new AuthStore(new ApiClient('https://srv', new FakeTransport()), new TokenStore($this->tokenPath));

        self::assertNull($this->await($store->restore()));
        self::assertFalse($store->isLoggedIn());
    }

    public function testRestoreValidatesStoredTokenViaMe(): void
    {
        $tokens = new TokenStore($this->tokenPath);
        $tokens->save(new TokenBundle('stored-access', 'stored-refresh', 'Bearer', null));
        $api = new ApiClient('https://srv', (new FakeTransport())->json(200, ['user' => ['id' => 'u1', 'username' => 'joe']]));
        $store = new AuthStore($api, $tokens);

        $user = $this->await($store->restore());

        self::assertSame('joe', $user?->username);
        self::assertSame('joe', $store->currentUser()?->username);
    }

    public function testRestoreClearsTokenOnAuthFailure(): void
    {
        $tokens = new TokenStore($this->tokenPath);
        $tokens->save(new TokenBundle('stale', 'bad-refresh', 'Bearer', null));
        // me() → 401, refresh → 401  → AuthError → token cleared.
        $api = new ApiClient('https://srv', (new FakeTransport())
            ->json(401, ['error' => 'Unauthorized'])
            ->json(401, ['error' => 'Invalid refresh token']));
        $store = new AuthStore($api, $tokens);

        self::assertNull($this->await($store->restore()));
        self::assertFalse($tokens->exists(), 'an auth failure clears the stored token');
    }

    public function testRestoreKeepsTokenOnNetworkFailure(): void
    {
        $tokens = new TokenStore($this->tokenPath);
        $tokens->save(new TokenBundle('valid-but-unverified', 'refresh', 'Bearer', null));
        $api = new ApiClient('https://srv', (new FakeTransport())->fail(new \RuntimeException('Connection refused')));
        $store = new AuthStore($api, $tokens);

        self::assertNull($this->await($store->restore()));
        self::assertTrue($tokens->exists(), 'a transient network failure must not discard a possibly-valid token');
    }

    public function testLogoutClearsEverything(): void
    {
        $tokens = new TokenStore($this->tokenPath);
        $api = new ApiClient('https://srv', (new FakeTransport())->json(200, $this->authResponse()));
        $store = new AuthStore($api, $tokens);
        $this->await($store->login('joe', 'pw'));

        $store->logout();

        self::assertFalse($store->isLoggedIn());
        self::assertNull($store->currentUser());
        self::assertNull($api->token());
        self::assertFalse($tokens->exists());
    }

    // ---- per-server isolation [review console__secaudit #1-#2 / CU-004] ----

    public function testSwitchWithNoBundleForTargetServerIssuesZeroRequests(): void
    {
        // The guard law: after a switch whose target server has no stored
        // bundle, the client holds NO bearer and issues NO authenticated
        // request — the previous server's credential never reaches the new
        // host's origin.
        $tokens = new TokenStore($this->tokenPath);
        $tokens->setForServer('srv-a', new TokenBundle('access-a', 'refresh-a', 'Bearer', null));
        $transport = (new FakeTransport())->json(200, ['user' => ['id' => 'u1', 'username' => 'joe']]);
        $api = new ApiClient('https://a.example', $transport);
        $store = new AuthStore($api, $tokens, 'srv-a');

        self::assertNotNull($this->await($store->restore()), 'a bound server validates its own keyed bundle');

        $api->setBaseUrl('https://b.example');
        $store->setCurrentServer('srv-b');
        $requestsBeforeSwitch = $transport->requestCount();

        self::assertNull($this->await($store->restore()));

        self::assertSame(
            $requestsBeforeSwitch,
            $transport->requestCount(),
            'a switch with no stored bundle for the target issues zero requests',
        );
        self::assertNull($api->token(), 'the foreign bearer is dropped from the client');
        self::assertNotNull($tokens->getForServer('srv-a'), 'srv-a keeps its own entry for its next session');
    }

    public function testRejectedKeyedBundleRemovesOnlyThatServersEntry(): void
    {
        // A rejection proves the REJECTED bundle bad — nothing else. The old
        // design wiped the sole slot (logging out unrelated servers) and the
        // whole-store clear() would do it again through the back door.
        $tokens = new TokenStore($this->tokenPath);
        $tokens->save(new TokenBundle('legacy-access', 'legacy-refresh', 'Bearer', null));
        $tokens->setForServer('srv-a', new TokenBundle('access-a', 'refresh-a', 'Bearer', null));
        $tokens->setForServer('srv-b', new TokenBundle('access-b', 'refresh-b', 'Bearer', null));
        // me() 401 then the transparent refresh() 401 → AuthError reject arm.
        $api = new ApiClient('https://a.example', (new FakeTransport())
            ->json(401, ['error' => 'Unauthorized'])
            ->json(401, ['error' => 'Invalid refresh token']));
        $store = new AuthStore($api, $tokens, 'srv-a');

        self::assertNull($this->await($store->restore()));

        self::assertNull($tokens->getForServer('srv-a'), 'the rejected server entry is removed');
        self::assertNotNull($tokens->getForServer('srv-b'), 'another server entry is untouched');
        self::assertNotNull($tokens->load(), 'the legacy slot is untouched by a keyed rejection');
    }

    public function testLegacyBundleIsHonouredOnceAndStampedToTheValidatingServer(): void
    {
        // Migration feed: the pre-per-server single slot may boot a keyed
        // server ONCE, and the validation that succeeds is the provenance
        // stamp — the bundle then lives keyed, and the slot retires.
        $tokens = new TokenStore($this->tokenPath);
        $tokens->save(new TokenBundle('migrated-access', 'migrated-refresh', 'Bearer', null));
        $api = new ApiClient('https://a.example', (new FakeTransport())->json(200, ['user' => ['id' => 'u1', 'username' => 'joe']]));
        $store = new AuthStore($api, $tokens, 'srv-a');

        self::assertNotNull($this->await($store->restore()));

        $stamped = $tokens->getForServer('srv-a');
        self::assertNotNull($stamped, 'the validated legacy bundle is re-stamped to the server that proved it good');
        self::assertSame('migrated-access', $stamped->accessToken);
        self::assertNull($tokens->load(), 'the legacy slot retires after the migration');
    }

    public function testUnboundStoreRetiresOnlyTheLegacySlot(): void
    {
        // The env-stub (pre-per-server) shape keeps its old single-slot life:
        // persists land in the legacy slot, logout retires exactly that slot
        // — keyed entries belong to bound sessions and stay.
        $tokens = new TokenStore($this->tokenPath);
        $tokens->save(new TokenBundle('legacy-access', 'legacy-refresh', 'Bearer', null));
        $tokens->setForServer('srv-a', new TokenBundle('access-a', 'refresh-a', 'Bearer', null));
        $api = new ApiClient('https://srv', (new FakeTransport())->json(200, $this->authResponse()));
        $store = new AuthStore($api, $tokens);

        $this->await($store->login('joe', 'pw'));
        self::assertSame('access-1', $tokens->load()?->accessToken, 'an unbound persist writes the legacy slot');

        $store->logout();

        self::assertNull($tokens->load(), 'unbound logout retires the legacy slot');
        self::assertNotNull($tokens->getForServer('srv-a'), 'keyed entries are not an unbound store to clear');
    }

    public function testDetachServerTokenReturnsTheBundleAndClearsExactlyThatEntry(): void
    {
        // Removal path hands the caller the credential for a best-effort
        // revoke while it is still in hand — and touches nothing else.
        $tokens = new TokenStore($this->tokenPath);
        $tokens->setForServer('srv-a', new TokenBundle('access-a', 'refresh-a', 'Bearer', null));
        $tokens->setForServer('srv-b', new TokenBundle('access-b', 'refresh-b', 'Bearer', null));
        $api = new ApiClient('https://a.example', new FakeTransport());
        $store = new AuthStore($api, $tokens, 'srv-a');
        $bearer = $tokens->getForServer('srv-a');
        self::assertNotNull($bearer);
        $api->setToken($bearer);

        $detached = $store->detachServerToken('srv-b');

        self::assertNotNull($detached, 'the removed server bundle is handed to the caller');
        self::assertSame('access-b', $detached->accessToken);
        self::assertNull($tokens->getForServer('srv-b'), 'its entry is gone');
        self::assertNotNull($api->token(), 'removing another server entry keeps the bound bearer');

        self::assertSame('access-a', $store->detachServerToken('srv-a')?->accessToken);
        self::assertNull($api->token(), 'removing the bound server entry drops the in-memory bearer');
    }

    private function await(PromiseInterface $promise, float $timeout = 2.0): mixed
    {
        $state = ['done' => false, 'value' => null, 'error' => null];
        $promise->then(
            function ($v) use (&$state): void {
                $state['value'] = $v;
                $state['done'] = true;
                Loop::stop();
            },
            function ($e) use (&$state): void {
                $state['error'] = $e;
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
