<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Admin;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\ApiError;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Admin\AdminDashboard;
use Phlix\Console\Api\Dto\Admin\AdminUser;
use Phlix\Console\Api\Dto\Admin\Backup;
use Phlix\Console\Api\Dto\Admin\BackupSchedule;
use Phlix\Console\Api\Dto\Admin\Channel;
use Phlix\Console\Api\Dto\Admin\DlnaServerStatus;
use Phlix\Console\Api\Dto\Admin\GuideProgram;
use Phlix\Console\Api\Dto\Admin\LogFile;
use Phlix\Console\Api\Dto\Admin\LogTail;
use Phlix\Console\Api\Dto\Admin\HubStatus;
use Phlix\Console\Api\Dto\Admin\Plugin;
use Phlix\Console\Api\Dto\Admin\PluginCatalogResult;
use Phlix\Console\Api\Dto\Admin\PluginDetail;
use Phlix\Console\Api\Dto\Admin\Parental\AccessSchedule;
use Phlix\Console\Api\Dto\Admin\Parental\ProfileStreamLimit;
use Phlix\Console\Api\Dto\Admin\Parental\ProfileTag;
use Phlix\Console\Api\Dto\Admin\PortForwardCandidate;
use Phlix\Console\Api\Dto\Admin\Profile;
use Phlix\Console\Api\Dto\Admin\PortForwardStatus;
use Phlix\Console\Api\Dto\Admin\Recording;
use Phlix\Console\Api\Dto\Admin\RelayStatus;
use Phlix\Console\Api\Dto\Admin\RemoteAccessStatus;
use Phlix\Console\Api\Dto\Admin\ScanJob;
use Phlix\Console\Api\Dto\Admin\SeriesRule;
use Phlix\Console\Api\Dto\Admin\ServerSettings;
use Phlix\Console\Api\Dto\Admin\Tuner;
use Phlix\Console\Api\Dto\Admin\SubdomainStatus;
use Phlix\Console\Api\Dto\Coerce;
use Phlix\Console\Api\Dto\Library;
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

    /** The base path the profile-by-id endpoints (update/delete/PIN) hang off. */
    private const PROFILES = '/api/v1/admin/profiles';

    /** The base path every plugin-management endpoint hangs off. */
    private const PLUGINS = '/api/v1/admin/plugins';

    /** The base path every backup-management endpoint hangs off. */
    private const BACKUP = '/api/v1/admin/backup';

    /** The base path the server-settings endpoints hang off. */
    private const SETTINGS = '/api/v1/admin/settings';

    /** The base path every DLNA-server endpoint hangs off. */
    private const DLNA = '/api/v1/admin/dlna';

    /** The base path every remote-access endpoint hangs off. */
    private const REMOTE = '/api/v1/admin/remote';

    /** The base path every library endpoint hangs off. */
    private const LIBRARIES = '/api/v1/libraries';

    /** The base path every Live-TV endpoint hangs off. */
    private const LIVETV = '/api/v1/admin/livetv';

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
     * Create a new user. The body is `{username, email, password, is_admin}`; on
     * 201 the server returns `{user_id, message}` and this resolves the `message`.
     * Rejects with the server `error` on a 400 (validation / duplicate email) —
     * the {@see \Phlix\Console\Api\ApiError} carries it as the exception message.
     * (A `field_errors` map also rides the 400 body but only `error` is surfaced.)
     *
     * @return PromiseInterface<string>
     */
    public function createUser(string $username, string $email, string $password, bool $isAdmin): PromiseInterface
    {
        return $this->api->send('POST', self::USERS, [], [
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'is_admin' => $isAdmin,
        ])->then(static fn (array $resp): string => Coerce::str($resp['message'] ?? ''));
    }

    /**
     * Update a user. Every field is optional: the PUT body carries ONLY the
     * non-null fields (a blank/unchanged field is passed as null and omitted), so
     * an unchanged value is left as-is server-side. On 200 the server returns
     * `{message}` and this resolves it. Rejects with the server `error` on a 400
     * (per-field validation) or 404 (unknown user).
     *
     * @return PromiseInterface<string>
     */
    public function updateUser(string $id, ?string $username, ?string $email, ?string $password): PromiseInterface
    {
        $body = [];
        if ($username !== null) {
            $body['username'] = $username;
        }
        if ($email !== null) {
            $body['email'] = $email;
        }
        if ($password !== null) {
            $body['password'] = $password;
        }

        return $this->api->send('PUT', self::USERS . '/' . rawurlencode($id), [], $body === [] ? null : $body)
            ->then(static fn (array $resp): string => Coerce::str($resp['message'] ?? ''));
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

    // ---- user profiles -------------------------------------------------

    /**
     * List a user's viewer profiles. Like the user endpoints (and UNLIKE the
     * dashboard), {@see \Phlix\Server\Http\Controllers\Admin\AdminProfileController}
     * returns its payload at the TOP LEVEL with NO `{success, data}` envelope
     * (admin envelopes are per-controller), so the list is read straight from
     * `$body['profiles']` — a `{data:{profiles}}` wrapper therefore yields the
     * tolerant empty list. Rejects with the server `error` on a 404 (unknown user).
     *
     * @return PromiseInterface<list<Profile>>
     */
    public function userProfiles(string $userId): PromiseInterface
    {
        return $this->api->send('GET', self::USERS . '/' . rawurlencode($userId) . '/profiles')
            ->then(static function (array $body): array {
                return self::mapList(
                    $body['profiles'] ?? null,
                    static fn (array $row): Profile => Profile::fromArray($row),
                );
            });
    }

    /**
     * Create a profile under a user. The body is `{name}` plus an optional
     * `{rating}` (the 0-6 content-rating index, omitted when null so the server
     * applies its default). On 201 the server returns `{profile_id, message}` and
     * this resolves the `message`. Rejects with the server `error` on a 400
     * (name length / rating range / max-profiles reached) — the
     * {@see \Phlix\Console\Api\ApiError} carries it as the exception message.
     *
     * @return PromiseInterface<string>
     */
    public function createProfile(string $userId, string $name, ?int $rating): PromiseInterface
    {
        $body = ['name' => $name];
        if ($rating !== null) {
            $body['rating'] = $rating;
        }

        return $this->api->send('POST', self::USERS . '/' . rawurlencode($userId) . '/profiles', [], $body)
            ->then(static fn (array $resp): string => Coerce::str($resp['message'] ?? ''));
    }

    /**
     * Update a profile. Both fields are optional: the PUT body carries ONLY the
     * provided (non-null) fields — `{name}` and/or `{rating}` (the 0-6 index) — so an
     * unchanged value is left as-is server-side. On 200 the server returns
     * `{message}` and this resolves it. Rejects with the server `error` on a 400 or
     * 404 (unknown profile).
     *
     * @return PromiseInterface<string>
     */
    public function updateProfile(string $profileId, ?string $name, ?int $rating): PromiseInterface
    {
        $body = [];
        if ($name !== null) {
            $body['name'] = $name;
        }
        if ($rating !== null) {
            $body['rating'] = $rating;
        }

        return $this->api->send('PUT', self::PROFILES . '/' . rawurlencode($profileId), [], $body === [] ? null : $body)
            ->then(static fn (array $resp): string => Coerce::str($resp['message'] ?? ''));
    }

    /**
     * Delete a profile. Resolves the server `message`; rejects with the server
     * `error` on a 404 (unknown profile).
     *
     * @return PromiseInterface<string>
     */
    public function deleteProfile(string $profileId): PromiseInterface
    {
        return $this->api->send('DELETE', self::PROFILES . '/' . rawurlencode($profileId))
            ->then(static fn (array $resp): string => Coerce::str($resp['message'] ?? ''));
    }

    /**
     * Set (or change) a profile's admin PIN. The body is `{pin}` (4 OR 6 digits —
     * the caller client-validates so a guaranteed-400 never round-trips). Resolves
     * the server `message`; rejects with the server `error` on a 400 (PIN length /
     * non-digit) or 404.
     *
     * @return PromiseInterface<string>
     */
    public function setProfilePin(string $profileId, string $pin): PromiseInterface
    {
        return $this->api->send('POST', self::PROFILES . '/' . rawurlencode($profileId) . '/pin', [], ['pin' => $pin])
            ->then(static fn (array $resp): string => Coerce::str($resp['message'] ?? ''));
    }

    /**
     * Clear a profile's admin PIN (DELETE). Resolves the server `message`; rejects
     * with the server `error` on a 404.
     *
     * @return PromiseInterface<string>
     */
    public function clearProfilePin(string $profileId): PromiseInterface
    {
        return $this->api->send('DELETE', self::PROFILES . '/' . rawurlencode($profileId) . '/pin')
            ->then(static fn (array $resp): string => Coerce::str($resp['message'] ?? ''));
    }

    // ---- parental controls (schedules) ---------------------------------

    /**
     * List a profile's access schedules. UNLIKE the user/plugins endpoints (and
     * LIKE the dashboard / backup), the access-schedule controller returns data
     * at the TOP LEVEL with NO `{success, data}` envelope, so the list is read
     * straight from `$body['schedules']`. A non-list payload yields an empty list.
     *
     * @return PromiseInterface<list<AccessSchedule>>
     */
    public function profileSchedules(int $profileId): PromiseInterface
    {
        return $this->api->send('GET', '/api/v1/profiles/' . $profileId . '/schedules')
            ->then(static function (array $body): array {
                return self::mapList(
                    $body['schedules'] ?? null,
                    static fn (array $row): AccessSchedule => AccessSchedule::fromArray($row),
                );
            });
    }

    /**
     * Create an access schedule for a profile. The body is `{name, start_time,
     * end_time, days_of_week, is_active?}`; on 201 the server returns
     * `{schedule_id, message}` and this resolves the `message`. Rejects with the
     * server `error` on a 400 (validation) — the {@see \Phlix\Console\Api\ApiError}
     * carries it as the exception message.
     *
     * @param list<string> $daysOfWeek
     * @return PromiseInterface<string>
     */
    public function createProfileSchedule(int $profileId, string $name, string $startTime, string $endTime, array $daysOfWeek, bool $isActive = true): PromiseInterface
    {
        return $this->api->send('POST', '/api/v1/profiles/' . $profileId . '/schedules', [], [
            'name' => $name,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'days_of_week' => $daysOfWeek,
            'is_active' => $isActive,
        ])->then(static fn (array $resp): string => Coerce::str($resp['message'] ?? ''));
    }

    /**
     * Delete a profile's access schedule. Resolves the server `message`; rejects
     * with the server `error` on a 404.
     *
     * @return PromiseInterface<string>
     */
    public function deleteProfileSchedule(int $profileId, int $scheduleId): PromiseInterface
    {
        return $this->api->send('DELETE', '/api/v1/profiles/' . $profileId . '/schedules/' . $scheduleId)
            ->then(static fn (array $resp): string => Coerce::str($resp['message'] ?? ''));
    }

    // ---- parental controls (tags) ---------------------------------------

    /**
     * List a profile's tags. Like the schedules endpoint, the tag controller
     * returns data at the TOP LEVEL with NO envelope, so the list is read straight
     * from `$body['tags']`. A non-list payload yields an empty list.
     *
     * @return PromiseInterface<list<ProfileTag>>
     */
    public function profileTags(int $profileId): PromiseInterface
    {
        return $this->api->send('GET', '/api/v1/profiles/' . $profileId . '/tags')
            ->then(static function (array $body): array {
                return self::mapList(
                    $body['tags'] ?? null,
                    static fn (array $row): ProfileTag => ProfileTag::fromArray($row),
                );
            });
    }

    /**
     * Add a tag to a profile. The body is `{tag, type}` (`type` is `blocked` or
     * `allowed`); on 201 the server returns `{tag_id, message}` and this resolves
     * the `message`. Rejects with the server `error` on a 400 (validation) —
     * the {@see \Phlix\Console\Api\ApiError} carries it as the exception message.
     *
     * @return PromiseInterface<string>
     */
    public function addProfileTag(int $profileId, string $tag, string $tagType): PromiseInterface
    {
        return $this->api->send('POST', '/api/v1/profiles/' . $profileId . '/tags', [], [
            'tag' => $tag,
            'type' => $tagType,
        ])->then(static fn (array $resp): string => Coerce::str($resp['message'] ?? ''));
    }

    /**
     * Remove a tag from a profile. Resolves the server `message`; rejects with
     * the server `error` on a 404.
     *
     * @return PromiseInterface<string>
     */
    public function deleteProfileTag(int $profileId, int $tagId): PromiseInterface
    {
        return $this->api->send('DELETE', '/api/v1/profiles/' . $profileId . '/tags/' . $tagId)
            ->then(static fn (array $resp): string => Coerce::str($resp['message'] ?? ''));
    }

    // ---- parental controls (stream limits) ------------------------------

    /**
     * Get a profile's stream limits. The response shape is
     * `{stream_limits: {max_concurrent_streams, max_total_bandwidth_kbps}}`.
     *
     * @return PromiseInterface<ProfileStreamLimit>
     */
    public function profileStreamLimits(int $profileId): PromiseInterface
    {
        return $this->api->send('GET', '/api/v1/profiles/' . $profileId . '/stream-limits')
            ->then(static fn (array $body): ProfileStreamLimit => ProfileStreamLimit::fromArray(
                Coerce::map($body['stream_limits'] ?? null),
            ));
    }

    /**
     * Update a profile's stream limits. The body is `{max_concurrent_streams}` and
     * optionally `{max_total_bandwidth_kbps}`; on 200 the server returns
     * `{stream_limits, message}` and this resolves the `message`. Rejects with the
     * server `error` on a 400 (validation) — the {@see \Phlix\Console\Api\ApiError}
     * carries it as the exception message.
     *
     * @return PromiseInterface<string>
     */
    public function updateProfileStreamLimits(int $profileId, int $maxConcurrentStreams, ?int $maxTotalBandwidthKbps = null): PromiseInterface
    {
        $body = ['max_concurrent_streams' => $maxConcurrentStreams];
        if ($maxTotalBandwidthKbps !== null) {
            $body['max_total_bandwidth_kbps'] = $maxTotalBandwidthKbps;
        }

        return $this->api->send('PUT', '/api/v1/profiles/' . $profileId . '/stream-limits', [], $body)
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
     * Fetch one plugin's full detail (`GET .../plugins/{name}`). Like the plugin
     * LIST (and UNLIKE the dashboard), {@see \Phlix\Server\Http\Controllers\PluginAdminController}
     * is unenveloped (admin envelopes are per-controller), so the detail is read
     * straight from `$body['plugin']` — NOT `$body['data']['plugin']`; a
     * `{data:{plugin}}` wrapper therefore yields the tolerant empty default. The
     * name is rawurlencoded into the path. Rejects with the server `error` on 404.
     *
     * @return PromiseInterface<PluginDetail>
     */
    public function pluginDetail(string $name): PromiseInterface
    {
        return $this->api->send('GET', self::PLUGINS . '/' . rawurlencode($name))
            ->then(static fn (array $body): PluginDetail => self::pluginDetailOf($body));
    }

    /**
     * Update one plugin setting. The PUT body is `{settings:{$key:$value}}`; the
     * value is already coerced to its field type by the caller (a real bool / int /
     * float / string / array — and for a secret, only ever a non-blank value, since
     * a blank secret edit is a caller-side no-op). Resolves the REFRESHED
     * {@see PluginDetail} the server returns under `$body['plugin']` (so the screen
     * swaps the whole detail in). Rejects with the server `error` on a 400
     * (`plugin.settings.invalid` — unknown key / wrong type) or 404 — the
     * {@see \Phlix\Console\Api\ApiError} carries it as the exception message.
     *
     * @param bool|int|float|string|array<array-key,mixed> $value
     * @return PromiseInterface<PluginDetail>
     */
    public function updatePluginSetting(string $name, string $key, bool|int|float|string|array $value): PromiseInterface
    {
        return $this->api->send('PUT', self::PLUGINS . '/' . rawurlencode($name) . '/settings', [], ['settings' => [$key => $value]])
            ->then(static fn (array $body): PluginDetail => self::pluginDetailOf($body));
    }

    /**
     * Map a `{plugin: {...}}` detail response to a {@see PluginDetail}; a missing/
     * non-array `plugin` key (e.g. a wrong `{data:{plugin}}` wrapper) yields a
     * tolerant empty detail so the mapping never breaks.
     *
     * @param array<string,mixed> $body
     */
    private static function pluginDetailOf(array $body): PluginDetail
    {
        $plugin = $body['plugin'] ?? null;

        return PluginDetail::fromArray(is_array($plugin) ? $plugin : []);
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

    // ---- plugin catalog ------------------------------------------------

    /**
     * Fetch the plugin catalog (browse + install-from-catalog + manage sources).
     * Like the plugin LIST (and UNLIKE the dashboard), the
     * {@see \Phlix\Server\Http\Controllers\PluginCatalogController} is unenveloped
     * (admin envelopes are per-controller), so the WHOLE body is read TOP-LEVEL into
     * {@see PluginCatalogResult::fromArray} — NOT `$body['data']`. A `{data:{...}}`
     * wrapper therefore yields the tolerant empty/default result. Rejects with the
     * server `error` on a non-2xx.
     *
     * @return PromiseInterface<PluginCatalogResult>
     */
    public function pluginCatalog(): PromiseInterface
    {
        return $this->api->send('GET', self::PLUGINS . '/catalog')
            ->then(static fn (array $body): PluginCatalogResult => PluginCatalogResult::fromArray($body));
    }

    /**
     * Add a catalog source URL. The body is `{url}`; on success the server returns
     * the updated `{sources}` list, read TOP-LEVEL from `$body['sources']`. Rejects
     * with the server `error` on a 400 (invalid / unreachable URL) — the
     * {@see \Phlix\Console\Api\ApiError} carries it as the exception message.
     *
     * @return PromiseInterface<list<string>>
     */
    public function addCatalogSource(string $url): PromiseInterface
    {
        return $this->api->send('POST', self::PLUGINS . '/catalog/sources', [], ['url' => $url])
            ->then(static fn (array $body): array => Coerce::stringList($body['sources'] ?? null));
    }

    /**
     * Remove a catalog source URL. The body is `{url}`; on success the server
     * returns the updated `{sources}` list, read TOP-LEVEL from `$body['sources']`.
     * Rejects with the server `error` on a 400.
     *
     * @return PromiseInterface<list<string>>
     */
    public function removeCatalogSource(string $url): PromiseInterface
    {
        return $this->api->send('DELETE', self::PLUGINS . '/catalog/sources', [], ['url' => $url])
            ->then(static fn (array $body): array => Coerce::stringList($body['sources'] ?? null));
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

    // ---- server settings -----------------------------------------------

    /**
     * Fetch the full server-settings set. UNLIKE the users / plugins endpoints
     * (and LIKE the dashboard / backup), {@see \Phlix\Server\Http\Controllers\Admin\AdminSettingsController}
     * IS enveloped (admin envelopes are per-controller), so the
     * `{settings, overridden, types}` maps are read from `$body['data']`. A
     * top-level `{settings}` with no `data` wrapper yields an empty set.
     *
     * @return PromiseInterface<ServerSettings>
     */
    public function serverSettings(): PromiseInterface
    {
        return $this->api->send('GET', self::SETTINGS)
            ->then(static fn (array $body): ServerSettings => ServerSettings::fromArray(Coerce::map($body['data'] ?? null)));
    }

    /**
     * Update one setting. The PUT body is `{settings:{$key:$value}}`; the value
     * is already coerced to its internal type by the caller (a real bool / int /
     * float / string / array). Resolves the server `message` (the PUT response
     * carries no `types`, so the screen refetches via GET). Rejects with the
     * server `error` on a 400 (validation / invalid payload) or 500 — the
     * {@see \Phlix\Console\Api\ApiError} carries it as the exception message.
     *
     * @param bool|int|float|string|array<array-key,mixed> $value
     * @return PromiseInterface<string>
     */
    public function updateServerSetting(string $key, bool|int|float|string|array $value): PromiseInterface
    {
        return $this->api->send('PUT', self::SETTINGS, [], ['settings' => [$key => $value]])
            ->then(static fn (array $body): string => Coerce::str($body['message'] ?? ''));
    }

    // ---- DLNA server ---------------------------------------------------

    /**
     * Fetch the DLNA media-server status. Like the users / plugins endpoints
     * (and UNLIKE the dashboard / backup / settings), the
     * {@see \Phlix\Server\Http\Controllers\Admin\AdminDlnaServerController}
     * returns its payload at the TOP LEVEL with NO `{success, data}` envelope, so
     * the status is read straight from `$body` (not `$body['data']`). A
     * `{data:{...}}` wrapper therefore yields the tolerant not-configured default.
     *
     * @return PromiseInterface<DlnaServerStatus>
     */
    public function dlnaStatus(): PromiseInterface
    {
        return $this->api->send('GET', self::DLNA . '/status')
            ->then(static fn (array $body): DlnaServerStatus => DlnaServerStatus::fromArray($body));
    }

    /**
     * Start the DLNA server. Resolves a confirmation string on a 200; on a 409
     * (not configured / already running) or 500 the failure body carries the
     * human text in `message`, NOT `error`, so {@see ApiClient::decode()} would
     * surface the generic "Request failed (HTTP …)". This maps the
     * {@see ApiError::$body}['message'] back onto the rejection so the screen
     * toasts e.g. "DLNA server is already running".
     *
     * @return PromiseInterface<string>
     */
    public function startDlna(): PromiseInterface
    {
        return $this->dlnaAction('/start', 'DLNA server started');
    }

    /**
     * Stop the DLNA server. Resolves a confirmation string on a 200; on a 409
     * (not configured / not running) or 500 the friendly `message` is surfaced
     * (see {@see startDlna()} for the message-not-error landmine).
     *
     * @return PromiseInterface<string>
     */
    public function stopDlna(): PromiseInterface
    {
        return $this->dlnaAction('/stop', 'DLNA server stopped');
    }

    /**
     * Fire one DLNA start/stop POST and resolve the given confirmation string.
     * On rejection, prefer the server's friendly `message` (carried on the
     * {@see ApiError::$body}) over the generic HTTP message — the start/stop
     * failure bodies put their human text in `message`, not `error`.
     *
     * @return PromiseInterface<string>
     */
    private function dlnaAction(string $suffix, string $confirmation): PromiseInterface
    {
        return $this->api->send('POST', self::DLNA . $suffix)->then(
            static fn (array $resp): string => $confirmation,
            self::reThrowFriendly(...),
        );
    }

    // ---- remote access -------------------------------------------------

    /**
     * Fetch all four remote-access statuses concurrently and assemble the
     * aggregate. Each of the four GETs is unenveloped (the
     * {@see \Phlix\Server\Http\Controllers\Admin\AdminHubController} is
     * per-controller TOP-LEVEL), so each leg's body is read straight into its DTO
     * (NOT `$body['data']`); a `{data:{...}}` wrapper therefore yields that
     * sub-area's tolerant unpaired / unclaimed / disconnected / disabled default.
     * The four GETs return 200 normally, so no per-leg tolerance is applied — a
     * genuine rejection rejects the whole call (the screen's error + `r` retry).
     *
     * @return PromiseInterface<RemoteAccessStatus>
     */
    public function remoteStatus(): PromiseInterface
    {
        /** @var array<string, PromiseInterface<array<string,mixed>>> $legs */
        $legs = [
            'hub' => $this->api->send('GET', self::REMOTE . '/hub/status'),
            'subdomain' => $this->api->send('GET', self::REMOTE . '/subdomain/status'),
            'relay' => $this->api->send('GET', self::REMOTE . '/relay/status'),
            'portforward' => $this->api->send('GET', self::REMOTE . '/portforward/status'),
        ];

        return all($legs)->then(static function (array $results): RemoteAccessStatus {
            return RemoteAccessStatus::fromParts(
                HubStatus::fromArray($results['hub']),
                SubdomainStatus::fromArray($results['subdomain']),
                RelayStatus::fromArray($results['relay']),
                PortForwardStatus::fromArray($results['portforward']),
            );
        });
    }

    /**
     * Enable the relay tunnel. Resolves a confirmation string on a 200; on a
     * failure the friendly server `message` is surfaced (see the message-not-error
     * landmine on {@see remoteAction()}).
     *
     * @return PromiseInterface<string>
     */
    public function relayEnable(): PromiseInterface
    {
        return $this->remoteAction('/relay/enable', static fn (array $resp): string => 'Relay enabled');
    }

    /**
     * Disable the relay tunnel. Resolves a confirmation string; surfaces the
     * friendly `message` on failure.
     *
     * @return PromiseInterface<string>
     */
    public function relayDisable(): PromiseInterface
    {
        return $this->remoteAction('/relay/disable', static fn (array $resp): string => 'Relay disabled');
    }

    /**
     * Ping the relay tunnel. Resolves "Relay ping: {latencyMs}ms" derived from the
     * 200 body; a 409 (relay not connected) surfaces the friendly `message`.
     *
     * @return PromiseInterface<string>
     */
    public function relayPing(): PromiseInterface
    {
        return $this->remoteAction(
            '/relay/ping',
            static fn (array $resp): string => 'Relay ping: ' . Coerce::int($resp['latencyMs'] ?? 0) . 'ms',
        );
    }

    /**
     * Enable port forwarding. Resolves a confirmation string; a 500 surfaces the
     * friendly `message`.
     *
     * @return PromiseInterface<string>
     */
    public function portForwardEnable(): PromiseInterface
    {
        return $this->remoteAction('/portforward/enable', static fn (array $resp): string => 'Port forwarding enabled');
    }

    /**
     * Disable port forwarding. Resolves a confirmation string; surfaces the
     * friendly `message` on failure.
     *
     * @return PromiseInterface<string>
     */
    public function portForwardDisable(): PromiseInterface
    {
        return $this->remoteAction('/portforward/disable', static fn (array $resp): string => 'Port forwarding disabled');
    }

    /**
     * List the discovered port-forward candidates — the reachable hostname URLs
     * the server probed for itself, each with its detected external IP + port. Like
     * the remote-access status GETs (and UNLIKE the dashboard), the
     * {@see \Phlix\Server\Http\Controllers\Admin\AdminHubController} is per-controller
     * TOP-LEVEL, so the list is read straight from `$body['candidates']` via
     * {@see mapList}; a `{data:{candidates}}` wrapper therefore yields an empty list.
     * A rejection re-surfaces the server's friendly `message` — the candidates 500
     * body uses `message`, NOT `error` (see {@see reThrowFriendly()}).
     *
     * @return PromiseInterface<list<PortForwardCandidate>>
     */
    public function portForwardCandidates(): PromiseInterface
    {
        return $this->api->send('GET', self::REMOTE . '/portforward/candidates')->then(static function (array $body): array {
            return self::mapList(
                $body['candidates'] ?? null,
                static fn (array $row): PortForwardCandidate => PortForwardCandidate::fromArray($row),
            );
        })->otherwise(self::reThrowFriendly(...));
    }

    /**
     * Claim a managed subdomain. Resolves "Claimed {fqdn}" derived from the 200
     * body; a 409 (already claimed) surfaces the friendly `message`.
     *
     * @return PromiseInterface<string>
     */
    public function subdomainClaim(): PromiseInterface
    {
        return $this->remoteAction('/subdomain/claim', static function (array $resp): string {
            $fqdn = Coerce::nstr($resp['fqdn'] ?? null) ?? Coerce::nstr($resp['subdomain'] ?? null);

            return $fqdn === null ? 'Subdomain claimed' : 'Claimed ' . $fqdn;
        });
    }

    /**
     * Release the managed subdomain. Resolves a confirmation string; a 409 (not
     * claimed) surfaces the friendly `message`.
     *
     * @return PromiseInterface<string>
     */
    public function subdomainRelease(): PromiseInterface
    {
        return $this->remoteAction('/subdomain/release', static fn (array $resp): string => 'Subdomain released');
    }

    /**
     * Unenroll from the hub (remove the pairing). Resolves a confirmation string;
     * a 500 surfaces the friendly `message`.
     *
     * @return PromiseInterface<string>
     */
    public function hubUnenroll(): PromiseInterface
    {
        return $this->remoteAction('/hub/unenroll', static fn (array $resp): string => 'Unenrolled from the hub');
    }

    /**
     * Fire one remote-access POST and resolve a confirmation string derived from
     * the 200 body. On rejection, prefer the server's friendly `message` (carried
     * on {@see ApiError::$body}) over the generic HTTP text — the remote-access
     * failure bodies (409 / 500) put their human text in `message`, NOT `error`,
     * so {@see ApiClient::decode()} would otherwise surface "Request failed
     * (HTTP …)". An {@see AuthError} (401) is let through untouched so the screen
     * can surface a session expiry rather than a toast.
     *
     * @param \Closure(array<string,mixed>): string $confirm
     * @return PromiseInterface<string>
     */
    private function remoteAction(string $suffix, \Closure $confirm): PromiseInterface
    {
        return $this->api->send('POST', self::REMOTE . $suffix)->then(
            static fn (array $resp): string => $confirm($resp),
            self::reThrowFriendly(...),
        );
    }

    // ---- libraries -----------------------------------------------------

    /**
     * List the media libraries (each row + the router-added `item_count`). Like
     * the users / plugins endpoints (and UNLIKE the dashboard / backup / settings),
     * {@see \Phlix\Server\Http\Controllers\LibraryController} returns its payload at
     * the TOP LEVEL with NO `{success, data}` envelope, so the list is read straight
     * from `$body['libraries']`. A non-list payload yields an empty list.
     *
     * @return PromiseInterface<list<Library>>
     */
    public function libraries(): PromiseInterface
    {
        return $this->api->send('GET', self::LIBRARIES)->then(static function (array $body): array {
            return self::mapList(
                $body['libraries'] ?? null,
                static fn (array $row): Library => Library::fromArray($row),
            );
        });
    }

    /**
     * Enqueue a scan of a library (add new items). The server returns 202 with
     * `{job_id, status, message}`; this resolves the `message`. Rejects with the
     * server `error` on 404 (the {@see \Phlix\Console\Api\ApiError} carries it as
     * the exception message).
     *
     * @return PromiseInterface<string>
     */
    public function scanLibrary(string $id): PromiseInterface
    {
        return $this->enqueue('/' . rawurlencode($id) . '/scan');
    }

    /**
     * Enqueue a rescan of a library — DESTRUCTIVE-ish: purges then rescans. The
     * server returns 202 with `{job_id, status, message}`; this resolves the
     * `message`. Rejects with the server `error` on 404.
     *
     * @return PromiseInterface<string>
     */
    public function rescanLibrary(string $id): PromiseInterface
    {
        return $this->enqueue('/' . rawurlencode($id) . '/rescan');
    }

    /**
     * Enqueue a metadata re-match of a library (reuses the scan-job queue, so
     * scan-status shows its progress too). The server returns 202 with
     * `{job_id, status, message}`; this resolves the `message`. Rejects with the
     * server `error` on 404.
     *
     * @return PromiseInterface<string>
     */
    public function matchLibraryMetadata(string $id): PromiseInterface
    {
        return $this->enqueue('/' . rawurlencode($id) . '/match-metadata');
    }

    /**
     * Fetch a library's latest scan-job status. Read TOP-LEVEL from
     * `$body['scan_status']`; a null (or non-array) value means no job has run yet
     * and resolves null. Rejects (via {@see ApiClient::send()}) on a non-2xx.
     *
     * @return PromiseInterface<?ScanJob>
     */
    public function libraryScanStatus(string $id): PromiseInterface
    {
        return $this->api->send('GET', self::LIBRARIES . '/' . rawurlencode($id) . '/scan-status')
            ->then(static function (array $body): ?ScanJob {
                $status = $body['scan_status'] ?? null;

                return is_array($status) ? ScanJob::fromArray($status) : null;
            });
    }

    /**
     * Fetch a library's recent scan-job history (newest first; the server clamps
     * `limit` to `[1,100]`, default 20). Read TOP-LEVEL from `$body['history']` via
     * {@see mapList} — like the other LibraryController endpoints there is NO
     * `{success, data}` envelope, so a `{data:{history}}` wrapper yields an empty
     * list. The rows are the SAME shape as scan-status, so each is mapped to a
     * {@see ScanJob}. Rejects (via {@see ApiClient::send()}) with the server `error`
     * on a 404.
     *
     * @return PromiseInterface<list<ScanJob>>
     */
    public function libraryScanHistory(string $id, int $limit = 20): PromiseInterface
    {
        return $this->api->send('GET', self::LIBRARIES . '/' . rawurlencode($id) . '/scan-history', ['limit' => $limit])
            ->then(static function (array $body): array {
                return self::mapList(
                    $body['history'] ?? null,
                    static fn (array $row): ScanJob => ScanJob::fromArray($row),
                );
            });
    }

    /**
     * Fire one scan-enqueue POST (scan / rescan / match-metadata) and resolve the
     * server `message`. The server returns 202 (a success in the 2xx range that
     * {@see ApiClient::send()} accepts); a non-2xx rejects with the server `error`.
     *
     * @return PromiseInterface<string>
     */
    private function enqueue(string $suffix): PromiseInterface
    {
        return $this->api->send('POST', self::LIBRARIES . $suffix)
            ->then(static fn (array $resp): string => Coerce::str($resp['message'] ?? ''));
    }

    // ---- live tv -------------------------------------------------------

    /**
     * List the configured Live-TV tuners. {@see \Phlix\Server\Http\Controllers\Admin\AdminLiveTvController}
     * uses a THIRD envelope pattern — the data rides a TOP-LEVEL NAMED KEY
     * alongside `success` (`{success, tuners:[...]}`, NOT `{success, data}` and NOT
     * bare), so the list is read straight from `$body['tuners']`. A `{data:{tuners}}`
     * wrapper (the WRONG envelope) therefore yields an empty list. A rejection
     * re-surfaces the server's friendly `message` (see {@see reThrowFriendly()}).
     *
     * @return PromiseInterface<list<Tuner>>
     */
    public function liveTvTuners(): PromiseInterface
    {
        return $this->api->send('GET', self::LIVETV . '/tuners')->then(static function (array $body): array {
            return self::mapList(
                $body['tuners'] ?? null,
                static fn (array $row): Tuner => Tuner::fromArray($row),
            );
        })->otherwise(self::reThrowFriendly(...));
    }

    /**
     * Re-discover tuners (GET `/tuners/scan` rescans hardware/IPTV sources and
     * returns the refreshed set). Reads the top-level `tuners` named key. A
     * rejection re-surfaces the server's friendly `message` (see
     * {@see reThrowFriendly()}).
     *
     * @return PromiseInterface<list<Tuner>>
     */
    public function scanTuners(): PromiseInterface
    {
        return $this->api->send('GET', self::LIVETV . '/tuners/scan')->then(static function (array $body): array {
            return self::mapList(
                $body['tuners'] ?? null,
                static fn (array $row): Tuner => Tuner::fromArray($row),
            );
        })->otherwise(self::reThrowFriendly(...));
    }

    /**
     * Enable/disable a tuner (PUT `{enabled}`). Resolves the single updated
     * {@see Tuner} from the top-level `tuner` named key; a missing/non-array `tuner`
     * yields the tolerant empty default. Rejects with the server `error` on non-2xx.
     *
     * @return PromiseInterface<Tuner>
     */
    public function setTunerEnabled(string $id, bool $enabled): PromiseInterface
    {
        return $this->api->send('PUT', self::LIVETV . '/tuners/' . rawurlencode($id), [], ['enabled' => $enabled])
            ->then(static function (array $body): Tuner {
                $tuner = $body['tuner'] ?? null;

                return Tuner::fromArray(is_array($tuner) ? $tuner : []);
            })->otherwise(self::reThrowFriendly(...));
    }

    /**
     * Delete a tuner. The server returns `{success:true}` (or an empty body), so
     * this resolves null; rejects with the server `error` on non-2xx.
     *
     * @return PromiseInterface<null>
     */
    public function deleteTuner(string $id): PromiseInterface
    {
        return $this->api->send('DELETE', self::LIVETV . '/tuners/' . rawurlencode($id))
            ->then(static fn (array $body): ?Tuner => null)
            ->otherwise(self::reThrowFriendly(...));
    }

    /**
     * List the Live-TV channels. Reads the top-level `channels` named key; a
     * `{data:{channels}}` wrapper yields an empty list.
     *
     * @return PromiseInterface<list<Channel>>
     */
    public function liveTvChannels(): PromiseInterface
    {
        return $this->api->send('GET', self::LIVETV . '/channels')->then(static function (array $body): array {
            return self::mapList(
                $body['channels'] ?? null,
                static fn (array $row): Channel => Channel::fromArray($row),
            );
        })->otherwise(self::reThrowFriendly(...));
    }

    /**
     * Enable/disable a channel (PUT `{enabled}`; the server maps it onto
     * `visibility`). Resolves the single updated {@see Channel} from the top-level
     * `channel` named key. Rejects with the server `error` on non-2xx.
     *
     * @return PromiseInterface<Channel>
     */
    public function setChannelEnabled(string $id, bool $enabled): PromiseInterface
    {
        return $this->api->send('PUT', self::LIVETV . '/channels/' . rawurlencode($id), [], ['enabled' => $enabled])
            ->then(static function (array $body): Channel {
                $channel = $body['channel'] ?? null;

                return Channel::fromArray(is_array($channel) ? $channel : []);
            })->otherwise(self::reThrowFriendly(...));
    }

    /**
     * List the guide programs, optionally scoped to a single channel (GET `/guide`
     * with an optional `channel_id`). Reads the top-level `programs` named key; a
     * `{data:{programs}}` wrapper yields an empty list.
     *
     * @return PromiseInterface<list<GuideProgram>>
     */
    public function liveTvGuide(?string $channelId = null): PromiseInterface
    {
        $query = $channelId === null ? [] : ['channel_id' => $channelId];

        return $this->api->send('GET', self::LIVETV . '/guide', $query)->then(static function (array $body): array {
            return self::mapList(
                $body['programs'] ?? null,
                static fn (array $row): GuideProgram => GuideProgram::fromArray($row),
            );
        })->otherwise(self::reThrowFriendly(...));
    }

    /**
     * Refresh the EPG (POST `/guide/refresh`). UNLIKE the guide LIST, the refresh
     * response's `programs` key is the INT count of programs imported, so this
     * resolves that count. Rejects with the server `error` on non-2xx.
     *
     * @return PromiseInterface<int>
     */
    public function refreshGuide(): PromiseInterface
    {
        return $this->api->send('POST', self::LIVETV . '/guide/refresh')
            ->then(static fn (array $body): int => Coerce::int($body['programs'] ?? 0))
            ->otherwise(self::reThrowFriendly(...));
    }

    /**
     * List recordings, optionally filtered by status (GET `/recordings` with an
     * optional `status`). Reads the top-level `recordings` named key; a
     * `{data:{recordings}}` wrapper yields an empty list.
     *
     * @return PromiseInterface<list<Recording>>
     */
    public function recordings(?string $status = null): PromiseInterface
    {
        $query = $status === null ? [] : ['status' => $status];

        return $this->api->send('GET', self::LIVETV . '/recordings', $query)->then(static function (array $body): array {
            return self::mapList(
                $body['recordings'] ?? null,
                static fn (array $row): Recording => Recording::fromArray($row),
            );
        })->otherwise(self::reThrowFriendly(...));
    }

    /**
     * List the next upcoming recordings (GET `/recordings/upcoming?limit`). Reads
     * the top-level `recordings` named key.
     *
     * @return PromiseInterface<list<Recording>>
     */
    public function upcomingRecordings(int $limit = 10): PromiseInterface
    {
        return $this->api->send('GET', self::LIVETV . '/recordings/upcoming', ['limit' => $limit])
            ->then(static function (array $body): array {
                return self::mapList(
                    $body['recordings'] ?? null,
                    static fn (array $row): Recording => Recording::fromArray($row),
                );
            })->otherwise(self::reThrowFriendly(...));
    }

    /**
     * Delete a recording. The server returns `{success:true}` (or an empty body),
     * so this resolves null; rejects with the server `error` on non-2xx.
     *
     * @return PromiseInterface<null>
     */
    public function deleteRecording(string $id): PromiseInterface
    {
        return $this->api->send('DELETE', self::LIVETV . '/recordings/' . rawurlencode($id))
            ->then(static fn (array $body): ?Recording => null)
            ->otherwise(self::reThrowFriendly(...));
    }

    /**
     * List the series-recording rules. Reads the top-level `rules` named key; a
     * `{data:{rules}}` wrapper yields an empty list.
     *
     * @return PromiseInterface<list<SeriesRule>>
     */
    public function seriesRules(): PromiseInterface
    {
        return $this->api->send('GET', self::LIVETV . '/series-rules')->then(static function (array $body): array {
            return self::mapList(
                $body['rules'] ?? null,
                static fn (array $row): SeriesRule => SeriesRule::fromArray($row),
            );
        })->otherwise(self::reThrowFriendly(...));
    }

    /**
     * Delete a series rule. The server returns `{success:true}` (or an empty
     * body), so this resolves null; rejects with the server `error` on non-2xx.
     *
     * @return PromiseInterface<null>
     */
    public function deleteSeriesRule(string $id): PromiseInterface
    {
        return $this->api->send('DELETE', self::LIVETV . '/series-rules/' . rawurlencode($id))
            ->then(static fn (array $body): ?SeriesRule => null)
            ->otherwise(self::reThrowFriendly(...));
    }

    /**
     * Schedule a one-off recording FROM a selected guide program — the caller
     * passes the program's `channel_id`, `start_time`/`end_time` (epoch ints),
     * `title`, and `program_id` directly, so no manual time entry is ever needed.
     * The POST body is `{channel_id, start_time, end_time, title?, program_id?,
     * priority?}` (a blank title / null program_id / null priority is omitted).
     * Resolves a confirmation string; rejects with the server's friendly `message`
     * on a 400 (missing channel/start/end) or 500 (see {@see reThrowFriendly()}).
     *
     * @return PromiseInterface<string>
     */
    public function createRecording(string $channelId, int $startTime, int $endTime, string $title, ?string $programId, ?int $priority): PromiseInterface
    {
        $body = [
            'channel_id' => $channelId,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ];
        if ($title !== '') {
            $body['title'] = $title;
        }
        if ($programId !== null && $programId !== '') {
            $body['program_id'] = $programId;
        }
        if ($priority !== null) {
            $body['priority'] = $priority;
        }

        return $this->api->send('POST', self::LIVETV . '/recordings', [], $body)->then(
            static fn (array $resp): string => 'Recording scheduled',
            self::reThrowFriendly(...),
        );
    }

    /**
     * Create a series-recording rule FROM a selected guide program — the caller
     * passes the program's `series_id` and `channel_id`. The POST body is
     * `{series_id, channel_id, title?, priority?, pre_padding_seconds?,
     * post_padding_seconds?, max_recordings?, days_ahead?}`; every null optional is
     * OMITTED so the server applies its defaults. Resolves a confirmation string;
     * rejects with the server's friendly `message` on a 400 (missing
     * series/channel) or 500 (see {@see reThrowFriendly()}).
     *
     * @return PromiseInterface<string>
     */
    public function createSeriesRule(string $seriesId, string $channelId, string $title, ?int $priority, ?int $prePad, ?int $postPad, ?int $maxRecordings, ?int $daysAhead): PromiseInterface
    {
        $body = [
            'series_id' => $seriesId,
            'channel_id' => $channelId,
        ];
        if ($title !== '') {
            $body['title'] = $title;
        }
        $body += self::ruleOptionals($priority, $prePad, $postPad, $maxRecordings, $daysAhead);

        return $this->api->send('POST', self::LIVETV . '/series-rules', [], $body)->then(
            static fn (array $resp): string => 'Series rule created',
            self::reThrowFriendly(...),
        );
    }

    /**
     * Update a series-recording rule (PUT `/series-rules/{id}`). Every field is
     * optional: the body carries ONLY the provided (non-null, and for the title
     * non-blank) fields, so an unchanged value is left as-is server-side. Resolves a
     * confirmation string; rejects with the server's friendly `message` on a 400 /
     * 404 (see {@see reThrowFriendly()}).
     *
     * @return PromiseInterface<string>
     */
    public function updateSeriesRule(string $id, ?string $title, ?int $priority, ?int $prePad, ?int $postPad, ?int $maxRecordings, ?int $daysAhead): PromiseInterface
    {
        $body = [];
        if ($title !== null && $title !== '') {
            $body['title'] = $title;
        }
        $body += self::ruleOptionals($priority, $prePad, $postPad, $maxRecordings, $daysAhead);

        return $this->api->send('PUT', self::LIVETV . '/series-rules/' . rawurlencode($id), [], $body === [] ? null : $body)->then(
            static fn (array $resp): string => 'Series rule updated',
            self::reThrowFriendly(...),
        );
    }

    /**
     * Build the optional numeric fields shared by the series-rule create/update
     * bodies, omitting every null so the server keeps its default / current value.
     *
     * @return array<string, int>
     */
    private static function ruleOptionals(?int $priority, ?int $prePad, ?int $postPad, ?int $maxRecordings, ?int $daysAhead): array
    {
        $body = [];
        if ($priority !== null) {
            $body['priority'] = $priority;
        }
        if ($prePad !== null) {
            $body['pre_padding_seconds'] = $prePad;
        }
        if ($postPad !== null) {
            $body['post_padding_seconds'] = $postPad;
        }
        if ($maxRecordings !== null) {
            $body['max_recordings'] = $maxRecordings;
        }
        if ($daysAhead !== null) {
            $body['days_ahead'] = $daysAhead;
        }

        return $body;
    }

    /**
     * Rename a tuner (PUT `/tuners/{id}` `{name}`). Reuses the existing
     * enabled-toggle endpoint with a `name` field. Resolves the single updated
     * {@see Tuner} from the top-level `tuner` named key; a missing/non-array `tuner`
     * yields the tolerant empty default. Rejects with the server's friendly
     * `message` on non-2xx (see {@see reThrowFriendly()}).
     *
     * @return PromiseInterface<Tuner>
     */
    public function renameTuner(string $id, string $name): PromiseInterface
    {
        return $this->api->send('PUT', self::LIVETV . '/tuners/' . rawurlencode($id), [], ['name' => $name])
            ->then(static function (array $body): Tuner {
                $tuner = $body['tuner'] ?? null;

                return Tuner::fromArray(is_array($tuner) ? $tuner : []);
            })->otherwise(self::reThrowFriendly(...));
    }

    /**
     * Rename a channel (PUT `/channels/{id}` `{name}`). Reuses the existing
     * enabled-toggle endpoint with a `name` field. Resolves the single updated
     * {@see Channel} from the top-level `channel` named key. Rejects with the
     * server's friendly `message` on non-2xx (see {@see reThrowFriendly()}).
     *
     * @return PromiseInterface<Channel>
     */
    public function renameChannel(string $id, string $name): PromiseInterface
    {
        return $this->api->send('PUT', self::LIVETV . '/channels/' . rawurlencode($id), [], ['name' => $name])
            ->then(static function (array $body): Channel {
                $channel = $body['channel'] ?? null;

                return Channel::fromArray(is_array($channel) ? $channel : []);
            })->otherwise(self::reThrowFriendly(...));
    }

    /**
     * Re-throw a rejected {@see ApiError} carrying the server's FRIENDLY text. The
     * admin controllers that wrap failures in an `error()` helper emit
     * `{success:false, message:…}` with NO `error` key (DLNA, Remote Access,
     * Live TV), but {@see ApiClient::decode()} only reads `error`, so without this
     * the failure degrades to the generic "Request failed (HTTP …)". This prefers
     * `body['message']`, then `body['error']`, re-throwing a new {@see ApiError}
     * whose message is the friendly text. An {@see AuthError} (401) is let through
     * UNTOUCHED so the screen can route to a session expiry rather than a toast.
     */
    private static function reThrowFriendly(\Throwable $e): never
    {
        if ($e instanceof ApiError && !$e instanceof AuthError) {
            $message = $e->body['message'] ?? $e->body['error'] ?? null;
            if (is_string($message) && $message !== '') {
                throw new ApiError($message, $e->statusCode, $e->body, $e);
            }
        }

        throw $e;
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
