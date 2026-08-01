<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\Dto\CastMember;
use Phlix\Console\Api\Dto\Coerce;
use Phlix\Console\Api\Dto\CrewMember;
use PHPUnit\Framework\TestCase;

final class CoerceTest extends TestCase
{
    // ---- str ----

    public function testStrCoercesScalarToString(): void
    {
        self::assertSame('123', Coerce::str(123));
        self::assertSame('hello', Coerce::str('hello'));
        self::assertSame('1.5', Coerce::str(1.5));
    }

    public function testStrReturnsDefaultForNonScalar(): void
    {
        self::assertSame('default', Coerce::str(null, 'default'));
        self::assertSame('default', Coerce::str([], 'default'));
        self::assertSame('fallback', Coerce::str(new \stdClass(), 'fallback'));
    }

    // ---- nstr ----

    public function testNstrReturnsNullForNull(): void
    {
        self::assertNull(Coerce::nstr(null));
    }

    public function testNstrReturnsNullForEmptyString(): void
    {
        self::assertNull(Coerce::nstr(''));
    }

    public function testNstrReturnsNullForNonScalar(): void
    {
        self::assertNull(Coerce::nstr([]));
        self::assertNull(Coerce::nstr(new \stdClass()));
    }

    public function testNstrCoercesScalarToString(): void
    {
        self::assertSame('123', Coerce::nstr(123));
        self::assertSame('hello', Coerce::nstr('hello'));
    }

    public function testNstrCoercesZeroToString(): void
    {
        // 0 is scalar, (string)0 = '0' which is not empty, so it returns '0'
        self::assertSame('0', Coerce::nstr(0));
    }

    // ---- nint ----

    public function testNintCoercesNumericString(): void
    {
        self::assertSame(123, Coerce::nint('123'));
        self::assertSame(0, Coerce::nint('0'));
        self::assertSame(-5, Coerce::nint('-5'));
    }

    public function testNintCoercesInt(): void
    {
        self::assertSame(42, Coerce::nint(42));
        self::assertSame(0, Coerce::nint(0));
    }

    public function testNintCoercesFloat(): void
    {
        self::assertSame(1, Coerce::nint(1.9));
    }

    public function testNintReturnsNullForNull(): void
    {
        self::assertNull(Coerce::nint(null));
    }

    public function testNintReturnsNullForEmptyString(): void
    {
        self::assertNull(Coerce::nint(''));
    }

    public function testNintReturnsNullForNonNumeric(): void
    {
        self::assertNull(Coerce::nint('abc'));
        self::assertNull(Coerce::nint([]));
        self::assertNull(Coerce::nint(false));
    }

    // ---- int ----

    public function testIntUsesDefaultWhenNull(): void
    {
        self::assertSame(99, Coerce::int(null, 99));
    }

    public function testIntUsesDefaultWhenEmpty(): void
    {
        self::assertSame(5, Coerce::int('', 5));
    }

    // ---- nfloat ----

    public function testNfloatCoercesNumericString(): void
    {
        self::assertSame(1.5, Coerce::nfloat('1.5'));
    }

    public function testNfloatReturnsNullForNull(): void
    {
        self::assertNull(Coerce::nfloat(null));
    }

    public function testNfloatReturnsNullForEmptyString(): void
    {
        self::assertNull(Coerce::nfloat(''));
    }

    public function testNfloatReturnsNullForNonNumeric(): void
    {
        self::assertNull(Coerce::nfloat('abc'));
    }

    // ---- float ----

    public function testFloatUsesDefaultWhenNull(): void
    {
        self::assertSame(9.9, Coerce::float(null, 9.9));
    }

    // ---- bool ----

    public function testBoolReturnsBoolAsIs(): void
    {
        self::assertTrue(Coerce::bool(true));
        self::assertFalse(Coerce::bool(false));
    }

    public function testBoolCoercesOneAndZero(): void
    {
        self::assertTrue(Coerce::bool(1));
        self::assertFalse(Coerce::bool(0));
        self::assertTrue(Coerce::bool('1'));
        self::assertFalse(Coerce::bool('0'));
    }

    public function testBoolCoercesStringTrue(): void
    {
        self::assertTrue(Coerce::bool('true'));
        self::assertTrue(Coerce::bool('yes'));
    }

    public function testBoolReturnsFalseForOtherStrings(): void
    {
        self::assertFalse(Coerce::bool('false'));
        self::assertFalse(Coerce::bool('no'));
        self::assertFalse(Coerce::bool('maybe'));
    }

    // ---- stringList ----

    public function testStringListFiltersNonScalars(): void
    {
        $result = Coerce::stringList(['a', 'b', null, 'c', [], 'd']);

        self::assertSame(['a', 'b', 'c', 'd'], $result);
    }

    public function testStringListReturnsEmptyArrayForNonArray(): void
    {
        self::assertSame([], Coerce::stringList(null));
        self::assertSame([], Coerce::stringList('abc'));
    }

    public function testStringListWithAllValidStrings(): void
    {
        $result = Coerce::stringList(['foo', 'bar', 'baz']);

        self::assertSame(['foo', 'bar', 'baz'], $result);
    }

    // ---- actorNames ----

    public function testActorNamesWithPlainStrings(): void
    {
        $result = Coerce::actorNames(['Alice', 'Bob', 'Charlie']);

        self::assertSame(['Alice', 'Bob', 'Charlie'], $result);
    }

    public function testActorNamesWithObjects(): void
    {
        $result = Coerce::actorNames([
            ['name' => 'Alice'],
            ['name' => 'Bob'],
        ]);

        self::assertSame(['Alice', 'Bob'], $result);
    }

    public function testActorNamesWithMixedObjects(): void
    {
        $result = Coerce::actorNames([
            'Plain Name',
            ['name' => 'Alice'],
            123, // integers get stringified via nstr
            ['name' => 'Bob', 'role' => 'Lead'],
        ]);

        self::assertSame(['Plain Name', 'Alice', '123', 'Bob'], $result);
    }

    public function testActorNamesReturnsEmptyForNonArray(): void
    {
        self::assertSame([], Coerce::actorNames(null));
        self::assertSame([], Coerce::actorNames('abc'));
    }

    // ---- castList ----

    public function testCastListWithValidCast(): void
    {
        $result = Coerce::castList([
            ['name' => 'Alice', 'role' => 'Lead', 'profile_url' => 'https://example.com/alice'],
            ['name' => 'Bob', 'role' => 'Support', 'profile_url' => null],
        ]);

        self::assertCount(2, $result);
        self::assertInstanceOf(CastMember::class, $result[0]);
        self::assertSame('Alice', $result[0]->name);
        self::assertSame('Lead', $result[0]->role);
        self::assertSame('https://example.com/alice', $result[0]->profileUrl);
        self::assertSame('Bob', $result[1]->name);
    }

    public function testCastListReturnsNullForEmptyArray(): void
    {
        self::assertNull(Coerce::castList([]));
    }

    public function testCastListReturnsNullForNonArray(): void
    {
        self::assertNull(Coerce::castList(null));
    }

    public function testCastListSkipsItemsWithoutName(): void
    {
        $result = Coerce::castList([
            ['name' => 'Alice'],
            ['role' => 'No name'],
            ['name' => 'Bob', 'role' => 'Lead'],
        ]);

        self::assertCount(2, $result);
    }

    // ---- crewList ----

    public function testCrewListWithValidCrew(): void
    {
        $result = Coerce::crewList([
            ['name' => 'Director', 'job' => 'Director', 'profile_url' => 'https://example.com/dir'],
            ['name' => 'Writer', 'job' => 'Screenplay'],
        ]);

        self::assertCount(2, $result);
        self::assertInstanceOf(CrewMember::class, $result[0]);
        self::assertSame('Director', $result[0]->name);
        self::assertSame('Director', $result[0]->job);
        self::assertSame('Writer', $result[1]->name);
        self::assertSame('Screenplay', $result[1]->job);
    }

    public function testCrewListReturnsNullForEmptyArray(): void
    {
        self::assertNull(Coerce::crewList([]));
    }

    public function testCrewListReturnsNullForNonArray(): void
    {
        self::assertNull(Coerce::crewList(null));
    }

    public function testCrewListSkipsItemsWithoutName(): void
    {
        $result = Coerce::crewList([
            ['name' => 'Director'],
            ['job' => 'No name'],
        ]);

        self::assertCount(1, $result);
    }

    // ---- map ----

    public function testMapReturnsArrayAsIs(): void
    {
        $input = ['key' => 'value', 'num' => 42];
        $result = Coerce::map($input);

        self::assertSame($input, $result);
    }

    public function testMapReturnsEmptyArrayForNonArray(): void
    {
        self::assertSame([], Coerce::map(null));
        self::assertSame([], Coerce::map('string'));
        self::assertSame([], Coerce::map(123));
    }
}
