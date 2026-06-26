<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Cast;

use Phlix\Console\Api\Dto\Cast\CastStatus;
use PHPUnit\Framework\TestCase;

final class CastStatusTest extends TestCase
{
    public function testReadsActiveAndStateShape(): void
    {
        $status = CastStatus::fromArray(['active' => true, 'state' => 'PLAYING']);

        self::assertTrue($status->active);
        self::assertSame('PLAYING', $status->state);
    }

    public function testReadsDlnaHasActiveSessionAndSessionStateShape(): void
    {
        $status = CastStatus::fromArray(['has_active_session' => true, 'session_state' => 'TRANSITIONING']);

        self::assertTrue($status->active);
        self::assertSame('TRANSITIONING', $status->state);
    }

    public function testActivePrefersActiveOverHasActiveSession(): void
    {
        $status = CastStatus::fromArray(['active' => false, 'has_active_session' => true]);

        self::assertFalse($status->active, 'active takes precedence over has_active_session');
    }

    public function testStatePrefersStateOverSessionState(): void
    {
        $status = CastStatus::fromArray(['state' => 'PAUSED', 'session_state' => 'PLAYING']);

        self::assertSame('PAUSED', $status->state);
    }

    public function testCoercesTinyintActive(): void
    {
        $status = CastStatus::fromArray(['has_active_session' => 1]);

        self::assertTrue($status->active);
    }

    public function testEmptyDefaults(): void
    {
        $status = CastStatus::fromArray([]);

        self::assertFalse($status->active);
        self::assertNull($status->state);
    }
}
