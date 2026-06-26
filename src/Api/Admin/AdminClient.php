<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Admin;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Admin\AdminDashboard;
use Phlix\Console\Api\Dto\Admin\AdminUser;
use Phlix\Console\Api\Dto\Admin\Backup;
use Phlix\Console\Api\Dto\Admin\BackupSchedule;
use Phlix\Console\Api\Dto\Admin\LogFile;
use Phlix\Console\Api\Dto\Admin\LogTail;
use Phlix\Console\Api\Dto\Admin\Plugin;
use Phlix\Console\Api\Dto\Coerce;
use React\Promise\PromiseInterface;

use function React\Promise\all;

/**
 * The typed client for the server's admin surfaces, layered over
 * {@see ApiClient::send()} (the public authed-JSON seam). One method per admin
 * surface returns a `PromiseInterface<Dto>`; later surfaces add sibling methods.
 *
 * The App holds NO AdminClient field — it is built locally in the App's
 * admin-section handler from the shared {@see ApiClient} (mirroring the
 * BooksStore-built-locally pattern), so admin code needs no constructor wiring.
 */
final class AdminClient
{
    /** The base path every dashboard endpoint hangs off. */
    private const DASHBOARD = '/api/v1/admin/dashboard';

    /** The base path every log endpoint hangs off. */
    private const LOGS = '/api/v1/admin/logs';

    /** The base path every user-management endpoint hangs off. */
    private const USERS = '/api/v1/admin/users';

    /** The base path every plugin-management endpoint hangs off. */
    private const PLUGINS = '/api/v1/admin/plugins';

    /** The base path every backup-management endpoint hangs off. */
    private const BACKUP = '/api/v1/admin/backup';

    public function __construct(
        private readonly ApiClient $api,
    ) {
    }

    /**
     * Fetch all five dashboard panels concurrently and assemble the aggregate.
     * Each endpoint returns the `{success, data, count}` envelope; the `data`
     * payload (a list, or the storage map) is extracted and mapped into the
     * {@see AdminDashboard} DTO. A rejection of any leg rejects the whole call —
     * the screen surfaces it as a single failure with a retry.
     *
     * @return PromiseInterface<AdminDashboard>
     */
    public function dashboard(): PromiseInterface
    {
        /** @var array<string, PromiseInterface<array<string,mixed>>> $legs */
        $legs = [
            'nowPlaying' => $this->api->send('GET', self::DASHBOARD . '/now-playing'),
            'topUsers' => $this->api->send('GET', self::DASHBOARD . '/top-users', ['limit' => 10, 'days' => 30]),
            'topMedia' => $this->api->send('GET', self::DASHBOARD . '/top-media', ['limit' => 10, 'days' => 30]),
            'storage' => $this->api->send('GET', self::DASHBOARD . '/storage'),
            'activity' => $this->api->send('GET', self::DASHBOARD . '/activity', ['limit' => 20]),
        ];

        return all($legs)->then(static function (array $results): AdminDashboard {
            return AdminDashboard::fromParts(
                Coerce::map($results['nowPlaying']['data'] ?? null),
                Coerce::map($results['topUsers']['data'] ?? null),
                Coerce::map($results['topMedia']['data'] ?? null),
                Coerce::map($results['storage']['data'] ?? null),
                Coerce::map($results['activity']['data'] ?? null),
            );
        });
    }

    /**
     * List the available server log files (most-recently-modified first, per the
     * server). LogController returns a TOP-LEVEL `{files: [...]}` (no
     * `{success, data}` envelope — admin envelopes are per-controller); a
     * non-list payload yields an empty list.
     *
     * @return PromiseInterface<list<LogFile>>
     */
    public function logFiles(): PromiseInterface
    {
        return $this->api->send('GET', self::LOGS)->then(static fn (array $body): array => self::mapList(
            $body['files'] ?? null,
            static fn (array $row): LogFile => LogFile::fromArray($row),
        ));
    }

    /**
     * Tail the last $lines lines of a single log file. The returned
     * {@see LogTail} carries the `file`, its `lines`, and a `truncated` flag.
     *
     * @return PromiseInterface<LogTail>
     */
    public function tailLog(string $file, int $lines): PromiseInterface
    {
        return $this->api->send('GET', self::LOGS . '/tail', ['file' => $file, 'lines' => $lines])
            ->then(static fn (array $body): LogTail => LogTail::fromArray($body));
    }

    /**
     * Tail the last $lines lines across *every* log file, pre-merged
     * chronologically by the server. The returned {@see LogTail} carries the
     * merged source `files`, the prefixed `lines`, and a `truncated` flag.
     *
     * @return PromiseInterface<LogTail>
     */
    public function tailAllLogs(int $lines): PromiseInterface
    {
        return $this->api->send('GET', self::LOGS . '/tail-all', ['lines' => $lines])
            ->then(static fn (array $body): LogTail => LogTail::fromArray($body));
    }

    // ---- users ---------------------------------------------------------

    /**
     * List the server's users, optionally filtered by account status
     * (`pending|active|disabled`; null = all). UNLIKE the dashboard endpoints, the
     * admin `AdminUserController` returns its payload at the TOP LEVEL with NO
     * `{success, data}` envelope (envelopes are per-controller, and the admin route
     * group has no response-wrapping middleware), so the list is read straight from
     * `$body['users']`. A non-list payload yields an empty list.
     *
     * @return PromiseInterface<list<AdminUser>>
     */
    public function users(?string $status = null): PromiseInterface
    {
        $query = $status === null ? [] : ['status' => $status];

        return $this->api->send('GET', self::USERS, $query)->then(static function (array $body): array {
            return self::mapList(
                $body['users'] ?? null,
                static fn (array $row): AdminUser => AdminUser::fromArray($row),
            );
        });
    }

    /**
     * Approve a pending signup (status → active). Resolves the server `message`;
     * rejects with the server `error` on 400/404 (the {@see \Phlix\Console\Api\ApiError}
     * carries it as the exception message).
     *
     * @return PromiseInterface<string>
     */
    public function approveUser(string $id): PromiseInterface
    {
        return $this->userAction('POST', '/' . rawurlencode($id) . '/approve');
    }

    /**
     * Disable a user (status → disabled). Resolves the server `message`; rejects
     * with the server `error` on 400 (self / last admin) or 404.
     *
     * @return PromiseInterface<string>
     */
    public function disableUser(string $id): PromiseInterface
    {
        return $this->userAction('POST', '/' . rawurlencode($id) . '/disable');
    }

    /**
     * Reject (delete) a still-pending signup. Resolves the server `message`;
     * rejects with the server `error` on 400 (already active) or 404.
     *
     * @return PromiseInterface<string>
     */
    public function rejectUser(string $id): PromiseInterface
    {
        return $this->userAction('POST', '/' . rawurlencode($id) . '/reject');
    }

    /**
     * Delete a user outright. Resolves the server `message`; rejects with the
     * server `error` on 400 (last admin) or 404.
     *
     * @return PromiseInterface<string>
     */
    public function deleteUser(string $id): PromiseInterface
    {
        return $this->userAction('DELETE', '/' . rawurlencode($id));
    }

    /**
     * Promote/demote a user's admin flag. Resolves the server `message`; rejects
     * with the server `error` on 400 (self / last admin) or 404.
     *
     * @return PromiseInterface<string>
     */
    public function setUserAdmin(string $id, bool $isAdmin): PromiseInterface
    {
        return $this->userAction('POST', '/' . rawurlencode($id) . '/set-admin', ['is_admin' => $isAdmin]);
    }

    /**
     * Reset a user's password to a server-generated value, shown ONCE. Resolves
     * the `new_password` string (NOT the message — the caller reveals it); rejects
     * with the server `error` on 404.
     *
     * @return PromiseInterface<string>
     */
    public function resetUserPassword(string $id): PromiseInterface
    {
        return $this->api->send('POST', self::USERS . '/' . rawurlencode($id) . '/reset-password')
            ->then(static fn (array $body): string => Coerce::str($body['new_password'] ?? ''));
    }

    /**
     * Fire one mutating user action and resolve the server `message`. The shared
     * {@see ApiClient::send()} rejects non-2xx with the server `error` carried on
     * the {@see \Phlix\Console\Api\ApiError}, so this never has to inspect status.
     *
     * @param array<string,mixed>|null $body
     * @return PromiseInterface<string>
     */
    private function userAction(string $method, string $suffix, ?array $body = null): PromiseInterface
    {
        return $this->api->send($method, self::USERS . $suffix, [], $body)
            ->then(static fn (array $resp): string => Coerce::str($resp['message'] ?? ''));
    }

    // ---- plugins -------------------------------------------------------

    /**
     * List the installed plugins. Like the user endpoints (and UNLIKE the
     * dashboard), {@see \Phlix\Server\Http\Controllers\PluginAdminController}
     * returns its payload at the TOP LEVEL with NO `{success, data}` envelope, so
     * the list is read straight from `$body['plugins']`. A non-list payload yields
     * an empty list.
     *
     * @return PromiseInterface<list<Plugin>>
     */
    public function plugins(): PromiseInterface
    {
        return $this->api->send('GET', self::PLUGINS)->then(static function (array $body): array {
            return self::mapList(
                $body['plugins'] ?? null,
                static fn (array $row): Plugin => Plugin::fromArray($row),
            );
        });
    }

    /**
     * Install a plugin from a URL. The body is `{url}`; on 201 the server returns
     * `{plugin: ManifestJson}` (the freshly-installed plugin). Rejects with the
     * server `error` on 400 (missing / non-HTTPS URL) or 422 (install / signature
     * failure) — the {@see \Phlix\Console\Api\ApiError} carries it as the exception
     * message.
     *
     * @return PromiseInterface<Plugin>
     */
    public function installPlugin(string $url): PromiseInterface
    {
        return $this->api->send('POST', self::PLUGINS . '/install', [], ['url' => $url])
            ->then(static fn (array $body): Plugin => self::plugin($body));
    }

    /**
     * Enable a previously-installed plugin. Resolves the refreshed
     * {@see Plugin} (the server returns `{plugin: {name, enabled: true}}`); rejects
     * with the server `error` on 404 / 422.
     *
     * @return PromiseInterface<Plugin>
     */
    public function enablePlugin(string $name): PromiseInterface
    {
        return $this->api->send('POST', self::PLUGINS . '/' . rawurlencode($name) . '/enable')
            ->then(static fn (array $body): Plugin => self::plugin($body));
    }

    /**
     * Disable a currently-enabled plugin. Resolves the refreshed {@see Plugin}
     * (the server returns `{plugin: {name, enabled: false}}`); rejects with the
     * server `error` on 404.
     *
     * @return PromiseInterface<Plugin>
     */
    public function disablePlugin(string $name): PromiseInterface
    {
        return $this->api->send('POST', self::PLUGINS . '/' . rawurlencode($name) . '/disable')
            ->then(static fn (array $body): Plugin => self::plugin($body));
    }

    /**
     * Uninstall a plugin entirely (removes files + DB row). The server returns
     * `204 No Content`, so this resolves null; rejects with the server `error` on
     * 404.
     *
     * @return PromiseInterface<null>
     */
    public function uninstallPlugin(string $name): PromiseInterface
    {
        return $this->api->send('DELETE', self::PLUGINS . '/' . rawurlencode($name))
            ->then(static fn (array $body): ?Plugin => null);
    }

    /**
     * Map a `{plugin: {...}}` action response to a {@see Plugin}; a missing/
     * non-array `plugin` key yields a tolerant empty plugin (so a thin
     * enable/disable shape never breaks the mapping).
     *
     * @param array<string,mixed> $body
     */
    private static function plugin(array $body): Plugin
    {
        $plugin = $body['plugin'] ?? null;

        return Plugin::fromArray(is_array($plugin) ? $plugin : []);
    }

    // ---- backups -------------------------------------------------------

    /**
     * List the backup archives (most-recent first, per the server). UNLIKE the
     * users / plugins endpoints, {@see \Phlix\Server\Http\Controllers\Admin\BackupController}
     * IS enveloped (admin envelopes are per-controller), so the list is read from
     * `$body['data']`. A non-list payload yields an empty list.
     *
     * @return PromiseInterface<list<Backup>>
     */
    public function backups(): PromiseInterface
    {
        return $this->api->send('GET', self::BACKUP . '/list')->then(static function (array $body): array {
            return self::mapList(
                $body['data'] ?? null,
                static fn (array $row): Backup => Backup::fromArray($row),
            );
        });
    }

    /**
     * Create a new backup. The body carries an optional `{label}` (null when the
     * user gives none). Resolves the server `message`; rejects with the server
     * `error` on a 500 (the {@see \Phlix\Console\Api\ApiError} carries it as the
     * exception message).
     *
     * @return PromiseInterface<string>
     */
    public function createBackup(?string $label): PromiseInterface
    {
        $body = $label === null ? [] : ['label' => $label];

        return $this->backupAction('POST', '/create', $body);
    }

    /**
     * Delete a backup by id. Resolves the server `message`; rejects with the
     * server `error` on 400 (missing id) or 404 (unknown id).
     *
     * @return PromiseInterface<string>
     */
    public function deleteBackup(string $id): PromiseInterface
    {
        return $this->backupAction('DELETE', '/' . rawurlencode($id));
    }

    /**
     * Restore from a backup — DESTRUCTIVE: overwrites the current data. Resolves
     * the server `message`; rejects with the server `error` on 400 / 500.
     *
     * @return PromiseInterface<string>
     */
    public function restoreBackup(string $id): PromiseInterface
    {
        return $this->backupAction('POST', '/' . rawurlencode($id) . '/restore');
    }

    /**
     * Upload a backup to S3. Resolves the server `message`; rejects with the
     * server `error` on 400 / 500.
     *
     * @return PromiseInterface<string>
     */
    public function uploadBackupToS3(string $id): PromiseInterface
    {
        return $this->backupAction('POST', '/' . rawurlencode($id) . '/upload-s3');
    }

    /**
     * Fetch the backup schedule (auto-interval, retention, next-run). The
     * enveloped payload is read from `$body['data']` and mapped tolerantly.
     *
     * @return PromiseInterface<BackupSchedule>
     */
    public function backupSchedule(): PromiseInterface
    {
        return $this->api->send('GET', self::BACKUP . '/schedule')
            ->then(static fn (array $body): BackupSchedule => BackupSchedule::fromArray(Coerce::map($body['data'] ?? null)));
    }

    /**
     * Update the backup schedule (interval days + retention count). The PUT body
     * carries both fields; the enveloped response `data` is mapped back into a
     * {@see BackupSchedule}. Rejects with the server `error` on 400 (invalid
     * interval / retention).
     *
     * @return PromiseInterface<BackupSchedule>
     */
    public function updateBackupSchedule(int $intervalDays, int $retentionCount): PromiseInterface
    {
        return $this->api->send('PUT', self::BACKUP . '/schedule', [], [
            'auto_backup_interval_days' => $intervalDays,
            'retention_count' => $retentionCount,
        ])->then(static fn (array $body): BackupSchedule => BackupSchedule::fromArray(Coerce::map($body['data'] ?? null)));
    }

    /**
     * Fire one mutating backup action and resolve the server `message`. The
     * shared {@see ApiClient::send()} rejects non-2xx with the server `error`
     * carried on the {@see \Phlix\Console\Api\ApiError}, so this never inspects
     * status.
     *
     * @param array<string,mixed> $body
     * @return PromiseInterface<string>
     */
    private function backupAction(string $method, string $suffix, array $body = []): PromiseInterface
    {
        return $this->api->send($method, self::BACKUP . $suffix, [], $body === [] ? null : $body)
            ->then(static fn (array $resp): string => Coerce::str($resp['message'] ?? ''));
    }

    /**
     * Map every array row of a loosely-typed list payload through $factory,
     * skipping any non-array entry. Returns a re-indexed `list<T>`.
     *
     * @template T
     * @param mixed                               $rows
     * @param \Closure(array<array-key,mixed>): T $factory
     * @return list<T>
     */
    private static function mapList(mixed $rows, \Closure $factory): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $factory($row);
            }
        }

        return $out;
    }
}
