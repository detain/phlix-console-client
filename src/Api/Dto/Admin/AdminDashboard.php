<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto\Admin;

/**
 * The aggregate admin dashboard model: the five panels the
 * {@see \Phlix\Console\Api\Admin\AdminClient::dashboard()} fan-out assembles from
 * the server's `/api/v1/admin/dashboard/*` endpoints. Immutable.
 */
final readonly class AdminDashboard
{
    /**
     * @param list<NowPlayingSession> $nowPlaying
     * @param list<TopUser>           $topUsers
     * @param list<TopMediaItem>      $topMedia
     * @param list<ActivityEntry>     $activity
     */
    public function __construct(
        public array $nowPlaying,
        public array $topUsers,
        public array $topMedia,
        public StorageSummary $storage,
        public array $activity,
    ) {
    }

    /**
     * Assemble the aggregate from each endpoint's already-extracted `data`
     * payload. Each list payload is mapped through the matching item DTO; the
     * storage object is a single map. Tolerant — a non-array list payload yields
     * an empty list, an absent storage map yields zeroed totals.
     *
     * @param array<array-key,mixed> $nowPlaying the now-playing `data` (a list)
     * @param array<array-key,mixed> $topUsers   the top-users `data` (a list)
     * @param array<array-key,mixed> $topMedia   the top-media `data` (a list)
     * @param array<array-key,mixed> $storage    the storage `data` (a map)
     * @param array<array-key,mixed> $activity   the activity `data` (a list)
     */
    public static function fromParts(
        array $nowPlaying,
        array $topUsers,
        array $topMedia,
        array $storage,
        array $activity,
    ): self {
        return new self(
            self::mapList($nowPlaying, static fn (array $row): NowPlayingSession => NowPlayingSession::fromArray($row)),
            self::mapList($topUsers, static fn (array $row): TopUser => TopUser::fromArray($row)),
            self::mapList($topMedia, static fn (array $row): TopMediaItem => TopMediaItem::fromArray($row)),
            StorageSummary::fromArray($storage),
            self::mapList($activity, static fn (array $row): ActivityEntry => ActivityEntry::fromArray($row)),
        );
    }

    /**
     * Map every array row of a loosely-typed list payload through $factory,
     * skipping any non-array entry. Returns a re-indexed `list<T>`.
     *
     * @template T
     * @param array<array-key,mixed>     $rows
     * @param \Closure(array<array-key,mixed>): T $factory
     * @return list<T>
     */
    private static function mapList(array $rows, \Closure $factory): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $factory($row);
            }
        }

        return $out;
    }
}
