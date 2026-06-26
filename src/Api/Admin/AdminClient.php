<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Admin;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Admin\AdminDashboard;
use Phlix\Console\Api\Dto\Admin\LogFile;
use Phlix\Console\Api\Dto\Admin\LogTail;
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
     * server). The envelope's `data.files` list is mapped through {@see LogFile};
     * a non-list payload yields an empty list.
     *
     * @return PromiseInterface<list<LogFile>>
     */
    public function logFiles(): PromiseInterface
    {
        return $this->api->send('GET', self::LOGS)->then(static function (array $body): array {
            $data = Coerce::map($body['data'] ?? null);

            return self::mapList(
                $data['files'] ?? null,
                static fn (array $row): LogFile => LogFile::fromArray($row),
            );
        });
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
            ->then(static fn (array $body): LogTail => LogTail::fromArray(Coerce::map($body['data'] ?? null)));
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
            ->then(static fn (array $body): LogTail => LogTail::fromArray(Coerce::map($body['data'] ?? null)));
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
