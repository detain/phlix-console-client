<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\ApiError;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\AuthResult;
use Phlix\Console\Api\MediaQuery;
use Phlix\Console\Api\NetworkError;
use Phlix\Console\Api\Dto\AuthUser;
use Phlix\Console\Api\Dto\ContinueWatchingItem;
use Phlix\Console\Api\Dto\Library;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Api\Dto\MediaPage;
use Phlix\Console\Api\Dto\PlaybackInfo;
use Phlix\Console\Api\Dto\PlaybackMarkers;
use Phlix\Console\Config\TokenBundle;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

final class ApiClientTest extends TestCase
{
    private const BASE = 'https://srv.example';

    /** Login/refresh response fixture. */
    private function authResponse(string $access = 'access-1', string $refresh = 'refresh-1'): array
    {
        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'user' => ['id' => 'u1', 'username' => 'joe', 'email' => 'joe@x.tld', 'is_admin' => 0, 'status' => 'active'],
        ];
    }

    // ---- login ---------------------------------------------------------

    public function testLoginSucceedsAndStoresToken(): void
    {
        $t = (new FakeTransport())->json(200, $this->authResponse());
        $client = new ApiClient(self::BASE, $t);

        $result = $this->await($client->login('joe', 'secret'));

        self::assertInstanceOf(AuthResult::class, $result);
        self::assertSame('joe', $result->user->username);
        self::assertSame('access-1', $result->tokens->accessToken);
        self::assertSame('access-1', $client->token()?->accessToken, 'token is stored on the client');

        $req = $t->requestAt(0);
        self::assertSame('POST', $req['method']);
        self::assertSame(self::BASE . '/api/v1/auth/login', $req['url']);
        self::assertArrayNotHasKey('Authorization', $req['headers'], 'login is unauthenticated');
        self::assertSame(['username' => 'joe', 'password' => 'secret'], json_decode($req['body'], true));
    }

    public function testLoginWithEmailAlsoSendsEmailField(): void
    {
        $t = (new FakeTransport())->json(200, $this->authResponse());
        $client = new ApiClient(self::BASE, $t);

        $this->await($client->login('joe@x.tld', 'pw'));

        $body = json_decode($t->requestAt(0)['body'], true);
        self::assertSame('joe@x.tld', $body['username']);
        self::assertSame('joe@x.tld', $body['email']);
    }

    public function testLoginFiresTokenChangedCallback(): void
    {
        $t = (new FakeTransport())->json(200, $this->authResponse());
        $client = new ApiClient(self::BASE, $t);
        $saved = null;
        $client->onTokenChanged(function (TokenBundle $b) use (&$saved): void {
            $saved = $b;
        });

        $this->await($client->login('joe', 'pw'));

        self::assertSame('access-1', $saved?->accessToken);
    }

    public function testLoginInvalidCredentialsRejectsWithAuthError(): void
    {
        $t = (new FakeTransport())->json(401, ['error' => 'Invalid username or password']);
        $client = new ApiClient(self::BASE, $t);

        $error = $this->awaitError($client->login('joe', 'bad'));

        self::assertInstanceOf(AuthError::class, $error);
        self::assertSame('Invalid username or password', $error->getMessage());
        self::assertSame(401, $error->statusCode);
    }

    public function testLoginPendingAccountRejectsWithPlainApiError(): void
    {
        $t = (new FakeTransport())->json(403, ['error' => 'Account is pending approval', 'code' => 'account_pending']);
        $client = new ApiClient(self::BASE, $t);

        $error = $this->awaitError($client->login('joe', 'pw'));

        self::assertInstanceOf(ApiError::class, $error);
        self::assertNotInstanceOf(AuthError::class, $error, '403 must not be treated as a refreshable 401');
        self::assertSame('Account is pending approval', $error->getMessage());
        self::assertSame(403, $error->statusCode);
    }

    // ---- authed reads --------------------------------------------------

    public function testMeSendsBearerAndMapsUser(): void
    {
        $t = (new FakeTransport())->json(200, ['user' => ['id' => 'u1', 'username' => 'joe', 'is_admin' => 1, 'status' => 'active']]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('tok-abc', 'ref', 'Bearer', null));

        $user = $this->await($client->me());

        self::assertInstanceOf(AuthUser::class, $user);
        self::assertTrue($user->isAdmin);
        self::assertSame('Bearer tok-abc', $t->requestAt(0)['headers']['Authorization']);
    }

    public function testLibrariesMapsList(): void
    {
        $t = (new FakeTransport())->json(200, ['libraries' => [
            ['id' => 'l1', 'name' => 'Movies', 'type' => 'movie', 'item_count' => 10],
            ['id' => 'l2', 'name' => 'TV', 'type' => 'series', 'item_count' => 5],
            'garbage',
        ]]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $libs = $this->await($client->libraries());

        self::assertContainsOnlyInstancesOf(Library::class, $libs);
        self::assertCount(2, $libs);
        self::assertSame('Movies', $libs[0]->name);
    }

    public function testMediaBuildsQueryAndMapsPage(): void
    {
        $t = (new FakeTransport())->json(200, [
            'items' => [['id' => 'a', 'name' => 'A', 'type' => 'movie']],
            'total' => 42,
            'limit' => 18,
            'offset' => 0,
        ]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $page = $this->await($client->media(MediaQuery::forLibrary('lib-7', limit: 18)));

        self::assertInstanceOf(MediaPage::class, $page);
        self::assertSame(42, $page->total);
        $url = $t->requestAt(0)['url'];
        self::assertStringContainsString('/api/v1/media?', $url);
        self::assertStringContainsString('libraryId=lib-7', $url);
        self::assertStringContainsString('limit=18', $url);
        self::assertStringContainsString('offset=0', $url);
    }

    public function testLetterIndexHitsEndpointWithFiltersAndMaps(): void
    {
        $t = (new FakeTransport())->json(200, [
            'letters' => [
                ['letter' => '#', 'offset' => 0, 'count' => 2],
                ['letter' => 'A', 'offset' => 2, 'count' => 5],
                ['letter' => 'B', 'offset' => 7, 'count' => 0],
            ],
            'total' => 7,
        ]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $index = $this->await($client->letterIndex(MediaQuery::forLibrary('lib-7')));

        self::assertSame(7, $index->total);
        self::assertSame(2, $index->offsetFor('A'));
        self::assertSame(['#', 'A'], $index->enabledLetters());
        $url = $t->requestAt(0)['url'];
        self::assertStringContainsString('/api/v1/media/letter-index?', $url);
        self::assertStringContainsString('libraryId=lib-7', $url);
    }

    public function testMediaItemMapsSingleItem(): void
    {
        $t = (new FakeTransport())->json(200, ['item' => ['id' => 'm1', 'name' => 'Matrix', 'type' => 'movie', 'stream_url' => 'https://s/x?sig=1']]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $item = $this->await($client->mediaItem('m1'));

        self::assertInstanceOf(MediaItem::class, $item);
        self::assertSame('https://s/x?sig=1', $item->streamUrl);
        self::assertStringEndsWith('/api/v1/media/m1', $t->requestAt(0)['url']);
    }

    public function testContinueWatchingMapsEntries(): void
    {
        $t = (new FakeTransport())->json(200, ['items' => [
            ['media_item_id' => 'm1', 'name' => 'Show', 'type' => 'episode', 'position_ticks' => 30, 'duration_ticks' => 100, 'metadata' => ['poster_url' => 'https://p/1.jpg']],
        ]]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $entries = $this->await($client->continueWatching());

        self::assertContainsOnlyInstancesOf(ContinueWatchingItem::class, $entries);
        self::assertSame('m1', $entries[0]->item->id);
        self::assertEqualsWithDelta(0.3, $entries[0]->progress(), 0.0001);
        self::assertStringEndsWith('/api/v1/users/me/continue-watching', $t->requestAt(0)['url']);
    }

    public function testPlaybackInfoMaps(): void
    {
        $t = (new FakeTransport())->json(200, ['playback_info' => ['id' => 'm1', 'name' => 'X', 'type' => 'movie', 'media_sources' => [['id' => 'default']]]]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $info = $this->await($client->playbackInfo('m1'));

        self::assertInstanceOf(PlaybackInfo::class, $info);
        self::assertStringEndsWith('/api/v1/media/m1/playback', $t->requestAt(0)['url']);
    }

    public function testPlaybackMarkersMapsTheFlatShape(): void
    {
        $t = (new FakeTransport())->json(200, [
            'item_id' => 'm1',
            'intro_marker' => ['start_seconds' => 5, 'end_seconds' => 30],
            'outro_marker' => null,
            'chapters' => [['start_seconds' => 0, 'end_seconds' => 50, 'title' => 'One']],
        ]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $markers = $this->await($client->playbackMarkers('m1'));

        self::assertInstanceOf(PlaybackMarkers::class, $markers);
        self::assertSame(5.0, $markers->intro?->start);
        self::assertNull($markers->outro);
        self::assertCount(1, $markers->chapters);
        self::assertStringEndsWith('/api/v1/media/m1/playback-info', $t->requestAt(0)['url']);
    }

    public function testCreateSessionPostsTheDeviceAndReturnsTheId(): void
    {
        $t = (new FakeTransport())->json(201, ['session_id' => 'sess-9']);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $id = $this->await($client->createSession('dev-1', 'Phlix Console', 'console'));

        self::assertSame('sess-9', $id);
        $req = $t->requestAt(0);
        self::assertSame('POST', $req['method']);
        self::assertStringEndsWith('/api/v1/sessions', $req['url']);
        self::assertStringContainsString('"device_id":"dev-1"', $req['body']);
    }

    public function testReportProgressPostsTicks(): void
    {
        $t = (new FakeTransport())->json(200, ['message' => 'Progress updated']);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $ok = $this->await($client->reportProgress('sess-9', 'm1', 100000000, 360000000, true));

        self::assertTrue($ok);
        $req = $t->requestAt(0);
        self::assertStringEndsWith('/api/v1/sessions/sess-9/progress', $req['url']);
        self::assertStringContainsString('"position_ticks":100000000', $req['body']);
        self::assertStringContainsString('"is_paused":true', $req['body']);
    }

    public function testEndSessionDeletes(): void
    {
        $t = (new FakeTransport())->json(200, ['message' => 'Session ended']);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $ok = $this->await($client->endSession('sess-9'));

        self::assertTrue($ok);
        $req = $t->requestAt(0);
        self::assertSame('DELETE', $req['method']);
        self::assertStringEndsWith('/api/v1/sessions/sess-9', $req['url']);
    }

    // ---- 401 refresh-and-retry ----------------------------------------

    public function testUnauthorizedTriggersRefreshAndRetry(): void
    {
        $t = (new FakeTransport())
            ->json(401, ['error' => 'Unauthorized'])           // 1: me() rejected
            ->json(200, $this->authResponse('access-2', 'refresh-2')) // 2: refresh
            ->json(200, ['user' => ['id' => 'u1', 'username' => 'joe']]); // 3: me() retried
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('stale', 'refresh-1', 'Bearer', null));
        $refreshed = null;
        $client->onTokenChanged(function (TokenBundle $b) use (&$refreshed): void {
            $refreshed = $b;
        });

        $user = $this->await($client->me());

        self::assertSame('joe', $user->username);
        self::assertSame(3, $t->requestCount(), 'me → refresh → me');
        self::assertSame(self::BASE . '/api/v1/auth/refresh', $t->requestAt(1)['url']);
        self::assertSame(['refresh_token' => 'refresh-1'], json_decode($t->requestAt(1)['body'], true));
        self::assertSame('Bearer access-2', $t->requestAt(2)['headers']['Authorization'], 'retry uses the refreshed token');
        self::assertSame('access-2', $refreshed?->accessToken);
    }

    public function testRefreshFailureRejectsWithAuthError(): void
    {
        $t = (new FakeTransport())
            ->json(401, ['error' => 'Unauthorized'])
            ->json(401, ['error' => 'Invalid refresh token']);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('stale', 'refresh-1', 'Bearer', null));

        $error = $this->awaitError($client->me());

        self::assertInstanceOf(AuthError::class, $error);
        self::assertSame('Session expired — please log in again.', $error->getMessage());
        self::assertSame(2, $t->requestCount(), 'no second retry after a failed refresh');
    }

    public function testUnauthorizedWithoutRefreshTokenDoesNotRetry(): void
    {
        $t = (new FakeTransport())->json(401, ['error' => 'Unauthorized']);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('stale', '', 'Bearer', null)); // no refresh token

        $error = $this->awaitError($client->me());

        self::assertInstanceOf(AuthError::class, $error);
        self::assertSame(1, $t->requestCount(), 'nothing to refresh with → single attempt');
    }

    public function testConcurrentRefreshCallsShareOneInFlightPromise(): void
    {
        // A never-settling transport keeps the refresh in flight so we can
        // observe that a second call returns the same promise.
        $client = new ApiClient(self::BASE, (new FakeTransport())->pending());
        $client->setToken(new TokenBundle('stale', 'refresh-1', 'Bearer', null));

        $first = $client->refresh();
        $second = $client->refresh();

        self::assertSame($first, $second);
    }

    public function testRefreshWithoutTokenRejects(): void
    {
        $client = new ApiClient(self::BASE, new FakeTransport());

        $error = $this->awaitError($client->refresh());

        self::assertInstanceOf(AuthError::class, $error);
    }

    // ---- transport failures -------------------------------------------

    public function testTransportFailureBecomesNetworkError(): void
    {
        $t = (new FakeTransport())->fail(new \RuntimeException('Connection refused'));
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $error = $this->awaitError($client->libraries());

        self::assertInstanceOf(NetworkError::class, $error);
        self::assertStringContainsString('Could not reach the server', $error->getMessage());
    }

    public function testServerErrorBecomesApiError(): void
    {
        $t = (new FakeTransport())->json(500, ['error' => 'boom']);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $error = $this->awaitError($client->libraries());

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame(500, $error->statusCode);
        self::assertNotInstanceOf(AuthError::class, $error);
    }

    public function testClearToken(): void
    {
        $client = new ApiClient(self::BASE, new FakeTransport());
        $client->setToken(new TokenBundle('t', 'r'));

        $client->clearToken();

        self::assertNull($client->token());
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

    private function awaitError(PromiseInterface $promise): \Throwable
    {
        try {
            $this->await($promise);
        } catch (\Throwable $e) {
            return $e;
        }

        self::fail('Expected the promise to reject, but it resolved.');
    }
}
