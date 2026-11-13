<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Ui\KeyReference;
use PHPUnit\Framework\TestCase;

final class KeyReferenceTest extends TestCase
{
    public function testEveryChordIsDocumentedInTheShippedKeybindingsSection(): void
    {
        $doc = (string) file_get_contents(__DIR__ . '/../../docs/clients/console.md');
        $start = strpos($doc, '### Keybindings');
        $end = strpos($doc, '#### Administration keys');
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $section = substr($doc, $start, $end - $start);

        foreach (KeyReference::sections() as $s) {
            foreach ($s['rows'] as [$keys]) {
                // Split "a / b", "a or b" into individual chords; each must appear in backticks.
                foreach (preg_split('~ / | or ~', $keys) ?: [] as $chord) {
                    self::assertStringContainsString('`' . $chord, $section, "chord '$chord' missing from docs/clients/console.md Keybindings");
                }
            }
        }
    }

    public function testLinesRenderEveryRow(): void
    {
        $lines = implode("\n", KeyReference::lines());
        self::assertStringContainsString('Command palette', $lines);
        self::assertStringContainsString('?', $lines);
    }
}
