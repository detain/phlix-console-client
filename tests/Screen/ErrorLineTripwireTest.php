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
    /** Interpolation form and, since CON-S12, the concatenation forms. */
    private const RAW = '/\{\$this->errors?(?:\[[^\]]*\])?\}'
        . '|\.\s*\$this->errors?\b(?!\s*(?:->|\())'
        . '|\$this->errors?\s*\.(?!\.)/';

    public function testNoRawErrorInterpolationInScreens(): void
    {
        $offenders = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/Screen/*.php') ?: [] as $file) {
            if (preg_match(self::RAW, (string) file_get_contents($file)) === 1) {
                $offenders[] = basename($file);
            }
        }

        self::assertSame([], $offenders, 'Render errors via Chrome::errorLine().');
    }

    public function testScannerWouldCatchARawInterpolation(): void
    {
        $bad = 'return "\n  {$this->error}";';
        self::assertSame(1, preg_match(self::RAW, $bad));
        self::assertSame(1, preg_match(self::RAW, "\$lines[] = '  ' . \$this->error;"), 'concat form');
        self::assertSame(1, preg_match(self::RAW, "\$x = \$this->error . ' tail';"), 'trailing concat form');
        self::assertSame(0, preg_match(self::RAW, "\$lines[] = '  ' . Chrome::errorLine(\$this->error, 80);"), 'errorLine is the lawful route');
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
