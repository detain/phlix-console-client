<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Admin;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\ApiError;
use Phlix\Console\Api\Dto\Admin\AdminDashboard;
use Phlix\Console\Api\Dto\Admin\AdminUser;
use Phlix\Console\Api\Dto\Admin\Backup;
use Phlix\Console\Api\Dto\Admin\BackupSchedule;
use Phlix\Console\Api\Dto\Admin\Channel;
use Phlix\Console\Api\Dto\Admin\DlnaServerStatus;
use Phlix\Console\Api\Dto\Admin\GuideProgram;
use Phlix\Console\Api\Dto\Admin\LogFile;
use Phlix\Console\Api\Dto\Admin\LogTail;
use Phlix\Console\Api\Dto\Admin\Plugin;
use Phlix\Console\Api\Dto\Admin\Recording;
use Phlix\Console\Api\Dto\Admin\RemoteAccessStatus;
use Phlix\Console\Api\Dto\Admin\SeriesRule;
use Phlix\Console\Api\Dto\Admin\ServerSettings;
use Phlix\Console\Api\Dto\Admin\Tuner;
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
        $transport = (new FakeTransport())->json(200, [
            'files' => [
                ['name' => 'app.log', 'size' => 4096, 'modified_at' => '2026-06-26T12:00:00-04:00'],
                ['name' => 'error.log', 'size' => 128, 'modified_at' => '2026-06-25T09:00:00-04:00'],
            ],
        ]);

        $files = $this->await($this->clientWith($transport)->logFiles());

        self::assertContainsOnlyInstancesOf(LogFile::class, $files);
        self::assertCount(2, $files);
        self::assertSame('app.log', $files[0]->name);
        self::assertSame(4096, $files[0]->size);
        self::assertStringContainsString('/api/v1/admin/logs', $transport->requestAt(0)['url']);
    }

    public function testLogFilesToleratesAMissingFilesKey(): void
    {
        $transport = (new FakeTransport())->json(200, []);

        $files = $this->await($this->clientWith($transport)->logFiles());

        self::assertSame([], $files);
    }

    public function testLogFilesSkipsNonArrayRows(): void
    {
        $transport = (new FakeTransport())->json(200, [
            'files' => [['name' => 'app.log'], 'not-an-array', 99],
        ]);

        $files = $this->await($this->clientWith($transport)->logFiles());

        self::assertCount(1, $files);
        self::assertSame('app.log', $files[0]->name);
    }

    public function testLogFilesIgnoresAnEnvelopeDataKey(): void
    {
        // Guard against regressing to a dashboard-style `{success, data:{files}}`
        // read: LogController is top-level, so a `data` wrapper must NOT be read.
        $transport = (new FakeTransport())->json(200, [
            'data' => ['files' => [['name' => 'ghost.log']]],
        ]);

        $files = $this->await($this->clientWith($transport)->logFiles());

        self::assertSame([], $files, 'log files are read top-level, not from data');
    }

    public function testTailLogTailsASingleFileWithQuery(): void
    {
        $transport = (new FakeTransport())->json(200, [
            'file' => 'app.log',
            'lines' => ['hello', 'world'],
            'truncated' => true,
        ]);

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
        $transport = (new FakeTransport())->json(200, [
            'files' => ['app.log', 'error.log'],
            'lines' => ['app.log    hi', 'error.log  boom'],
            'truncated' => false,
        ]);

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
        $transport = (new FakeTransport())->json(200, [
            'file' => 'app.log', 'lines' => [], 'truncated' => false,
        ]);

        $this->await($this->clientWith($transport)->tailLog('app.log', 200));

        self::assertSame('Bearer access-1', $transport->requestAt(0)['headers']['Authorization'] ?? null);
    }

    public function testTailLogRejectsOnAServerError(): void
    {
        $transport = (new FakeTransport())->json(404, ['error' => 'Log file not found']);

        $error = $this->awaitError($this->clientWith($transport)->tailLog('missing.log', 200));

        self::assertInstanceOf(ApiError::class, $error);
    }

    // ---- users ---------------------------------------------------------

    public function testUsersMapsTheUserList(): void
    {
        // The real AdminUserController returns the list at the TOP LEVEL, with NO
        // {success, data} envelope (envelopes are per-controller).
        $transport = (new FakeTransport())->json(200, [
            'users' => [
                ['id' => 'u-1', 'username' => 'bob', 'email' => 'bob@x', 'is_admin' => 1, 'status' => 'active', 'last_login' => '2026-06-26'],
                ['id' => 'u-2', 'username' => 'amy', 'email' => 'amy@x', 'is_admin' => 0, 'status' => 'pending'],
            ],
        ]);

        $users = $this->await($this->clientWith($transport)->users());

        self::assertContainsOnlyInstancesOf(AdminUser::class, $users);
        self::assertCount(2, $users);
        self::assertSame('bob', $users[0]->username);
        self::assertTrue($users[0]->isAdmin);
        self::assertSame('pending', $users[1]->status);
        self::assertStringContainsString('/api/v1/admin/users', $transport->requestAt(0)['url']);
        // No status query when none is requested.
        self::assertStringNotContainsString('status=', $transport->requestAt(0)['url']);
    }

    public function testUsersAppliesTheStatusFilterQuery(): void
    {
        $transport = (new FakeTransport())->json(200, ['users' => []]);

        $this->await($this->clientWith($transport)->users('pending'));

        self::assertStringContainsString('status=pending', $transport->requestAt(0)['url']);
    }

    public function testUsersToleratesAMissingUsersKey(): void
    {
        $transport = (new FakeTransport())->json(200, []);

        $users = $this->await($this->clientWith($transport)->users());

        self::assertSame([], $users);
    }

    public function testUsersIgnoresAnEnvelopeDataKey(): void
    {
        // Guard against regressing to the dashboard-style `{success, data:{users}}`
        // read: the real users endpoint is top-level, so a `data` wrapper must NOT
        // be where the list is found.
        $transport = (new FakeTransport())->json(200, [
            'data' => ['users' => [['id' => 'u-9', 'username' => 'ghost']]],
        ]);

        $users = $this->await($this->clientWith($transport)->users());

        self::assertSame([], $users, 'the list is read top-level, not from data');
    }

    public function testUsersSkipsNonArrayRows(): void
    {
        $transport = (new FakeTransport())->json(200, [
            'users' => [['id' => 'u-1', 'username' => 'bob'], 'nope', 7],
        ]);

        $users = $this->await($this->clientWith($transport)->users());

        self::assertCount(1, $users);
        self::assertSame('bob', $users[0]->username);
    }

    public function testApproveUserPostsAndResolvesTheMessage(): void
    {
        $transport = (new FakeTransport())->json(200, ['message' => 'User approved successfully']);

        $message = $this->await($this->clientWith($transport)->approveUser('u-1'));

        self::assertSame('User approved successfully', $message);
        self::assertSame('POST', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/admin/users/u-1/approve', $transport->requestAt(0)['url']);
    }

    public function testDisableUserResolvesTheMessage(): void
    {
        $transport = (new FakeTransport())->json(200, ['message' => 'User disabled successfully']);

        $message = $this->await($this->clientWith($transport)->disableUser('u-1'));

        self::assertSame('User disabled successfully', $message);
        self::assertStringContainsString('/api/v1/admin/users/u-1/disable', $transport->requestAt(0)['url']);
    }

    public function testRejectUserResolvesTheMessage(): void
    {
        $transport = (new FakeTransport())->json(200, ['message' => 'User rejected successfully']);

        $message = $this->await($this->clientWith($transport)->rejectUser('u-1'));

        self::assertSame('User rejected successfully', $message);
        self::assertStringContainsString('/api/v1/admin/users/u-1/reject', $transport->requestAt(0)['url']);
    }

    public function testDeleteUserSendsADeleteAndResolvesTheMessage(): void
    {
        $transport = (new FakeTransport())->json(200, ['message' => 'User deleted successfully']);

        $message = $this->await($this->clientWith($transport)->deleteUser('u-1'));

        self::assertSame('User deleted successfully', $message);
        self::assertSame('DELETE', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/admin/users/u-1', $transport->requestAt(0)['url']);
    }

    public function testSetUserAdminSendsTheBoolFlagInTheBody(): void
    {
        $transport = (new FakeTransport())->json(200, ['message' => 'User admin status updated successfully']);

        $message = $this->await($this->clientWith($transport)->setUserAdmin('u-1', true));

        self::assertSame('User admin status updated successfully', $message);
        self::assertStringContainsString('/api/v1/admin/users/u-1/set-admin', $transport->requestAt(0)['url']);
        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requestAt(0)['body'], true);
        self::assertTrue($body['is_admin']);
    }

    public function testResetUserPasswordResolvesTheNewPassword(): void
    {
        $transport = (new FakeTransport())->json(200, [
            'message' => 'Password reset successfully',
            'new_password' => 'Hunter2!xyz',
        ]);

        $password = $this->await($this->clientWith($transport)->resetUserPassword('u-1'));

        self::assertSame('Hunter2!xyz', $password);
        self::assertStringContainsString('/api/v1/admin/users/u-1/reset-password', $transport->requestAt(0)['url']);
    }

    public function testAUserActionRejectsWithTheServerErrorOnA400(): void
    {
        $transport = (new FakeTransport())->json(400, ['error' => 'Cannot disable the last admin']);

        $error = $this->awaitError($this->clientWith($transport)->disableUser('u-1'));

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('Cannot disable the last admin', $error->getMessage());
    }

    public function testAUserActionRejectsWithTheServerErrorOnA404(): void
    {
        $transport = (new FakeTransport())->json(404, ['error' => 'User not found']);

        $error = $this->awaitError($this->clientWith($transport)->approveUser('missing'));

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('User not found', $error->getMessage());
    }

    public function testCreateUserPostsTheFullBodyAndResolvesTheMessage(): void
    {
        $transport = (new FakeTransport())->json(201, ['user_id' => 'u-9', 'message' => 'User created successfully']);

        $message = $this->await($this->clientWith($transport)->createUser('alice_99', 'alice@x.com', 'sup3rsecret', true));

        self::assertSame('User created successfully', $message);
        self::assertSame('POST', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/admin/users', $transport->requestAt(0)['url']);
        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requestAt(0)['body'], true);
        self::assertSame('alice_99', $body['username']);
        self::assertSame('alice@x.com', $body['email']);
        self::assertSame('sup3rsecret', $body['password']);
        self::assertTrue($body['is_admin']);
    }

    public function testCreateUserRejectsWithTheServerErrorOnA400(): void
    {
        $transport = (new FakeTransport())->json(400, [
            'error' => 'Email already in use',
            'field_errors' => ['email' => 'Email already in use'],
        ]);

        $error = $this->awaitError($this->clientWith($transport)->createUser('alice_99', 'taken@x.com', 'sup3rsecret', false));

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('Email already in use', $error->getMessage());
    }

    public function testUpdateUserPutsOnlyTheProvidedFields(): void
    {
        $transport = (new FakeTransport())->json(200, ['message' => 'User updated successfully']);

        // Only the email changes; username/password are null and omitted.
        $message = $this->await($this->clientWith($transport)->updateUser('u-1', null, 'new@x.com', null));

        self::assertSame('User updated successfully', $message);
        self::assertSame('PUT', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/admin/users/u-1', $transport->requestAt(0)['url']);
        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requestAt(0)['body'], true);
        self::assertSame(['email' => 'new@x.com'], $body, 'only the changed field is sent');
        self::assertArrayNotHasKey('username', $body);
        self::assertArrayNotHasKey('password', $body);
    }

    public function testUpdateUserSendsEveryProvidedField(): void
    {
        $transport = (new FakeTransport())->json(200, ['message' => 'User updated successfully']);

        $this->await($this->clientWith($transport)->updateUser('u-1', 'renamed', 'r@x.com', 'newpassword1'));

        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requestAt(0)['body'], true);
        self::assertSame('renamed', $body['username']);
        self::assertSame('r@x.com', $body['email']);
        self::assertSame('newpassword1', $body['password']);
    }

    public function testUpdateUserWithNoChangedFieldsSendsNoBody(): void
    {
        $transport = (new FakeTransport())->json(200, ['message' => 'User updated successfully']);

        $this->await($this->clientWith($transport)->updateUser('u-1', null, null, null));

        self::assertSame('', $transport->requestAt(0)['body'], 'no provided fields sends no JSON body');
    }

    public function testUpdateUserRejectsWithTheServerErrorOnA404(): void
    {
        $transport = (new FakeTransport())->json(404, ['error' => 'User not found']);

        $error = $this->awaitError($this->clientWith($transport)->updateUser('missing', null, 'x@y.com', null));

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('User not found', $error->getMessage());
    }

    // ---- plugins -------------------------------------------------------

    public function testPluginsMapsTheTopLevelList(): void
    {
        // The real PluginAdminController returns the list at the TOP LEVEL, with
        // NO {success, data} envelope (envelopes are per-controller).
        $transport = (new FakeTransport())->json(200, [
            'plugins' => [
                ['name' => 'trakt', 'version' => '1.0', 'type' => 'scrobbler', 'enabled' => true, 'installed_at' => '2026-06-26T12:00:00-04:00', 'signed' => true],
                ['name' => 'lastfm', 'version' => '2.0', 'type' => 'scrobbler', 'enabled' => false, 'signed' => false],
            ],
        ]);

        $plugins = $this->await($this->clientWith($transport)->plugins());

        self::assertContainsOnlyInstancesOf(Plugin::class, $plugins);
        self::assertCount(2, $plugins);
        self::assertSame('trakt', $plugins[0]->name);
        self::assertTrue($plugins[0]->enabled);
        self::assertFalse($plugins[1]->enabled);
        self::assertStringContainsString('/api/v1/admin/plugins', $transport->requestAt(0)['url']);
    }

    public function testPluginsToleratesAMissingPluginsKey(): void
    {
        $transport = (new FakeTransport())->json(200, []);

        $plugins = $this->await($this->clientWith($transport)->plugins());

        self::assertSame([], $plugins);
    }

    public function testPluginsIgnoresAnEnvelopeDataKey(): void
    {
        // Guard against regressing to a dashboard-style `{success, data:{plugins}}`
        // read: PluginAdminController is top-level, so a `data` wrapper must NOT
        // be where the list is found.
        $transport = (new FakeTransport())->json(200, [
            'data' => ['plugins' => [['name' => 'ghost']]],
        ]);

        $plugins = $this->await($this->clientWith($transport)->plugins());

        self::assertSame([], $plugins, 'plugins are read top-level, not from data');
    }

    public function testPluginsSkipsNonArrayRows(): void
    {
        $transport = (new FakeTransport())->json(200, [
            'plugins' => [['name' => 'trakt'], 'nope', 7],
        ]);

        $plugins = $this->await($this->clientWith($transport)->plugins());

        self::assertCount(1, $plugins);
        self::assertSame('trakt', $plugins[0]->name);
    }

    public function testPluginsAttachesTheBearerToken(): void
    {
        $transport = (new FakeTransport())->json(200, ['plugins' => []]);

        $this->await($this->clientWith($transport)->plugins());

        self::assertSame('Bearer access-1', $transport->requestAt(0)['headers']['Authorization'] ?? null);
    }

    public function testEnablePluginPostsAndResolvesThePlugin(): void
    {
        $transport = (new FakeTransport())->json(200, ['plugin' => ['name' => 'trakt', 'enabled' => true]]);

        $plugin = $this->await($this->clientWith($transport)->enablePlugin('trakt'));

        self::assertInstanceOf(Plugin::class, $plugin);
        self::assertSame('trakt', $plugin->name);
        self::assertTrue($plugin->enabled);
        self::assertSame('POST', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/admin/plugins/trakt/enable', $transport->requestAt(0)['url']);
    }

    public function testDisablePluginResolvesTheDisabledPlugin(): void
    {
        $transport = (new FakeTransport())->json(200, ['plugin' => ['name' => 'trakt', 'enabled' => false]]);

        $plugin = $this->await($this->clientWith($transport)->disablePlugin('trakt'));

        self::assertFalse($plugin->enabled);
        self::assertStringContainsString('/api/v1/admin/plugins/trakt/disable', $transport->requestAt(0)['url']);
    }

    public function testDisablePluginToleratesAMissingPluginKey(): void
    {
        $transport = (new FakeTransport())->json(200, []);

        $plugin = $this->await($this->clientWith($transport)->disablePlugin('trakt'));

        self::assertInstanceOf(Plugin::class, $plugin);
        self::assertSame('', $plugin->name);
    }

    public function testInstallPluginPostsTheUrlAndResolvesThePlugin(): void
    {
        $transport = (new FakeTransport())->json(201, [
            'plugin' => ['name' => 'trakt', 'version' => '1.0', 'type' => 'scrobbler', 'signed' => true],
        ]);

        $plugin = $this->await($this->clientWith($transport)->installPlugin('https://github.com/owner/repo'));

        self::assertSame('trakt', $plugin->name);
        self::assertSame('1.0', $plugin->version);
        self::assertSame('POST', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/admin/plugins/install', $transport->requestAt(0)['url']);
        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requestAt(0)['body'], true);
        self::assertSame('https://github.com/owner/repo', $body['url']);
    }

    public function testInstallPluginRejectsWithTheServerErrorOnA400(): void
    {
        $transport = (new FakeTransport())->json(400, ['error' => 'Install URL must be an https:// archive']);

        $error = $this->awaitError($this->clientWith($transport)->installPlugin('http://insecure'));

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('Install URL must be an https:// archive', $error->getMessage());
    }

    public function testInstallPluginRejectsWithTheServerErrorOnA422(): void
    {
        $transport = (new FakeTransport())->json(422, ['error' => 'Plugin signature invalid']);

        $error = $this->awaitError($this->clientWith($transport)->installPlugin('https://bad'));

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('Plugin signature invalid', $error->getMessage());
    }

    public function testUninstallPluginSendsADeleteAndResolvesNull(): void
    {
        // The server returns 204 No Content (an empty body decodes to []).
        $transport = (new FakeTransport())->json(204, []);

        $result = $this->await($this->clientWith($transport)->uninstallPlugin('trakt'));

        self::assertNull($result);
        self::assertSame('DELETE', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/admin/plugins/trakt', $transport->requestAt(0)['url']);
    }

    public function testUninstallPluginRejectsWithTheServerErrorOnA404(): void
    {
        $transport = (new FakeTransport())->json(404, ['error' => 'Plugin not found']);

        $error = $this->awaitError($this->clientWith($transport)->uninstallPlugin('missing'));

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('Plugin not found', $error->getMessage());
    }

    // ---- backups -------------------------------------------------------

    public function testBackupsReadsTheEnvelopedDataList(): void
    {
        // UNLIKE users / plugins, BackupController IS enveloped — the list is under `data`.
        $transport = (new FakeTransport())->json(200, $this->envelope([
            ['id' => 'b-1', 'label' => 'nightly', 'size_bytes' => 2048, 'is_s3' => 0, 'created_at' => '2026-06-26 12:00:00'],
            ['id' => 'b-2', 'label' => '', 'size_bytes' => 4096, 'is_s3' => 1, 'created_at' => '2026-06-25 12:00:00'],
        ]));

        $backups = $this->await($this->clientWith($transport)->backups());

        self::assertContainsOnlyInstancesOf(Backup::class, $backups);
        self::assertCount(2, $backups);
        self::assertSame('nightly', $backups[0]->label);
        self::assertSame(2048, $backups[0]->sizeBytes);
        self::assertTrue($backups[1]->isS3);
        self::assertStringContainsString('/api/v1/admin/backup/list', $transport->requestAt(0)['url']);
    }

    public function testBackupsToleratesAMissingDataKey(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true]);

        $backups = $this->await($this->clientWith($transport)->backups());

        self::assertSame([], $backups);
    }

    public function testBackupsSkipsNonArrayRows(): void
    {
        $transport = (new FakeTransport())->json(200, $this->envelope([
            ['id' => 'b-1'], 'nope', 7,
        ]));

        $backups = $this->await($this->clientWith($transport)->backups());

        self::assertCount(1, $backups);
        self::assertSame('b-1', $backups[0]->id);
    }

    public function testCreateBackupPostsTheLabelAndResolvesTheMessage(): void
    {
        $transport = (new FakeTransport())->json(200, [
            'success' => true,
            'message' => 'Backup created successfully',
            'data' => ['backup_id' => 'b-9'],
        ]);

        $message = $this->await($this->clientWith($transport)->createBackup('pre-upgrade'));

        self::assertSame('Backup created successfully', $message);
        self::assertSame('POST', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/admin/backup/create', $transport->requestAt(0)['url']);
        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requestAt(0)['body'], true);
        self::assertSame('pre-upgrade', $body['label']);
    }

    public function testCreateBackupWithNullLabelSendsNoBody(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true, 'message' => 'Backup created successfully']);

        $this->await($this->clientWith($transport)->createBackup(null));

        self::assertSame('', $transport->requestAt(0)['body'], 'a null label sends no JSON body');
    }

    public function testCreateBackupRejectsWithTheServerErrorOnA500(): void
    {
        $transport = (new FakeTransport())->json(500, ['success' => false, 'error' => 'Backup creation failed']);

        $error = $this->awaitError($this->clientWith($transport)->createBackup(null));

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('Backup creation failed', $error->getMessage());
    }

    public function testDeleteBackupSendsADeleteAndResolvesTheMessage(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true, 'message' => 'Backup deleted successfully']);

        $message = $this->await($this->clientWith($transport)->deleteBackup('b-1'));

        self::assertSame('Backup deleted successfully', $message);
        self::assertSame('DELETE', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/admin/backup/b-1', $transport->requestAt(0)['url']);
    }

    public function testDeleteBackupRejectsWithTheServerErrorOnA404(): void
    {
        $transport = (new FakeTransport())->json(404, ['success' => false, 'error' => 'Backup not found']);

        $error = $this->awaitError($this->clientWith($transport)->deleteBackup('missing'));

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('Backup not found', $error->getMessage());
    }

    public function testRestoreBackupPostsAndResolvesTheMessage(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true, 'message' => 'Restore completed']);

        $message = $this->await($this->clientWith($transport)->restoreBackup('b-1'));

        self::assertSame('Restore completed', $message);
        self::assertSame('POST', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/admin/backup/b-1/restore', $transport->requestAt(0)['url']);
    }

    public function testRestoreBackupRejectsWithTheServerErrorOnA500(): void
    {
        $transport = (new FakeTransport())->json(500, ['success' => false, 'error' => 'Checksum mismatch']);

        $error = $this->awaitError($this->clientWith($transport)->restoreBackup('b-1'));

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('Checksum mismatch', $error->getMessage());
    }

    public function testUploadBackupToS3PostsAndResolvesTheMessage(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true, 'message' => 'Backup uploaded to S3 successfully']);

        $message = $this->await($this->clientWith($transport)->uploadBackupToS3('b-1'));

        self::assertSame('Backup uploaded to S3 successfully', $message);
        self::assertSame('POST', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/admin/backup/b-1/upload-s3', $transport->requestAt(0)['url']);
    }

    public function testUploadBackupToS3RejectsWithTheServerErrorOnA500(): void
    {
        $transport = (new FakeTransport())->json(500, ['success' => false, 'error' => 'S3 upload failed']);

        $error = $this->awaitError($this->clientWith($transport)->uploadBackupToS3('b-1'));

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('S3 upload failed', $error->getMessage());
    }

    public function testBackupScheduleReadsTheEnvelopedData(): void
    {
        $transport = (new FakeTransport())->json(200, $this->envelope([
            'auto_backup_interval_days' => 7,
            'retention_count' => 5,
            'next_scheduled_backup' => 1893456000,
            'next_scheduled_backup_iso' => '2030-01-01T00:00:00+00:00',
        ]));

        $schedule = $this->await($this->clientWith($transport)->backupSchedule());

        self::assertInstanceOf(BackupSchedule::class, $schedule);
        self::assertSame(7, $schedule->autoBackupIntervalDays);
        self::assertSame(5, $schedule->retentionCount);
        self::assertSame('2030-01-01T00:00:00+00:00', $schedule->nextScheduledBackup);
        self::assertStringContainsString('/api/v1/admin/backup/schedule', $transport->requestAt(0)['url']);
    }

    public function testBackupScheduleToleratesAMissingDataKey(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true]);

        $schedule = $this->await($this->clientWith($transport)->backupSchedule());

        self::assertSame(0, $schedule->autoBackupIntervalDays);
    }

    public function testUpdateBackupSchedulePutsBothFieldsAndMapsTheResponse(): void
    {
        $transport = (new FakeTransport())->json(200, [
            'success' => true,
            'message' => 'Schedule updated successfully',
            'data' => ['auto_backup_interval_days' => 14, 'retention_count' => 3],
        ]);

        $schedule = $this->await($this->clientWith($transport)->updateBackupSchedule(14, 3));

        self::assertInstanceOf(BackupSchedule::class, $schedule);
        self::assertSame(14, $schedule->autoBackupIntervalDays);
        self::assertSame(3, $schedule->retentionCount);
        self::assertSame('PUT', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/admin/backup/schedule', $transport->requestAt(0)['url']);
        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requestAt(0)['body'], true);
        self::assertSame(14, $body['auto_backup_interval_days']);
        self::assertSame(3, $body['retention_count']);
    }

    public function testUpdateBackupScheduleRejectsWithTheServerErrorOnA400(): void
    {
        $transport = (new FakeTransport())->json(400, ['success' => false, 'error' => 'Invalid retention count']);

        $error = $this->awaitError($this->clientWith($transport)->updateBackupSchedule(7, 0));

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('Invalid retention count', $error->getMessage());
    }

    public function testABackupActionAttachesTheBearerToken(): void
    {
        $transport = (new FakeTransport())->json(200, $this->envelope([]));

        $this->await($this->clientWith($transport)->backups());

        self::assertSame('Bearer access-1', $transport->requestAt(0)['headers']['Authorization'] ?? null);
    }

    // ---- server settings -----------------------------------------------

    private function settingsEnvelope(): array
    {
        return $this->envelope([
            'settings' => ['theme' => 'dark', 'port' => 8096, 'debug' => true],
            'types' => ['theme' => 'string', 'port' => 'int', 'debug' => 'bool'],
            'overridden' => ['port'],
        ]);
    }

    public function testServerSettingsReadsTheEnvelopedData(): void
    {
        $transport = (new FakeTransport())->json(200, $this->settingsEnvelope());

        $settings = $this->await($this->clientWith($transport)->serverSettings());

        self::assertInstanceOf(ServerSettings::class, $settings);
        self::assertCount(3, $settings->settings);
        self::assertStringContainsString('/api/v1/admin/settings', $transport->requestAt(0)['url']);
        self::assertSame('GET', $transport->requestAt(0)['method']);

        $byKey = [];
        foreach ($settings->settings as $setting) {
            $byKey[$setting->key] = $setting;
        }
        self::assertSame('dark', $byKey['theme']->displayValue);
        self::assertSame('8096', $byKey['port']->displayValue);
        self::assertTrue($byKey['port']->overridden);
        self::assertSame('true', $byKey['debug']->displayValue);
    }

    public function testServerSettingsToleratesAMissingDataKey(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true]);

        $settings = $this->await($this->clientWith($transport)->serverSettings());

        self::assertSame([], $settings->settings);
    }

    public function testServerSettingsIgnoresATopLevelSettingsWithNoDataWrapper(): void
    {
        // Inverse envelope-regression guard: this controller IS enveloped, so a
        // top-level {settings} (no `data`) must yield EMPTY — a wrong un-enveloped
        // read would surface the settings here and fail.
        $transport = (new FakeTransport())->json(200, [
            'success' => true,
            'settings' => ['theme' => 'dark'],
            'types' => ['theme' => 'string'],
        ]);

        $settings = $this->await($this->clientWith($transport)->serverSettings());

        self::assertSame([], $settings->settings);
    }

    public function testServerSettingsAttachesTheBearerToken(): void
    {
        $transport = (new FakeTransport())->json(200, $this->settingsEnvelope());

        $this->await($this->clientWith($transport)->serverSettings());

        self::assertSame('Bearer access-1', $transport->requestAt(0)['headers']['Authorization'] ?? null);
    }

    public function testUpdateServerSettingPutsABoolAndResolvesTheMessage(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true, 'message' => 'Settings updated.']);

        $message = $this->await($this->clientWith($transport)->updateServerSetting('debug', false));

        self::assertSame('Settings updated.', $message);
        self::assertSame('PUT', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/admin/settings', $transport->requestAt(0)['url']);
        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requestAt(0)['body'], true);
        self::assertSame(['settings' => ['debug' => false]], $body);
        self::assertFalse($body['settings']['debug'], 'a real bool is sent (not "false")');
    }

    public function testUpdateServerSettingPutsAnInt(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true, 'message' => 'Settings updated.']);

        $this->await($this->clientWith($transport)->updateServerSetting('port', 9000));

        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requestAt(0)['body'], true);
        self::assertSame(['settings' => ['port' => 9000]], $body);
        self::assertIsInt($body['settings']['port']);
    }

    public function testUpdateServerSettingPutsAFloat(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true, 'message' => 'Settings updated.']);

        $this->await($this->clientWith($transport)->updateServerSetting('ratio', 1.5));

        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requestAt(0)['body'], true);
        self::assertSame(1.5, $body['settings']['ratio']);
    }

    public function testUpdateServerSettingPutsAString(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true, 'message' => 'Settings updated.']);

        $this->await($this->clientWith($transport)->updateServerSetting('theme', 'light'));

        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requestAt(0)['body'], true);
        self::assertSame(['settings' => ['theme' => 'light']], $body);
    }

    public function testUpdateServerSettingPutsAnArray(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true, 'message' => 'Settings updated.']);

        $this->await($this->clientWith($transport)->updateServerSetting('hosts', ['a', 'b']));

        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requestAt(0)['body'], true);
        self::assertSame(['settings' => ['hosts' => ['a', 'b']]], $body);
    }

    public function testUpdateServerSettingResolvesAnEmptyMessageWhenAbsent(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true]);

        $message = $this->await($this->clientWith($transport)->updateServerSetting('theme', 'light'));

        self::assertSame('', $message);
    }

    public function testUpdateServerSettingRejectsWithTheServerErrorOnA400(): void
    {
        $transport = (new FakeTransport())->json(400, ['success' => false, 'error' => 'Validation failed']);

        $error = $this->awaitError($this->clientWith($transport)->updateServerSetting('port', 0));

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('Validation failed', $error->getMessage());
    }

    // ---- DLNA server ---------------------------------------------------

    public function testDlnaStatusReadsTheTopLevelBody(): void
    {
        // The AdminDlnaServerController is unenveloped — the status is at the top level.
        $transport = (new FakeTransport())->json(200, [
            'enabled' => true,
            'running' => true,
            'serverId' => 'srv-1',
            'friendlyName' => 'Phlix',
            'port' => 1900,
            'baseUrl' => 'http://10.0.0.5:1900/',
        ]);

        $status = $this->await($this->clientWith($transport)->dlnaStatus());

        self::assertInstanceOf(DlnaServerStatus::class, $status);
        self::assertTrue($status->enabled);
        self::assertTrue($status->running);
        self::assertSame('srv-1', $status->serverId);
        self::assertSame(1900, $status->port);
        self::assertSame('GET', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/admin/dlna/status', $transport->requestAt(0)['url']);
    }

    public function testDlnaStatusIgnoresAnEnvelopeDataKey(): void
    {
        // REGRESSION GUARD: the read is top-level, so a `{data:{enabled:…}}`
        // wrapper must NOT be unwrapped — it yields the not-configured default.
        $transport = (new FakeTransport())->json(200, $this->envelope([
            'enabled' => true,
            'running' => true,
            'port' => 1900,
        ]));

        $status = $this->await($this->clientWith($transport)->dlnaStatus());

        self::assertInstanceOf(DlnaServerStatus::class, $status);
        self::assertFalse($status->enabled, 'an enveloped {data} wrapper must not be read');
        self::assertFalse($status->running);
        self::assertNull($status->port);
        self::assertSame('Not configured', $status->stateLabel());
    }

    public function testDlnaStatusAttachesTheBearerToken(): void
    {
        $transport = (new FakeTransport())->json(200, ['enabled' => false, 'running' => false]);

        $this->await($this->clientWith($transport)->dlnaStatus());

        self::assertSame('Bearer access-1', $transport->requestAt(0)['headers']['Authorization'] ?? null);
    }

    public function testStartDlnaResolvesTheConfirmationOnSuccess(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true]);

        $message = $this->await($this->clientWith($transport)->startDlna());

        self::assertSame('DLNA server started', $message);
        self::assertSame('POST', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/admin/dlna/start', $transport->requestAt(0)['url']);
    }

    public function testStopDlnaResolvesTheConfirmationOnSuccess(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true]);

        $message = $this->await($this->clientWith($transport)->stopDlna());

        self::assertSame('DLNA server stopped', $message);
        self::assertSame('POST', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/admin/dlna/stop', $transport->requestAt(0)['url']);
    }

    public function testStartDlnaSurfacesTheBodyMessageOnA409NotTheGenericHttpText(): void
    {
        // LANDMINE: failure bodies use `message`, NOT `error`, so ApiClient::decode()
        // would build the generic "Request failed (HTTP 409)". startDlna must
        // re-surface the friendly `body['message']` instead.
        $transport = (new FakeTransport())->json(409, ['success' => false, 'message' => 'DLNA server is already running']);

        $error = $this->awaitError($this->clientWith($transport)->startDlna());

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('DLNA server is already running', $error->getMessage());
        self::assertSame(409, $error->statusCode);
    }

    public function testStopDlnaSurfacesTheBodyMessageOnA409(): void
    {
        $transport = (new FakeTransport())->json(409, ['success' => false, 'message' => 'DLNA server is not running']);

        $error = $this->awaitError($this->clientWith($transport)->stopDlna());

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('DLNA server is not running', $error->getMessage());
    }

    public function testStartDlnaSurfacesTheBodyMessageOnA500(): void
    {
        $transport = (new FakeTransport())->json(500, ['success' => false, 'message' => 'Failed to start DLNA server']);

        $error = $this->awaitError($this->clientWith($transport)->startDlna());

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('Failed to start DLNA server', $error->getMessage());
        self::assertSame(500, $error->statusCode);
    }

    public function testStartDlnaFallsBackToTheHttpMessageWhenNoBodyMessage(): void
    {
        // A failure with no `message` (and no `error`) leaves the generic text intact.
        $transport = (new FakeTransport())->json(500, ['success' => false]);

        $error = $this->awaitError($this->clientWith($transport)->startDlna());

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('Request failed (HTTP 500)', $error->getMessage());
    }

    public function testStartDlnaPropagatesAnAuthErrorUntouched(): void
    {
        // A 401 stays an AuthError (NOT re-wrapped as a plain ApiError) so the
        // screen can surface a session expiry rather than a toast. An empty
        // refresh token means the 401 is not retried.
        $transport = (new FakeTransport())->json(401, ['error' => 'Unauthorized']);
        $api = new ApiClient(self::BASE, $transport);
        $api->setToken(new TokenBundle('access-1', '', 'Bearer', time() + 3600));

        $error = $this->awaitError((new AdminClient($api))->startDlna());

        self::assertInstanceOf(\Phlix\Console\Api\AuthError::class, $error);
    }

    // ---- remote access -------------------------------------------------

    /**
     * A transport scripted with the four remote-access status GETs, in the order
     * the client fires them: hub, subdomain, relay, port-forward. ALL TOP-LEVEL
     * (the AdminHubController is unenveloped — admin envelopes are per-controller).
     */
    private function remoteTransport(): FakeTransport
    {
        return (new FakeTransport())
            ->json(200, [
                'paired' => true,
                'serverId' => 'srv-9',
                'hubUrl' => 'https://hub.example',
                'enrolledAt' => '2026-06-26T12:00:00+00:00',
                'lastHeartbeat' => null,
            ])
            ->json(200, [
                'claimed' => true,
                'subdomain' => 'myserver',
                'fqdn' => 'myserver.phlix.tv',
                'certPath' => '/c.pem',
                'keyPath' => '/k.pem',
            ])
            ->json(200, [
                'connected' => true,
                'active' => true,
                'endpoint' => null,
                'establishedAt' => '2026-06-26T10:00:00+00:00',
            ])
            ->json(200, [
                'enabled' => true,
                'method' => 'upnp',
                'externalIp' => '203.0.113.7',
                'externalPort' => 32400,
                'hostname' => 'home.example.com',
            ]);
    }

    public function testRemoteStatusFansOutToTheFourEndpointsAndAssembles(): void
    {
        $transport = $this->remoteTransport();

        $status = $this->await($this->clientWith($transport)->remoteStatus());

        self::assertInstanceOf(RemoteAccessStatus::class, $status);
        self::assertSame(4, $transport->requestCount(), 'all four sub-areas are fetched');

        // Each leg read its TOP-LEVEL body into its DTO.
        self::assertTrue($status->hub->paired);
        self::assertSame('https://hub.example', $status->hub->hubUrl);
        self::assertTrue($status->subdomain->claimed);
        self::assertSame('myserver.phlix.tv', $status->subdomain->fqdn);
        self::assertTrue($status->relay->connected);
        self::assertTrue($status->portForward->enabled);
        self::assertSame(32400, $status->portForward->externalPort);

        // The four GET URLs (in fan-out order).
        self::assertSame('GET', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/admin/remote/hub/status', $transport->requestAt(0)['url']);
        self::assertStringContainsString('/api/v1/admin/remote/subdomain/status', $transport->requestAt(1)['url']);
        self::assertStringContainsString('/api/v1/admin/remote/relay/status', $transport->requestAt(2)['url']);
        self::assertStringContainsString('/api/v1/admin/remote/portforward/status', $transport->requestAt(3)['url']);
    }

    public function testRemoteStatusReadsTheUnpairedAndDisabledDefaults(): void
    {
        $transport = (new FakeTransport())
            ->json(200, ['paired' => false])
            ->json(200, ['claimed' => false])
            ->json(200, ['connected' => false, 'active' => false])
            ->json(200, ['enabled' => false]);

        $status = $this->await($this->clientWith($transport)->remoteStatus());

        self::assertFalse($status->hub->paired);
        self::assertFalse($status->subdomain->claimed);
        self::assertFalse($status->relay->connected);
        self::assertFalse($status->portForward->enabled);
    }

    public function testRemoteStatusIgnoresAnEnvelopeDataKey(): void
    {
        // REGRESSION GUARD: the reads are TOP-LEVEL, so a `{data:{…}}` wrapper must
        // NOT be unwrapped — each leg falls back to its unpaired / unclaimed /
        // disconnected / disabled default.
        $transport = (new FakeTransport())
            ->json(200, $this->envelope(['paired' => true]))
            ->json(200, $this->envelope(['claimed' => true]))
            ->json(200, $this->envelope(['connected' => true, 'active' => true]))
            ->json(200, $this->envelope(['enabled' => true]));

        $status = $this->await($this->clientWith($transport)->remoteStatus());

        self::assertFalse($status->hub->paired, 'an enveloped {data} wrapper must not be read');
        self::assertFalse($status->subdomain->claimed);
        self::assertFalse($status->relay->connected);
        self::assertFalse($status->portForward->enabled);
    }

    public function testRemoteStatusAttachesTheBearerToken(): void
    {
        $transport = $this->remoteTransport();

        $this->await($this->clientWith($transport)->remoteStatus());

        self::assertSame('Bearer access-1', $transport->requestAt(0)['headers']['Authorization'] ?? null);
    }

    public function testRemoteStatusRejectsWhenALegFails(): void
    {
        $transport = (new FakeTransport())
            ->json(200, ['paired' => false])
            ->json(200, ['claimed' => false])
            ->json(500, ['success' => false, 'message' => 'Failed to load relay status.'])
            ->json(200, ['enabled' => false]);

        $error = $this->awaitError($this->clientWith($transport)->remoteStatus());

        self::assertInstanceOf(ApiError::class, $error);
    }

    public function testRelayEnablePostsAndResolvesTheConfirmation(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true]);

        $message = $this->await($this->clientWith($transport)->relayEnable());

        self::assertSame('Relay enabled', $message);
        self::assertSame('POST', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/admin/remote/relay/enable', $transport->requestAt(0)['url']);
    }

    public function testRelayDisablePostsAndResolvesTheConfirmation(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true]);

        $message = $this->await($this->clientWith($transport)->relayDisable());

        self::assertSame('Relay disabled', $message);
        self::assertStringContainsString('/api/v1/admin/remote/relay/disable', $transport->requestAt(0)['url']);
    }

    public function testRelayPingResolvesTheLatencyDerivedConfirmation(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true, 'latencyMs' => 42]);

        $message = $this->await($this->clientWith($transport)->relayPing());

        self::assertSame('Relay ping: 42ms', $message);
        self::assertStringContainsString('/api/v1/admin/remote/relay/ping', $transport->requestAt(0)['url']);
    }

    public function testRelayPingDefaultsTheLatencyToZeroWhenAbsent(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true]);

        $message = $this->await($this->clientWith($transport)->relayPing());

        self::assertSame('Relay ping: 0ms', $message);
    }

    public function testRelayPingSurfacesTheBodyMessageOnA409NotTheGenericHttpText(): void
    {
        // LANDMINE: the 409 failure body uses `message`, NOT `error`, so
        // ApiClient::decode() would build "Request failed (HTTP 409)". relayPing
        // must re-surface the friendly `body['message']`.
        $transport = (new FakeTransport())->json(409, ['success' => false, 'message' => 'Relay not connected.']);

        $error = $this->awaitError($this->clientWith($transport)->relayPing());

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('Relay not connected.', $error->getMessage());
        self::assertSame(409, $error->statusCode);
        self::assertStringNotContainsString('HTTP 409', $error->getMessage());
    }

    public function testPortForwardEnablePostsAndResolvesTheConfirmation(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true]);

        $message = $this->await($this->clientWith($transport)->portForwardEnable());

        self::assertSame('Port forwarding enabled', $message);
        self::assertStringContainsString('/api/v1/admin/remote/portforward/enable', $transport->requestAt(0)['url']);
    }

    public function testPortForwardEnableSurfacesTheBodyMessageOnA500(): void
    {
        $transport = (new FakeTransport())->json(500, ['success' => false, 'message' => 'Failed to enable port forwarding: upnp']);

        $error = $this->awaitError($this->clientWith($transport)->portForwardEnable());

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('Failed to enable port forwarding: upnp', $error->getMessage());
        self::assertSame(500, $error->statusCode);
    }

    public function testPortForwardDisablePostsAndResolvesTheConfirmation(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true]);

        $message = $this->await($this->clientWith($transport)->portForwardDisable());

        self::assertSame('Port forwarding disabled', $message);
        self::assertStringContainsString('/api/v1/admin/remote/portforward/disable', $transport->requestAt(0)['url']);
    }

    public function testSubdomainClaimResolvesTheFqdnDerivedConfirmation(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true, 'subdomain' => 'myserver', 'fqdn' => 'myserver.phlix.tv']);

        $message = $this->await($this->clientWith($transport)->subdomainClaim());

        self::assertSame('Claimed myserver.phlix.tv', $message);
        self::assertSame('POST', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/admin/remote/subdomain/claim', $transport->requestAt(0)['url']);
    }

    public function testSubdomainClaimFallsBackToSubdomainThenGenericConfirmation(): void
    {
        $sub = $this->await($this->clientWith((new FakeTransport())->json(200, ['success' => true, 'subdomain' => 'myserver']))->subdomainClaim());
        self::assertSame('Claimed myserver', $sub);

        $bare = $this->await($this->clientWith((new FakeTransport())->json(200, ['success' => true]))->subdomainClaim());
        self::assertSame('Subdomain claimed', $bare);
    }

    public function testSubdomainClaimSurfacesTheBodyMessageOnA409(): void
    {
        $transport = (new FakeTransport())->json(409, ['success' => false, 'message' => 'Subdomain already claimed.']);

        $error = $this->awaitError($this->clientWith($transport)->subdomainClaim());

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('Subdomain already claimed.', $error->getMessage());
        self::assertSame(409, $error->statusCode);
    }

    public function testSubdomainReleasePostsAndResolvesTheConfirmation(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true]);

        $message = $this->await($this->clientWith($transport)->subdomainRelease());

        self::assertSame('Subdomain released', $message);
        self::assertStringContainsString('/api/v1/admin/remote/subdomain/release', $transport->requestAt(0)['url']);
    }

    public function testSubdomainReleaseSurfacesTheBodyMessageOnA409(): void
    {
        $transport = (new FakeTransport())->json(409, ['success' => false, 'message' => 'Subdomain not claimed.']);

        $error = $this->awaitError($this->clientWith($transport)->subdomainRelease());

        self::assertSame('Subdomain not claimed.', $error->getMessage());
    }

    public function testHubUnenrollPostsAndResolvesTheConfirmation(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true]);

        $message = $this->await($this->clientWith($transport)->hubUnenroll());

        self::assertSame('Unenrolled from the hub', $message);
        self::assertSame('POST', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/admin/remote/hub/unenroll', $transport->requestAt(0)['url']);
    }

    public function testHubUnenrollSurfacesTheBodyMessageOnA500(): void
    {
        $transport = (new FakeTransport())->json(500, ['success' => false, 'message' => 'Failed to unenroll.']);

        $error = $this->awaitError($this->clientWith($transport)->hubUnenroll());

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('Failed to unenroll.', $error->getMessage());
        self::assertSame(500, $error->statusCode);
    }

    public function testRemoteActionFallsBackToTheHttpMessageWhenNoBodyMessage(): void
    {
        $transport = (new FakeTransport())->json(500, ['success' => false]);

        $error = $this->awaitError($this->clientWith($transport)->relayEnable());

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('Request failed (HTTP 500)', $error->getMessage());
    }

    public function testRemoteActionPropagatesAnAuthErrorUntouched(): void
    {
        $transport = (new FakeTransport())->json(401, ['error' => 'Unauthorized']);
        $api = new ApiClient(self::BASE, $transport);
        $api->setToken(new TokenBundle('access-1', '', 'Bearer', time() + 3600));

        $error = $this->awaitError((new AdminClient($api))->relayEnable());

        self::assertInstanceOf(\Phlix\Console\Api\AuthError::class, $error);
    }

    // ---- libraries -----------------------------------------------------

    /** The real top-level `GET /libraries` shape (NO `{success,data}` envelope). */
    private function librariesPayload(): array
    {
        return [
            'libraries' => [
                ['id' => 'lib-1', 'name' => 'Movies', 'type' => 'movie', 'item_count' => 42],
                ['id' => 'lib-2', 'name' => 'Shows', 'type' => 'series', 'item_count' => 7],
            ],
        ];
    }

    public function testLibrariesReadsTheTopLevelLibrariesList(): void
    {
        $transport = (new FakeTransport())->json(200, $this->librariesPayload());

        $libraries = $this->await($this->clientWith($transport)->libraries());

        self::assertCount(2, $libraries);
        self::assertContainsOnlyInstancesOf(\Phlix\Console\Api\Dto\Library::class, $libraries);
        self::assertSame('Movies', $libraries[0]->name);
        self::assertSame(42, $libraries[0]->itemCount);
        self::assertSame('GET', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/libraries', $transport->requestAt(0)['url']);
    }

    public function testLibrariesIgnoresAnEnvelopeDataKey(): void
    {
        // Regression guard: a `{data:{libraries}}` wrapper must yield an EMPTY list —
        // the endpoint is TOP-LEVEL, so a re-introduced `['data']` read would fail.
        $transport = (new FakeTransport())->json(200, ['data' => $this->librariesPayload()]);

        $libraries = $this->await($this->clientWith($transport)->libraries());

        self::assertSame([], $libraries);
    }

    public function testLibrariesSkipsNonArrayRowsAndToleratesANonListPayload(): void
    {
        $transport = (new FakeTransport())->json(200, ['libraries' => 'nope']);
        self::assertSame([], $this->await($this->clientWith($transport)->libraries()));

        $transport = (new FakeTransport())->json(200, ['libraries' => [['id' => 'lib-1'], 'junk', 5]]);
        $libraries = $this->await($this->clientWith($transport)->libraries());
        self::assertCount(1, $libraries);
        self::assertSame('lib-1', $libraries[0]->id);
    }

    public function testLibrariesSendsTheBearerToken(): void
    {
        $transport = (new FakeTransport())->json(200, $this->librariesPayload());

        $this->await($this->clientWith($transport)->libraries());

        self::assertSame('Bearer access-1', $transport->requestAt(0)['headers']['Authorization'] ?? null);
    }

    public function testScanLibraryPostsTheScanPathAndResolvesTheMessageOn202(): void
    {
        // 202 is a success (the 2xx range ApiClient::send accepts).
        $transport = (new FakeTransport())->json(202, ['job_id' => 'job-1', 'status' => 'queued', 'message' => 'Library scan queued']);

        $message = $this->await($this->clientWith($transport)->scanLibrary('lib-1'));

        self::assertSame('Library scan queued', $message);
        self::assertSame('POST', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/libraries/lib-1/scan', $transport->requestAt(0)['url']);
    }

    public function testRescanLibraryPostsTheRescanPathAndResolvesTheMessage(): void
    {
        $transport = (new FakeTransport())->json(202, ['job_id' => 'job-2', 'status' => 'queued', 'message' => 'Library rescan queued']);

        $message = $this->await($this->clientWith($transport)->rescanLibrary('lib-1'));

        self::assertSame('Library rescan queued', $message);
        self::assertStringContainsString('/api/v1/libraries/lib-1/rescan', $transport->requestAt(0)['url']);
    }

    public function testMatchLibraryMetadataPostsTheMatchPathAndResolvesTheMessage(): void
    {
        $transport = (new FakeTransport())->json(202, ['job_id' => 'job-3', 'status' => 'queued', 'message' => 'Metadata match queued']);

        $message = $this->await($this->clientWith($transport)->matchLibraryMetadata('lib-1'));

        self::assertSame('Metadata match queued', $message);
        self::assertStringContainsString('/api/v1/libraries/lib-1/match-metadata', $transport->requestAt(0)['url']);
    }

    public function testScanLibraryResolvesAnEmptyMessageWhenAbsent(): void
    {
        $transport = (new FakeTransport())->json(202, ['job_id' => 'job-1', 'status' => 'queued']);

        self::assertSame('', $this->await($this->clientWith($transport)->scanLibrary('lib-1')));
    }

    public function testScanLibraryRejectsWithTheServerErrorOnA404(): void
    {
        $transport = (new FakeTransport())->json(404, ['error' => 'Library not found']);

        $error = $this->awaitError($this->clientWith($transport)->scanLibrary('nope'));

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('Library not found', $error->getMessage());
    }

    public function testLibraryScanStatusReadsTheTopLevelScanStatus(): void
    {
        $transport = (new FakeTransport())->json(200, ['scan_status' => [
            'id' => 'job-1', 'library_id' => 'lib-1', 'type' => 'scan', 'status' => 'running',
            'items_found' => 12, 'items_added' => 3, 'items_updated' => 1, 'items_removed' => 0,
            'current_path' => '/media/movies/a.mkv',
        ]]);

        $job = $this->await($this->clientWith($transport)->libraryScanStatus('lib-1'));

        self::assertInstanceOf(\Phlix\Console\Api\Dto\Admin\ScanJob::class, $job);
        self::assertSame('running', $job->status);
        self::assertSame(12, $job->itemsFound);
        self::assertTrue($job->isActive());
        self::assertSame('GET', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/libraries/lib-1/scan-status', $transport->requestAt(0)['url']);
    }

    public function testLibraryScanStatusResolvesNullWhenNoJobYet(): void
    {
        $transport = (new FakeTransport())->json(200, ['scan_status' => null]);
        self::assertNull($this->await($this->clientWith($transport)->libraryScanStatus('lib-1')));

        // A missing key (or a non-array value) is likewise treated as "no job".
        $transport = (new FakeTransport())->json(200, []);
        self::assertNull($this->await($this->clientWith($transport)->libraryScanStatus('lib-1')));
    }

    public function testLibraryScanStatusRejectsWithTheServerErrorOnA404(): void
    {
        $transport = (new FakeTransport())->json(404, ['error' => 'Library not found']);

        $error = $this->awaitError($this->clientWith($transport)->libraryScanStatus('nope'));

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('Library not found', $error->getMessage());
    }

    // ---- live tv -------------------------------------------------------

    public function testLiveTvTunersReadsTheTopLevelNamedKey(): void
    {
        // The AdminLiveTvController uses the THIRD envelope: the data rides a
        // top-level named key (`tuners`) alongside `success`.
        $transport = (new FakeTransport())->json(200, [
            'success' => true,
            'tuners' => [
                ['id' => 't-1', 'tuner_id' => 'hdhr-1', 'type' => 'hdhomerun', 'name' => 'Living Room', 'enabled' => 1, 'status' => 'online'],
                ['id' => 't-2', 'tuner_id' => 'iptv-1', 'type' => 'iptv', 'name' => 'IPTV', 'enabled' => 0, 'status' => 'offline'],
            ],
        ]);

        $tuners = $this->await($this->clientWith($transport)->liveTvTuners());

        self::assertContainsOnlyInstancesOf(Tuner::class, $tuners);
        self::assertCount(2, $tuners);
        self::assertSame('Living Room', $tuners[0]->name);
        self::assertTrue($tuners[0]->enabled);
        self::assertFalse($tuners[1]->enabled);
        self::assertSame('GET', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/admin/livetv/tuners', $transport->requestAt(0)['url']);
    }

    public function testLiveTvTunersIgnoresAnEnvelopeDataKey(): void
    {
        // Regression guard: the list rides the top-level `tuners` key, NOT a
        // dashboard-style `{success, data:{tuners}}` wrapper.
        $transport = (new FakeTransport())->json(200, [
            'success' => true,
            'data' => ['tuners' => [['id' => 't-9', 'name' => 'ghost']]],
        ]);

        $tuners = $this->await($this->clientWith($transport)->liveTvTuners());

        self::assertSame([], $tuners, 'tuners are read top-level, not from data');
    }

    public function testLiveTvTunersToleratesAMissingTunersKey(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true]);

        self::assertSame([], $this->await($this->clientWith($transport)->liveTvTuners()));
    }

    public function testLiveTvTunersSkipsNonArrayRows(): void
    {
        $transport = (new FakeTransport())->json(200, [
            'tuners' => [['id' => 't-1', 'name' => 'Living Room'], 'nope', 7],
        ]);

        $tuners = $this->await($this->clientWith($transport)->liveTvTuners());

        self::assertCount(1, $tuners);
        self::assertSame('Living Room', $tuners[0]->name);
    }

    public function testLiveTvTunersAttachesTheBearerToken(): void
    {
        $transport = (new FakeTransport())->json(200, ['tuners' => []]);

        $this->await($this->clientWith($transport)->liveTvTuners());

        self::assertSame('Bearer access-1', $transport->requestAt(0)['headers']['Authorization'] ?? null);
    }

    public function testScanTunersRediscoversAndReadsTheNamedKey(): void
    {
        $transport = (new FakeTransport())->json(200, [
            'success' => true,
            'tuners' => [['id' => 't-1', 'name' => 'Living Room']],
        ]);

        $tuners = $this->await($this->clientWith($transport)->scanTuners());

        self::assertCount(1, $tuners);
        self::assertSame('Living Room', $tuners[0]->name);
        self::assertSame('GET', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/admin/livetv/tuners/scan', $transport->requestAt(0)['url']);
    }

    public function testScanTunersSurfacesTheFriendlyMessageOnA500NotTheGenericHttpText(): void
    {
        // LIST-method landmine: the real AdminLiveTvController::error() emits
        // {success:false, message:…} with NO `error` key, but ApiClient::decode()
        // only reads `error`, so without the AdminClient's reThrowFriendly() the
        // rejection would degrade to "Request failed (HTTP 500)".
        $transport = (new FakeTransport())->json(500, ['success' => false, 'message' => 'Tuner scan failed']);

        $error = $this->awaitError($this->clientWith($transport)->scanTuners());

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('Tuner scan failed', $error->getMessage());
        self::assertStringNotContainsString('Request failed', $error->getMessage());
    }

    public function testLiveTvFallsBackToTheGenericMessageWhenTheBodyCarriesNeitherMessageNorError(): void
    {
        // When the failure body has no friendly text at all, reThrowFriendly()
        // leaves the original ApiError (the generic "Request failed (HTTP …)")
        // untouched.
        $transport = (new FakeTransport())->json(500, ['success' => false]);

        $error = $this->awaitError($this->clientWith($transport)->liveTvTuners());

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('Request failed (HTTP 500)', $error->getMessage());
    }

    public function testLiveTvTunersPropagatesAnAuthErrorUntouched(): void
    {
        // A 401 must stay an AuthError (NOT re-wrapped by reThrowFriendly) so the
        // screen routes to a session expiry. An empty refresh token means the 401
        // is not retried.
        $transport = (new FakeTransport())->json(401, ['error' => 'Unauthorized']);
        $api = new ApiClient(self::BASE, $transport);
        $api->setToken(new TokenBundle('access-1', '', 'Bearer', time() + 3600));

        $error = $this->awaitError((new AdminClient($api))->liveTvTuners());

        self::assertInstanceOf(\Phlix\Console\Api\AuthError::class, $error);
    }

    public function testSetTunerEnabledPutsTheFlagAndResolvesTheTuner(): void
    {
        $transport = (new FakeTransport())->json(200, [
            'success' => true,
            'tuner' => ['id' => 't-1', 'name' => 'Living Room', 'enabled' => 0, 'status' => 'online'],
        ]);

        $tuner = $this->await($this->clientWith($transport)->setTunerEnabled('t-1', false));

        self::assertInstanceOf(Tuner::class, $tuner);
        self::assertSame('Living Room', $tuner->name);
        self::assertFalse($tuner->enabled);
        self::assertSame('PUT', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/admin/livetv/tuners/t-1', $transport->requestAt(0)['url']);
        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requestAt(0)['body'], true);
        self::assertFalse($body['enabled']);
    }

    public function testSetTunerEnabledToleratesAMissingTunerKey(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true]);

        $tuner = $this->await($this->clientWith($transport)->setTunerEnabled('t-1', true));

        self::assertInstanceOf(Tuner::class, $tuner);
        self::assertSame('', $tuner->id);
    }

    public function testSetTunerEnabledSurfacesTheFriendlyMessageOnA404NotTheGenericHttpText(): void
    {
        // Mutating-method counterpart of the list landmine: {success:false, message:…}.
        $transport = (new FakeTransport())->json(404, ['success' => false, 'message' => 'Tuner not found']);

        $error = $this->awaitError($this->clientWith($transport)->setTunerEnabled('missing', true));

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('Tuner not found', $error->getMessage());
        self::assertStringNotContainsString('Request failed', $error->getMessage());
    }

    public function testDeleteTunerSendsADeleteAndResolvesNull(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true]);

        $result = $this->await($this->clientWith($transport)->deleteTuner('t-1'));

        self::assertNull($result);
        self::assertSame('DELETE', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/admin/livetv/tuners/t-1', $transport->requestAt(0)['url']);
    }

    public function testDeleteTunerRejectsWithTheFriendlyMessageOnA404(): void
    {
        $transport = (new FakeTransport())->json(404, ['success' => false, 'message' => 'Tuner not found']);

        $error = $this->awaitError($this->clientWith($transport)->deleteTuner('missing'));

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('Tuner not found', $error->getMessage());
        self::assertStringNotContainsString('Request failed', $error->getMessage());
    }

    public function testLiveTvChannelsReadsTheNamedKey(): void
    {
        $transport = (new FakeTransport())->json(200, [
            'success' => true,
            'channels' => [
                ['id' => 'c-1', 'channel_id' => 'wxyz.1', 'name' => 'WXYZ', 'number' => 7, 'visibility' => 'visible', 'enabled' => 1],
                ['id' => 'c-2', 'channel_id' => 'abcd.1', 'name' => 'ABCD', 'number' => 9, 'visibility' => 'hidden', 'enabled' => 1],
            ],
        ]);

        $channels = $this->await($this->clientWith($transport)->liveTvChannels());

        self::assertContainsOnlyInstancesOf(Channel::class, $channels);
        self::assertCount(2, $channels);
        self::assertTrue($channels[0]->enabled);
        self::assertFalse($channels[1]->enabled, 'hidden visibility disables');
        self::assertStringContainsString('/api/v1/admin/livetv/channels', $transport->requestAt(0)['url']);
    }

    public function testSetChannelEnabledPutsTheFlagAndResolvesTheChannel(): void
    {
        $transport = (new FakeTransport())->json(200, [
            'success' => true,
            'channel' => ['id' => 'c-1', 'name' => 'WXYZ', 'visibility' => 'hidden', 'enabled' => 1],
        ]);

        $channel = $this->await($this->clientWith($transport)->setChannelEnabled('c-1', false));

        self::assertInstanceOf(Channel::class, $channel);
        self::assertSame('WXYZ', $channel->name);
        self::assertFalse($channel->enabled);
        self::assertSame('PUT', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/admin/livetv/channels/c-1', $transport->requestAt(0)['url']);
        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requestAt(0)['body'], true);
        self::assertFalse($body['enabled']);
    }

    public function testSetChannelEnabledToleratesAMissingChannelKey(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true]);

        $channel = $this->await($this->clientWith($transport)->setChannelEnabled('c-1', true));

        self::assertInstanceOf(Channel::class, $channel);
        self::assertSame('', $channel->id);
    }

    public function testLiveTvGuideReadsTheNamedKeyWithoutAChannelFilter(): void
    {
        $transport = (new FakeTransport())->json(200, [
            'success' => true,
            'programs' => [
                ['id' => 'p-1', 'channel_id' => 'wxyz.1', 'title' => 'The Show', 'start_time' => 1750000000, 'end_time' => 1750003600],
            ],
        ]);

        $programs = $this->await($this->clientWith($transport)->liveTvGuide());

        self::assertContainsOnlyInstancesOf(GuideProgram::class, $programs);
        self::assertCount(1, $programs);
        self::assertSame('The Show', $programs[0]->title);
        self::assertStringContainsString('/api/v1/admin/livetv/guide', $transport->requestAt(0)['url']);
        self::assertStringNotContainsString('channel_id=', $transport->requestAt(0)['url']);
    }

    public function testLiveTvGuideAppliesTheChannelFilterQuery(): void
    {
        $transport = (new FakeTransport())->json(200, ['programs' => []]);

        $this->await($this->clientWith($transport)->liveTvGuide('wxyz.1'));

        self::assertStringContainsString('channel_id=wxyz.1', $transport->requestAt(0)['url']);
    }

    public function testRefreshGuideResolvesTheImportedIntCount(): void
    {
        // On refresh, the `programs` key is the INT count of imported programs.
        $transport = (new FakeTransport())->json(200, ['success' => true, 'programs' => 142]);

        $count = $this->await($this->clientWith($transport)->refreshGuide());

        self::assertSame(142, $count);
        self::assertSame('POST', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/admin/livetv/guide/refresh', $transport->requestAt(0)['url']);
    }

    public function testRefreshGuideResolvesZeroWhenTheCountIsAbsent(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true]);

        self::assertSame(0, $this->await($this->clientWith($transport)->refreshGuide()));
    }

    public function testRefreshGuideRejectsWithTheFriendlyMessageOnA500(): void
    {
        $transport = (new FakeTransport())->json(500, ['success' => false, 'message' => 'EPG source unreachable']);

        $error = $this->awaitError($this->clientWith($transport)->refreshGuide());

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('EPG source unreachable', $error->getMessage());
        self::assertStringNotContainsString('Request failed', $error->getMessage());
    }

    public function testRecordingsReadsTheNamedKey(): void
    {
        $transport = (new FakeTransport())->json(200, [
            'success' => true,
            'recordings' => [
                ['recording_id' => 'rec-1', 'channel_id' => 'wxyz.1', 'title' => 'The Show', 'status' => 'recording', 'storage_size' => 1024],
            ],
        ]);

        $recordings = $this->await($this->clientWith($transport)->recordings());

        self::assertContainsOnlyInstancesOf(Recording::class, $recordings);
        self::assertCount(1, $recordings);
        self::assertSame('The Show', $recordings[0]->title);
        self::assertSame(1024, $recordings[0]->storageSize);
        self::assertStringContainsString('/api/v1/admin/livetv/recordings', $transport->requestAt(0)['url']);
        self::assertStringNotContainsString('status=', $transport->requestAt(0)['url']);
    }

    public function testRecordingsAppliesTheStatusFilterQuery(): void
    {
        $transport = (new FakeTransport())->json(200, ['recordings' => []]);

        $this->await($this->clientWith($transport)->recordings('completed'));

        self::assertStringContainsString('status=completed', $transport->requestAt(0)['url']);
    }

    public function testUpcomingRecordingsSendsTheLimitAndReadsTheNamedKey(): void
    {
        $transport = (new FakeTransport())->json(200, [
            'success' => true,
            'recordings' => [['recording_id' => 'rec-1', 'title' => 'The Show']],
        ]);

        $recordings = $this->await($this->clientWith($transport)->upcomingRecordings(5));

        self::assertCount(1, $recordings);
        self::assertSame('The Show', $recordings[0]->title);
        self::assertStringContainsString('/api/v1/admin/livetv/recordings/upcoming', $transport->requestAt(0)['url']);
        self::assertStringContainsString('limit=5', $transport->requestAt(0)['url']);
    }

    public function testUpcomingRecordingsDefaultsTheLimitToTen(): void
    {
        $transport = (new FakeTransport())->json(200, ['recordings' => []]);

        $this->await($this->clientWith($transport)->upcomingRecordings());

        self::assertStringContainsString('limit=10', $transport->requestAt(0)['url']);
    }

    public function testDeleteRecordingSendsADeleteAndResolvesNull(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true]);

        $result = $this->await($this->clientWith($transport)->deleteRecording('rec-1'));

        self::assertNull($result);
        self::assertSame('DELETE', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/admin/livetv/recordings/rec-1', $transport->requestAt(0)['url']);
    }

    public function testDeleteRecordingRejectsWithTheFriendlyMessageOnA404(): void
    {
        $transport = (new FakeTransport())->json(404, ['success' => false, 'message' => 'Recording not found']);

        $error = $this->awaitError($this->clientWith($transport)->deleteRecording('missing'));

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('Recording not found', $error->getMessage());
        self::assertStringNotContainsString('Request failed', $error->getMessage());
    }

    public function testSeriesRulesReadsTheNamedKey(): void
    {
        $transport = (new FakeTransport())->json(200, [
            'success' => true,
            'rules' => [
                ['rule_id' => 'sr-1', 'series_id' => 'SH1', 'title' => 'The Show', 'priority' => 5, 'days_ahead' => 14, 'is_active' => 1],
            ],
        ]);

        $rules = $this->await($this->clientWith($transport)->seriesRules());

        self::assertContainsOnlyInstancesOf(SeriesRule::class, $rules);
        self::assertCount(1, $rules);
        self::assertSame('The Show', $rules[0]->title);
        self::assertTrue($rules[0]->isActive);
        self::assertStringContainsString('/api/v1/admin/livetv/series-rules', $transport->requestAt(0)['url']);
    }

    public function testSeriesRulesToleratesAMissingRulesKey(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true]);

        self::assertSame([], $this->await($this->clientWith($transport)->seriesRules()));
    }

    public function testDeleteSeriesRuleSendsADeleteAndResolvesNull(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true]);

        $result = $this->await($this->clientWith($transport)->deleteSeriesRule('sr-1'));

        self::assertNull($result);
        self::assertSame('DELETE', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/admin/livetv/series-rules/sr-1', $transport->requestAt(0)['url']);
    }

    public function testDeleteSeriesRuleRejectsWithTheFriendlyMessageOnA404(): void
    {
        $transport = (new FakeTransport())->json(404, ['success' => false, 'message' => 'Rule not found']);

        $error = $this->awaitError($this->clientWith($transport)->deleteSeriesRule('missing'));

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame('Rule not found', $error->getMessage());
        self::assertStringNotContainsString('Request failed', $error->getMessage());
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
