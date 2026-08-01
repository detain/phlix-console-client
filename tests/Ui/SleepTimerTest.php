<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Msg\SleepTimerFireMsg;
use Phlix\Console\Msg\SleepTimerTickMsg;
use Phlix\Console\Ui\SleepTimer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SleepTimerTest extends TestCase
{
    public function testNewTimerIsInactive(): void
    {
        $timer = new SleepTimer();

        self::assertFalse($timer->isActive());
        self::assertNull($timer->remainingSeconds());
        self::assertSame(-1, $timer->selectedPresetIndex());
        self::assertSame('', $timer->formatRemaining());
    }

    #[DataProvider('presetProvider')]
    public function testStartFromPresetActivatesAndSetsDuration(int $presetIndex, int $expectedMinutes): void
    {
        $timer = new SleepTimer();
        $result = $timer->startFromPreset($presetIndex);

        self::assertSame($timer, $result['timer']);
        self::assertNotNull($result['cmd']);
        self::assertTrue($timer->isActive());
        self::assertSame($expectedMinutes * 60, $timer->remainingSeconds());
        self::assertSame($presetIndex, $timer->selectedPresetIndex());
    }

    public function testStartFromPresetReturnsNullCmdForInvalidIndex(): void
    {
        $timer = new SleepTimer();

        $result = $timer->startFromPreset(-1);
        self::assertSame($timer, $result['timer']);
        self::assertNull($result['cmd']);

        $result = $timer->startFromPreset(99);
        self::assertSame($timer, $result['timer']);
        self::assertNull($result['cmd']);
    }

    public function testCancelResetsTimer(): void
    {
        $timer = new SleepTimer();
        $timer->startFromPreset(1); // 30 minutes

        $result = $timer->cancel();

        self::assertSame($timer, $result['timer']);
        self::assertNull($result['cmd']);
        self::assertFalse($timer->isActive());
        self::assertNull($timer->remainingSeconds());
        self::assertSame(-1, $timer->selectedPresetIndex());
    }

    public function testTickDecrementsRemainingSeconds(): void
    {
        $timer = new SleepTimer();
        $timer->startFromPreset(0); // 15 minutes = 900 seconds

        $initial = $timer->remainingSeconds();
        $result = $timer->tick();

        self::assertSame($timer, $result['timer']);
        self::assertNotNull($result['cmd']);
        self::assertSame($initial - 1, $timer->remainingSeconds());
    }

    public function testTickFiresWhenExpired(): void
    {
        $timer = new SleepTimer();
        $timer->startFromPreset(0); // 15 minutes
        // Tick until 1 second remaining
        for ($i = 0; $i < (15 * 60) - 1; $i++) {
            $timer->tick();
        }

        $result = $timer->tick();
        // At this point remainingSeconds is 0, tick fires the message
        self::assertSame($timer, $result['timer']);
        self::assertNotNull($result['cmd']);

        // Call the cmd to get the FireMsg
        $msg = $result['cmd']();
        self::assertInstanceOf(SleepTimerFireMsg::class, $msg);

        // The timer is now inactive
        self::assertNull($timer->remainingSeconds());
        self::assertFalse($timer->isActive());
    }

    public function testTickOnInactiveTimerReturnsNullCmd(): void
    {
        $timer = new SleepTimer();

        $result = $timer->tick();

        self::assertSame($timer, $result['timer']);
        self::assertNull($result['cmd']);
    }

    public function testDispatchTickReturnsCorrectMessage(): void
    {
        $timer = new SleepTimer();
        $timer->startFromPreset(0); // 15 minutes

        $msg = $timer->dispatchTick();

        self::assertInstanceOf(SleepTimerTickMsg::class, $msg);
        self::assertSame(15 * 60, $msg->remainingSeconds);
    }

    public function testDispatchTickWithZeroRemaining(): void
    {
        $timer = new SleepTimer();

        $msg = $timer->dispatchTick();

        self::assertInstanceOf(SleepTimerTickMsg::class, $msg);
        self::assertSame(0, $msg->remainingSeconds);
    }

    #[DataProvider('formatProvider')]
    public function testFormatRemaining(int $seconds, string $expected): void
    {
        $timer = new SleepTimer();
        // Use reflection to set remainingSeconds directly
        $reflection = new \ReflectionClass($timer);
        $prop = $reflection->getProperty('remainingSeconds');
        $prop->setValue($timer, $seconds);

        self::assertSame($expected, $timer->formatRemaining());
    }

    public function testFormatRemainingWhenNull(): void
    {
        $timer = new SleepTimer();
        self::assertSame('', $timer->formatRemaining());
    }

    public function testPresetsConstantContainsExpectedValues(): void
    {
        self::assertSame([15, 30, 45, 60, 90, 120], SleepTimer::PRESETS);
    }

    /** @return array<string, array{int, int}> */
    public static function presetProvider(): array
    {
        return [
            '15 min' => [0, 15],
            '30 min' => [1, 30],
            '45 min' => [2, 45],
            '60 min' => [3, 60],
            '90 min' => [4, 90],
            '120 min' => [5, 120],
        ];
    }

    /** @return array<string, array{int, string}> */
    public static function formatProvider(): array
    {
        return [
            'zero' => [0, '0:00'],
            'one second' => [1, '0:01'],
            '59 seconds' => [59, '0:59'],
            'one minute' => [60, '1:00'],
            'one minute 30 seconds' => [90, '1:30'],
            '59 minutes 59 seconds' => [3599, '59:59'],
            'one hour' => [3600, '1:00:00'],
            'one hour 30 minutes 45 seconds' => [5445, '1:30:45'],
            'max value safety' => [100000, '27:46:40'],
        ];
    }
}
