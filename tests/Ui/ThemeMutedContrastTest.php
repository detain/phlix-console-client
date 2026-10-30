<?php

/**
 * M-CONSOLE-04 (review console__a11y item 5) — the muted status colour must
 * clear WCAG 2.x AA for body text (4.5:1) on the backdrops each theme is
 * tuned for. The ratios are RE-MEASURED here from the live theme tokens with
 * the reference WCAG relative-luminance formula — nothing restates a hex or a
 * number from prose, so a palette edit rotates the verdict honestly.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Ui\Theme;
use PHPUnit\Framework\TestCase;

final class ThemeMutedContrastTest extends TestCase
{
    /** The AA body-text floor. */
    private const MIN_RATIO = 4.5;

    /** Backdrops a Midnight user realistically runs (dark terminal defaults). */
    private const DARK_BACKDROPS = ['#000000', '#1e1e1e', '#1e1e2e', '#11111b'];

    /** Backdrops a Daylight user realistically runs (white/paper terminal defaults). */
    private const LIGHT_BACKDROPS = ['#ffffff', '#f5f5f5'];

    private static function channel(int $octet): float
    {
        $s = $octet / 255.0;

        return $s <= 0.04045 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
    }

    private static function luminance(string $hex): float
    {
        /** @var array{0: int, 1: int, 2: int} $rgb */
        $rgb = sscanf($hex, '#%02x%02x%02x');

        return 0.2126 * self::channel($rgb[0])
            + 0.7152 * self::channel($rgb[1])
            + 0.0722 * self::channel($rgb[2]);
    }

    private static function ratio(string $foreground, string $background): float
    {
        [$a, $b] = [self::luminance($foreground), self::luminance($background)];
        $light = max($a, $b);
        $dark = min($a, $b);

        return ($light + 0.05) / ($dark + 0.05);
    }

    public function testEveryNonNullColourTokenIsSixDigitHex(): void
    {
        foreach (Theme::all() as $theme) {
            foreach (['accent' => $theme->accent, 'muted' => $theme->muted] as $token => $hex) {
                if ($hex === null) {
                    continue;
                }

                self::assertMatchesRegularExpression(
                    '/^#[0-9a-f]{6}$/',
                    $hex,
                    "theme '{$theme->id}' {$token} token must parse for the contrast maths"
                );
            }
        }
    }

    public function testMidnightMutedClearsAaOnCommonDarkBackdrops(): void
    {
        $muted = Theme::midnight()->muted;
        self::assertNotNull($muted);

        foreach (self::DARK_BACKDROPS as $backdrop) {
            self::assertGreaterThanOrEqual(
                self::MIN_RATIO,
                self::ratio($muted, $backdrop),
                "Midnight muted on {$backdrop} must clear 4.5:1"
            );
        }
    }

    public function testDaylightMutedClearsAaOnCommonLightBackdrops(): void
    {
        $muted = Theme::daylight()->muted;
        self::assertNotNull($muted);

        foreach (self::LIGHT_BACKDROPS as $backdrop) {
            self::assertGreaterThanOrEqual(
                self::MIN_RATIO,
                self::ratio($muted, $backdrop),
                "Daylight muted on {$backdrop} must clear 4.5:1"
            );
        }
    }

    public function testMidnightAccentClearsAaOnCommonDarkBackdrops(): void
    {
        $accent = Theme::midnight()->accent;
        self::assertNotNull($accent);

        foreach (self::DARK_BACKDROPS as $backdrop) {
            self::assertGreaterThanOrEqual(
                self::MIN_RATIO,
                self::ratio($accent, $backdrop),
                "Midnight accent on {$backdrop} must clear 4.5:1"
            );
        }
    }

    public function testNocturneCarriesNoTokensToContrastCheck(): void
    {
        $nocturne = Theme::nocturne();

        self::assertNull($nocturne->accent);
        self::assertNull($nocturne->muted);
    }
}
