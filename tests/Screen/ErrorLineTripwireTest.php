<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\Theme;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;

/**
 * Tripwire [CON-12/CON-45/CON-42]: no screen interpolates a raw error string
 * into its body; every error rides Chrome::errorLine() (bounded, danger-voiced).
 */
final class ErrorLineTripwireTest extends TestCase
{
    public function testNoRawErrorInterpolationInScreens(): void
    {
        $offenders = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/Screen/*.php') ?: [] as $file) {
            if (preg_match('/\{\$this->errors?(?:\[[^\]]*\])?\}/', (string) file_get_contents($file)) === 1) {
                $offenders[] = basename($file);
            }
        }

        self::assertSame([], $offenders, 'Render errors via Chrome::errorLine().');
    }

    public function testScannerWouldCatchARawInterpolation(): void
    {
        $bad = 'return "\n  {$this->error}";';
        self::assertSame(1, preg_match('/\{\$this->errors?(?:\[[^\]]*\])?\}/', $bad));
    }

    public function testErrorLineIsBoundedByDisplayCells(): void
    {
        $line = Chrome::errorLine(str_repeat('漢字', 80), 40, Theme::nocturne());
        self::assertLessThanOrEqual(34, Width::string($line));
        self::assertStringContainsString('…', $line);
    }

    public function testErrorLineFlattensMultilineAndHandlesTinyBudget(): void
    {
        self::assertStringNotContainsString("\n", Chrome::errorLine("one\ntwo", 80));
        self::assertSame('', Chrome::errorLine('x', 4));
    }
}
