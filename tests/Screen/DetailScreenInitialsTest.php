<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Screen\DetailScreen;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Cast-avatar initials (review M-CONSOLE-69): the helper cut BYTES on UTF-8
 * names, so an accented or non-Latin cast member rendered mojibake inside the
 * avatar chip. The cure is codepoint cuts + codepoint folding; these pins are
 * the contract - byte substr()/strtoupper() could not produce any of the
 * multibyte expectations below.
 */
final class DetailScreenInitialsTest extends TestCase
{
    private static function initials(string $name): string
    {
        $method = new ReflectionMethod(DetailScreen::class, 'initials');
        $screen = (new ReflectionClass(DetailScreen::class))->newInstanceWithoutConstructor();

        return $method->invoke($screen, $name);
    }

    public static function provideInitials(): iterable
    {
        // name => abbreviation. Order is the SHIPPED one, pinned before the
        // codepoint cure and preserved by it: last part's initial first.
        yield 'ascii first-last' => ['Luke Robinson', 'RL'];
        yield 'reversed parts take last then first' => ['Jonah H.-King', 'HJ'];
        yield 'single word takes two letters' => ['Madonna', 'MA'];
        yield 'surrounding whitespace is ignored' => ['  Uwe  Occupation  ', 'OU'];
        yield 'accented latin folds whole' => ['José Gómez', 'GJ'];
        yield 'cyrillic uppercases whole codepoints' => ['Анна Иванова', 'ИА'];
        yield 'cjk keeps whole characters' => ['佐藤 花子', '花佐'];
    }

    /** @dataProvider provideInitials */
    public function testInitialsCutCodepointsNotBytes(string $name, string $expected): void
    {
        self::assertSame($expected, self::initials($name));
    }

    public function testEmptyNameYieldsNoInitials(): void
    {
        self::assertSame('', self::initials('   '));
    }
}
