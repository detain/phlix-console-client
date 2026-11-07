<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Screen\CapturesSlash;
use Phlix\Console\Screen\HostsTextInput;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Drift law behind the App's `/` (global search) and `:` (command palette)
 * gate [review console__a11y, CU-007]: a screen that hosts a sugar-forms
 * text `Input` must declare itself to the gate — either blanket-capturing
 * via {@see CapturesSlash} (own filter/search, or where an overlay would
 * orphan state) or field-aware via {@see HostsTextInput} (the keys stay
 * shortcuts while nothing is being edited).
 *
 * Before this law, ten Input-hosting screens were unmarked: typing `:` in
 * the admin JSON value editor popped the palette instead, `/` vanished
 * into a search overlay mid-URL, and SettingsScreen's own docblock recited
 * a capture law its class never implemented.
 *
 * The census is file-scanned (a new Input-hosting screen reddens this pin
 * until it carries a marker), and every claimed marker is verified against
 * the loaded class — comments cannot satisfy it.
 */
final class TextInputCaptureGateTest extends TestCase
{
    /** Screens that host a text input must implement one of these. */
    private const MARKERS = [CapturesSlash::class, HostsTextInput::class];

    public function testEveryInputHostingScreenDeclaresItselfToThePrintableKeyGate(): void
    {
        $dir = self::repoRoot() . '/src/Screen';
        $files = glob($dir . '/*.php');
        self::assertIsArray($files, 'src/Screen is missing — wrong venue, not a clean scan');

        $offenders = [];
        $census = 0;
        foreach ($files as $path) {
            $source = file_get_contents($path);
            self::assertNotFalse($source);
            if (!str_contains($source, 'Input::new(')) {
                continue;
            }
            $census++;

            $fqcn = self::classIn($path, $source);
            $implemented = class_implements($fqcn);
            self::assertIsArray($implemented, "screen class {$fqcn} did not autoload");

            $markers = array_intersect(self::MARKERS, $implemented);
            if ($markers === []) {
                $offenders[] = basename($path);
            }
        }

        self::assertGreaterThan(10, $census, 'the Input-hosting census collapsed; the scan mis-located');
        self::assertSame([], $offenders, 'Input-hosting screens must implement CapturesSlash or HostsTextInput so `/` and `:` reach the field while it is focused [CU-007]');
    }

    public function testSettingsScreenCarriesTheFieldAwareMarkerItsDocblockOnceClaimed(): void
    {
        $screen = new \ReflectionClass(\Phlix\Console\Screen\SettingsScreen::class);

        self::assertTrue(
            $screen->implementsInterface(HostsTextInput::class),
            'SettingsScreen advertises the capture law — it must implement it',
        );
        self::assertFalse(
            $screen->implementsInterface(CapturesSlash::class),
            'SettingsScreen is field-aware, not a blanket capturer — the shortcuts belong when focus sits on the Select',
        );
    }

    /** Resolve the one final class declared in a screen file. */
    private static function classIn(string $path, string $source): string
    {
        if (preg_match('/^final class (\w+)/m', $source, $m) !== 1) {
            throw new RuntimeException("no final class found in {$path}");
        }

        return 'Phlix\\Console\\Screen\\' . $m[1];
    }

    private static function repoRoot(): string
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/bin/phlix')) {
            throw new RuntimeException('repo root lookup failed from ' . __DIR__);
        }

        return $root;
    }
}
