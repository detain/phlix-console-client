<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto\Admin\Parental;

use Phlix\Console\Api\Dto\Coerce;

/**
 * Days of the week used in access schedules.
 *
 * @var list<string>
 */
final readonly class AccessSchedule
{
    public const VALID_DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * @param list<string> $daysOfWeek
     */
    public function __construct(
        public int $id,
        public int $profileId,
        public string $name,
        public string $startTime,
        public string $endTime,
        public array $daysOfWeek,
        public bool $isActive,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $daysRaw = $data['days_of_week'] ?? [];
        $days = [];
        foreach (is_array($daysRaw) ? $daysRaw : [] as $day) {
            if (is_string($day)) {
                $days[] = $day;
            }
        }

        return new self(
            id: Coerce::int($data['id'] ?? 0),
            profileId: Coerce::int($data['profile_id'] ?? 0),
            name: Coerce::str($data['name'] ?? ''),
            startTime: Coerce::str($data['start_time'] ?? '00:00:00'),
            endTime: Coerce::str($data['end_time'] ?? '23:59:59'),
            daysOfWeek: $days,
            isActive: Coerce::bool($data['is_active'] ?? true),
        );
    }

    /**
     * @return array{id: int, profile_id: int, name: string, start_time: string, end_time: string, days_of_week: list<string>, is_active: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'profile_id' => $this->profileId,
            'name' => $this->name,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'days_of_week' => $this->daysOfWeek,
            'is_active' => $this->isActive,
        ];
    }
}
