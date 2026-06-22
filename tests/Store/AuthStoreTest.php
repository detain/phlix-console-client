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
