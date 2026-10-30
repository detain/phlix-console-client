<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit\Screen;

use Phlix\Console\Screen\AdminMetricsScreen;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;

/**
 * M-CONSOLE-74 — the Top Routes panel clips with the house convention.
 *
 * The shipped form byte-cut the path: `strlen($path) > 30` measured BYTES,
 * kept the tail behind ASCII `'...'`, and `substr(-27)` could (and did)
 * split multi-byte UTF-8 clusters mid-codepoint; routes with non-ASCII
 * segments rendered mojibake. The cure delegates to the vendor's
 * grapheme-aware `Width::truncateMiddle` — cell budget, one '…', both ends
 * of the route preserved (the operator scans the head verb as much as the
 * endpoint tail).
 */
final class AdminMetricsRoutesPanelTest extends TestCase
{
    public function testALongAsciiRouteIsMiddleTruncatedKeepingBothEnds(): void
    {
        $panel = self::panelWith([
            ['method' => 'GET', 'route' => '/api/v1/media/items/very/long/tail/endpoint', 'request_count' => 7, 'p95_ms' => 12.0],
        ]);

        self::assertStringContainsString('GET /api', $panel, 'the head of the route survives');
        self::assertStringContainsString('endpoint', $panel, 'the tail of the route survives');
        self::assertStringContainsString('…', $panel, 'the cut is announced with the house ellipsis');
        self::assertStringNotContainsString('...', $panel, 'the ASCII dot-trio fossil stays dead');
    }

    public function testALongNonAsciiRouteIsNeverByteSplit(): void
    {
        $panel = self::panelWith([
            ['method' => 'GET', 'route' => '/api/v1/音楽/ライブラリ/アイテム/詳細情報/最終項目', 'request_count' => 1, 'p95_ms' => 3.0],
        ]);

        self::assertTrue(mb_check_encoding($panel, 'UTF-8'), 'the panel stays valid UTF-8 end to end');
        self::assertStringNotContainsString("\u{FFFD}", $panel);
        self::assertStringContainsString('情報/最終項目', $panel, 'the tail clusters survive whole within the 15-cell tail budget');

        $paths = [];
        foreach (explode("\n", Ansi::strip($panel)) as $line) {
            if (preg_match('/^\s*GET (.+?)  \d+  p95:/u', $line, $match) === 1) {
                $paths[] = $match[1];
            }
        }

        self::assertNotEmpty($paths, 'the route row renders');
        foreach ($paths as $path) {
            self::assertLessThanOrEqual(30, Width::string($path), 'the route field never exceeds its 30-cell budget');
        }
    }

    /**
     * Drive the screen's private Top Routes panel directly: the row budget
     * is the law under test, and the panel is pure over the routes array.
     *
     * @param list<array<string, mixed>> $routes
     */
    private static function panelWith(array $routes): string
    {
        $screen = (new ReflectionClass(AdminMetricsScreen::class))->newInstanceWithoutConstructor();

        $property = new ReflectionProperty(AdminMetricsScreen::class, 'routes');
        $property->setValue($screen, $routes);

        $method = new ReflectionMethod(AdminMetricsScreen::class, 'routesPanel');

        return $method->invoke($screen);
    }
}
