<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * One viewer profile of a server user, mirroring an item of
 * `GET /api/v1/admin/users/{userId}/profiles` → `{profiles: [profile]}`. Each
 * hydrated row carries `id, user_id, name, avatar_url, is_active, is_admin,
 * content_rating, pin_required_for_admin, max_daily_watch_time, allow_unrated`
 * (the `pin_hash` is NOT exposed, so a row can't reveal whether a PIN is set).
 *
 * The client carries the fields the management UI renders/edits: `id, name,
 * contentRating, isActive, maxDailyWatchTime, pinRequiredForAdmin`.
 *
 * The content-rating ENUM maps onto the server's `RATING_MAP`
 * (`0=>G, 1=>PG, 2=>PG-13, 3=>R, 4=>NC-17, 5=>X, 6=>UNRATED`); {@see ratingIndex()}
 * inverts a stored `content_rating` string back to its 0-6 index (default / unknown
 * → 3, `R`) to pre-select the edit picker. Immutable.
 */
final readonly class Profile
{
    /**
     * The content-rating ENUM order, index → label. Mirrors the server's
     * `UserProfileManager::RATING_MAP` EXACTLY; the index is what the create/edit
     * picker submits as `rating`.
     *
     * @var list<string>
     */
    public const RATINGS = ['G', 'PG', 'PG-13', 'R', 'NC-17', 'X', 'UNRATED'];

    /** The default rating index when none/unknown is set (`R`). */
    public const DEFAULT_RATING_INDEX = 3;

    public function __construct(
        public string $id,
        public string $name,
        public string $contentRating,
        public bool $isActive,
        public int $maxDailyWatchTime,
        public bool $pinRequiredForAdmin,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Coerce::str($data['id'] ?? ''),
            name: Coerce::str($data['name'] ?? ''),
            contentRating: Coerce::str($data['content_rating'] ?? 'R', 'R'),
            isActive: Coerce::bool($data['is_active'] ?? false),
            maxDailyWatchTime: Coerce::int($data['max_daily_watch_time'] ?? 0),
            pinRequiredForAdmin: Coerce::bool($data['pin_required_for_admin'] ?? false),
        );
    }

    /**
     * The 0-6 index of this profile's content rating (the inverse of the server's
     * RATING_MAP), used to pre-select the edit picker. An unknown/blank rating
     * falls back to the default (`R`, index 3).
     */
    public function ratingIndex(): int
    {
        $index = array_search($this->contentRating, self::RATINGS, true);

        return $index === false ? self::DEFAULT_RATING_INDEX : $index;
    }
}
