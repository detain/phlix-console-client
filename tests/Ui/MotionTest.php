<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Ui\Motion;
use Phlix\Console\Ui\Skeleton;
use PHPUnit\Framework\TestCase;

final class MotionTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('NO_MOTION');
        putenv('PHLIX_NO_ANIMATION');
        unset($_SERVER['NO_MOTION'], $_SERVER['PHLIX_NO_ANIMATION']);
    }

    public function testEnabledByDefault(): void
    {
        $this->assertTrue(Motion::enabled());
    }

    public function testOptOutVariablesDisable(): void
    {
        putenv('NO_MOTION=1');
        $this->assertFalse(Motion::enabled());
        putenv('NO_MOTION');
        putenv('PHLIX_NO_ANIMATION=1');
        $this->assertFalse(Motion::enabled());
        putenv('PHLIX_NO_ANIMATION=0');
        $this->assertTrue(Motion::enabled());
    }

    public function testSkeletonIsStaticWhenOptedOut(): void
    {
        putenv('NO_MOTION=1');
        $this->assertSame(Skeleton::bars(20, 2, 0), Skeleton::bars(20, 2, 7));
        $this->assertSame(Skeleton::line(20, 0), Skeleton::line(20, 9));
    }
}
