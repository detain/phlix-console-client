<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Admin;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\ApiError;
use Phlix\Console\Api\Dto\Admin\AdminDashboard;
use Phlix\Console\Api\Dto\Admin\LogFile;
use Phlix\Console\Api\Dto\Admin\LogTail;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

final class AdminClientTest extends TestCase
{
    private const BASE = 'https://srv.example';

    /** Wrap a `data` payload in the server's `{success, data, count}` envelope. */
    private function envelope(mixed $data): array
    {
        return ['success' => true, 'data' => $data, 'count' => is_array($data) ? count($data) : 1];
    }

    /**
     * A transport scripted with all five dashboard envelopes, in the order the
     * client fires them (now-playing, top-users, top-media, storage, activity).
     */
    private function dashboardTransport(): FakeTransport
    {
        return (new FakeTransport())
            ->json(200, $this->envelope([
                ['stream_id' => 'st-1', 'username' => 'joe', 'media_title' => 'Heat', 'progress_percent' => 42.0],
            ]))
            ->json(200, $this->envelope([
                ['user_id' => 'u-1', 'username' => 'joe', 'play_count' => 12],
            ]))
            ->json(200, $this->envelope([
                ['media_item_id' => 'm-1', 'title' => 'Heat', 'play_count' => 7],
            ]))
            ->json(200, $this->envelope([
                'movie_bytes' => 1000, 'series_bytes' => 2000, 'music_bytes' => 300,
                'photo_bytes' => 40, 'transcode_cache_bytes' => 5,
            ]))
            ->json(200, $this->envelope([
                ['id' => 'a-1', 'event_type' => 'login', 'username' => 'joe', 'occurred_at' => '2026-06-26 12:00:00'],
            ]));
    }

    private function clientWith(FakeTransport $transport): AdminClient
    {
        $api = new ApiClient(self::BASE, $transport);
        $api->setToken(new TokenBundle('access-1', 'refresh-1', 'Bearer', time() + 3600));

        return new AdminClient($api);
    }

    public function testDashboardFansOutToAllFiveEndpoints(): void
    {
        $transport = $this->dashboardTransport();

        $dashboard = $this->await($this->clientWith($transport)->dashboard());

        self::assertInstanceOf(AdminDashboard::class, $dashboard);
        self::assertSame(5, $transport->requestCount(), 'all five panels are fetched');

        $urls = array_map(static fn (array $r): string => $r['url'], $transport->requests);
        self::assertStringContainsString('/api/v1/admin/dashboard/now-playing', $urls[0]);
        self::assertStringContainsString('/api/v1/admin/dashboard/top-users', $urls[1]);
        self::assertStringContainsString('limit=10', $urls[1]);
        self::assertStringContainsString('days=30', $urls[1]);
        self::assertStringContainsString('/api/v1/admin/dashboard/top-media', $urls[2]);
        self::assertStringContainsString('/api/v1/admin/dashboard/storage', $urls[3]);
        self::assertStringContainsString('/api/v1/admin/dashboard/activity', $urls[4]);
        self::assertStringContainsString('limit=20', $urls[4]);
    }

    public function testDashboardExtractsAndMapsEachPanelsData(): void
    {
        $dashboard = $this->await($this->clientWith($this->dashboardTransport())->dashboard());

        self::assertInstanceOf(AdminDashboard::class, $dashboard);
        self::assertCount(1, $dashboard->nowPlaying);
        self::assertSame('joe', $dashboard->nowPlaying[0]->username);
        self::assertSame('Heat', $dashboard->nowPlaying[0]->mediaTitle);
        self::assertCount(1, $dashboard->topUsers);
        self::assertSame(12, $dashboard->topUsers[0]->playCount);
        self::assertCount(1, $dashboard->topMedia);
        self::assertSame('Heat', $dashboard->topMedia[0]->title);
        self::assertSame(1000, $dashboard->storage->movieBytes);
        self::assertSame(3345, $dashboard->storage->totalBytes());
        self::assertCount(1, $dashboard->activity);
        self::assertSame('login', $dashboard->activity[0]->eventType);
    }

    public function testDashboardAttachesTheBearerToken(): void
    {
        $transport = $this->dashboardTransport();

        $this->await($this->clientWith($transport)->dashboard());

        self::assertSame('Bearer access-1', $transport->requestAt(0)['headers']['Authorization'] ?? null);
    }

    public function testDashboardToleratesEmptyPayloads(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->envelope([]))
            ->json(200, $this->envelope([]))
            ->json(200, $this->envelope([]))
            ->json(200, $this->envelope([]))
            ->json(200, $this->envelope([]));

        $dashboard = $this->await($this->clientWith($transport)->dashboard());

        self::assertInstanceOf(AdminDashboard::class, $dashboard);
        self::assertSame([], $dashboard->nowPlaying);
        self::assertSame(0, $dashboard->storage->movieBytes);
    }

    public function testDashboardRejectsWhenAnyLegFails(): void
    {
        // The storage leg (4th) is a 500; all() rejects the whole fan-out.
        $transport = (new FakeTransport())
            ->json(200, $this->envelope([]))
            ->json(200, $this->envelope([]))
            ->json(200, $this->envelope([]))
            ->json(500, ['error' => 'boom'])
            ->json(200, $this->envelope([]));

        $error = $this->awaitError($this->clientWith($transport)->dashboard());

        self::assertInstanceOf(ApiError::class, $error);
    }

    // ---- logs ----------------------------------------------------------

    public function testLogFilesMapsTheFileList(): void
    {
        $transport = (new FakeTransport())->json(200, $this->envelope([
            'files' => [
                ['name' => 'app.log', 'size' => 4096, 'modified_at' => '2026-06-26T12:00:00-04:00'],
                ['name' => 'error.log', 'size' => 128, 'modified_at' => '2026-06-25T09:00:00-04:00'],
            ],
        ]));

        $files = $this->await($this->clientWith($transport)->logFiles());

        self::assertContainsOnlyInstancesOf(LogFile::class, $files);
        self::assertCount(2, $files);
        self::assertSame('app.log', $files[0]->name);
        self::assertSame(4096, $files[0]->size);
        self::assertStringContainsString('/api/v1/admin/logs', $transport->requestAt(0)['url']);
    }

    public function testLogFilesToleratesAMissingFilesKey(): void
    {
        $transport = (new FakeTransport())->json(200, $this->envelope([]));

        $files = $this->await($this->clientWith($transport)->logFiles());

        self::assertSame([], $files);
    }

    public function testLogFilesSkipsNonArrayRows(): void
    {
        $transport = (new FakeTransport())->json(200, $this->envelope([
            'files' => [['name' => 'app.log'], 'not-an-array', 99],
        ]));

        $files = $this->await($this->clientWith($transport)->logFiles());

        self::assertCount(1, $files);
        self::assertSame('app.log', $files[0]->name);
    }

    public function testTailLogTailsASingleFileWithQuery(): void
    {
        $transport = (new FakeTransport())->json(200, $this->envelope([
            'file' => 'app.log',
            'lines' => ['hello', 'world'],
            'truncated' => true,
        ]));

        $tail = $this->await($this->clientWith($transport)->tailLog('app.log', 200));

        self::assertInstanceOf(LogTail::class, $tail);
        self::assertSame('app.log', $tail->file);
        self::assertSame(['hello', 'world'], $tail->lines);
        self::assertTrue($tail->truncated);

        $url = $transport->requestAt(0)['url'];
        self::assertStringContainsString('/api/v1/admin/logs/tail', $url);
        self::assertStringContainsString('file=app.log', $url);
        self::assertStringContainsString('lines=200', $url);
    }

    public function testTailAllLogsMergesEveryFile(): void
    {
        $transport = (new FakeTransport())->json(200, $this->envelope([
            'files' => ['app.log', 'error.log'],
            'lines' => ['app.log    hi', 'error.log  boom'],
            'truncated' => false,
        ]));

        $tail = $this->await($this->clientWith($transport)->tailAllLogs(50));

        self::assertInstanceOf(LogTail::class, $tail);
        self::assertNull($tail->file);
        self::assertSame(['app.log', 'error.log'], $tail->files);
        self::assertCount(2, $tail->lines);

        $url = $transport->requestAt(0)['url'];
        self::assertStringContainsString('/api/v1/admin/logs/tail-all', $url);
        self::assertStringContainsString('lines=50', $url);
    }

    public function testTailLogAttachesTheBearerToken(): void
    {
        $transport = (new FakeTransport())->json(200, $this->envelope([
            'file' => 'app.log', 'lines' => [], 'truncated' => false,
        ]));

        $this->await($this->clientWith($transport)->tailLog('app.log', 200));

        self::assertSame('Bearer access-1', $transport->requestAt(0)['headers']['Authorization'] ?? null);
    }

    public function testTailLogRejectsOnAServerError(): void
    {
        $transport = (new FakeTransport())->json(404, ['error' => 'Log file not found']);

        $error = $this->awaitError($this->clientWith($transport)->tailLog('missing.log', 200));

        self::assertInstanceOf(ApiError::class, $error);
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

        self::fail('expected the promise to reject');
    }
}
