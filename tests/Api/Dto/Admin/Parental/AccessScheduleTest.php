<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin\Parental;

use Phlix\Console\Api\Dto\Admin\Parental\AccessSchedule;
use PHPUnit\Framework\TestCase;

final class AccessScheduleTest extends TestCase
{
    public function testFromArrayMapsAllFields(): void
    {
        $schedule = AccessSchedule::fromArray([
            'id' => 1,
            'profile_id' => 5,
            'name' => 'Weekend Nights',
            'start_time' => '18:00:00',
            'end_time' => '22:00:00',
            'days_of_week' => ['sat', 'sun'],
            'is_active' => true,
        ]);

        self::assertSame(1, $schedule->id);
        self::assertSame(5, $schedule->profileId);
        self::assertSame('Weekend Nights', $schedule->name);
        self::assertSame('18:00:00', $schedule->startTime);
        self::assertSame('22:00:00', $schedule->endTime);
        self::assertSame(['sat', 'sun'], $schedule->daysOfWeek);
        self::assertTrue($schedule->isActive);
    }

    public function testFromArrayDefaults(): void
    {
        $schedule = AccessSchedule::fromArray([]);

        self::assertSame(0, $schedule->id);
        self::assertSame(0, $schedule->profileId);
        self::assertSame('', $schedule->name);
        self::assertSame('00:00:00', $schedule->startTime);
        self::assertSame('23:59:59', $schedule->endTime);
        self::assertSame([], $schedule->daysOfWeek);
        self::assertTrue($schedule->isActive);
    }

    public function testFromArrayFiltersNonStringDays(): void
    {
        $schedule = AccessSchedule::fromArray([
            'days_of_week' => ['mon', null, false, 'tue', 123, 'wed'],
        ]);

        self::assertSame(['mon', 'tue', 'wed'], $schedule->daysOfWeek);
    }

    public function testFromArrayHandlesNonArrayDays(): void
    {
        $schedule = AccessSchedule::fromArray([
            'days_of_week' => 'not-an-array',
        ]);

        self::assertSame([], $schedule->daysOfWeek);
    }

    public function testToArrayReturnsAllFields(): void
    {
        $schedule = new AccessSchedule(
            id: 2,
            profileId: 10,
            name: 'After School',
            startTime: '15:00:00',
            endTime: '20:00:00',
            daysOfWeek: ['mon', 'tue', 'wed', 'thu', 'fri'],
            isActive: false,
        );

        $arr = $schedule->toArray();

        self::assertSame(2, $arr['id']);
        self::assertSame(10, $arr['profile_id']);
        self::assertSame('After School', $arr['name']);
        self::assertSame('15:00:00', $arr['start_time']);
        self::assertSame('20:00:00', $arr['end_time']);
        self::assertSame(['mon', 'tue', 'wed', 'thu', 'fri'], $arr['days_of_week']);
        self::assertFalse($arr['is_active']);
    }

    public function testValidDaysConstant(): void
    {
        self::assertSame(
            ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
            AccessSchedule::VALID_DAYS
        );
    }
}
