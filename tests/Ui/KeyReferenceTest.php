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

    /** The overlay's title and hint ride the catalog, not literals [CON-S16]. */
    public function testKeyHelpTitleAndHintAreLocalized(): void
    {
        \SugarCraft\Core\I18n\T::reset();
        \SugarCraft\Core\I18n\T::setLocale('de');
        try {
            $view = \SugarCraft\Core\Util\Ansi::strip((new \Phlix\Console\Screen\KeyHelpScreen(100, 30))->view());
            self::assertStringContainsString('Tastenkürzel', $view);
            self::assertStringContainsString('zurück', $view);
            self::assertStringNotContainsString('Keyboard shortcuts', $view);
        } finally {
            \SugarCraft\Core\I18n\T::reset();
        }
    }
}
