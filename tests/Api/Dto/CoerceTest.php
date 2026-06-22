<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\Dto\Coerce;
use PHPUnit\Framework\TestCase;

final class CoerceTest extends TestCase
{
    public function testStrCoercesScalarsAndFallsBack(): void
    {
        self::assertSame('hi', Coerce::str('hi'));
        self::assertSame('7', Coerce::str(7));
        self::assertSame('1', Coerce::str(true));
        self::assertSame('x', Coerce::str(['a'], 'x'));
        self::assertSame('', Coerce::str(null));
    }

    public function testNstrTreatsEmptyAndNonScalarAsNull(): void
    {
        self::assertSame('v', Coerce::nstr('v'));
        self::assertSame('0', Coerce::nstr('0'));
        self::assertNull(Coerce::nstr(''));
        self::assertNull(Coerce::nstr(null));
        self::assertNull(Coerce::nstr(['a']));
    }

    public function testNintParsesNumericStringsAndIntsOnly(): void
    {
        self::assertSame(2020, Coerce::nint(2020));
        self::assertSame(2020, Coerce::nint('2020'));
        self::assertSame(0, Coerce::nint(0));
        self::assertNull(Coerce::nint(null));
        self::assertNull(Coerce::nint(''));
        self::assertNull(Coerce::nint('not a number'));
    }

    public function testIntAppliesDefault(): void
    {
        self::assertSame(5, Coerce::int('5'));
        self::assertSame(0, Coerce::int(null));
        self::assertSame(9, Coerce::int('nope', 9));
    }

    public function testBoolHandlesAssortedEncodings(): void
    {
        self::assertTrue(Coerce::bool(true));
        self::assertTrue(Coerce::bool(1));
        self::assertTrue(Coerce::bool('1'));
        self::assertTrue(Coerce::bool('true'));
        self::assertFalse(Coerce::bool(false));
        self::assertFalse(Coerce::bool(0));
        self::assertFalse(Coerce::bool('0'));
        self::assertFalse(Coerce::bool(''));
    }

    public function testStringListFiltersEmptyAndNonScalar(): void
    {
        self::assertSame(['Action', 'Drama'], Coerce::stringList(['Action', 'Drama']));
        self::assertSame(['1', '2'], Coerce::stringList([1, 2]));
        self::assertSame(['ok'], Coerce::stringList(['ok', '', null, ['x']]));
        self::assertSame([], Coerce::stringList('not an array'));
    }

    public function testActorNamesNormalisesStringsAndObjects(): void
    {
        self::assertSame(['Keanu Reeves'], Coerce::actorNames(['Keanu Reeves']));
        self::assertSame(
            ['Keanu Reeves', 'Carrie-Anne Moss'],
            Coerce::actorNames([
                ['name' => 'Keanu Reeves', 'role' => 'Neo'],
                ['name' => 'Carrie-Anne Moss'],
            ]),
        );
        self::assertSame(
            ['Plain Name', 'Object Name'],
            Coerce::actorNames(['Plain Name', ['name' => 'Object Name'], ['noname' => 'x']]),
        );
        self::assertSame([], Coerce::actorNames(null));
    }

    public function testMapReturnsArrayOrEmpty(): void
    {
        self::assertSame(['a' => 1], Coerce::map(['a' => 1]));
        self::assertSame([], Coerce::map(null));
        self::assertSame([], Coerce::map('str'));
    }
}
