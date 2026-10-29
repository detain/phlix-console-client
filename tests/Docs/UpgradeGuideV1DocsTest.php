<?php

/**
 * Guard against the M-CONSOLE-19 class (review console__docmaint, M6):
 * docs/upgrade/v1.md once handed readers a `TokenStore::fileExists()`
 * migration instruction that could not compile — no such method exists on
 * TokenStore (the shipped file-presence check was a plain is_file() inside
 * exists(), replaced by the cache-backed count at 3db9a5b) — and it
 * guaranteed CLI backward compatibility for `browse` and `search` commands
 * that never were commands: bin/phlix's `switch ($cmd)` dispatch carries no
 * such case label today or in any past commit (git log --all -S finds none).
 *
 * Truth is derived from the code, never restated from prose: every
 * `Class::method()` cite in the guide resolves via reflection against the
 * real class, and the guide's command list is checked against bin/phlix's
 * own case labels. A renamed public method, a new command, or a resurrected
 * phantom reddens the pin here and its message directs docs and pin to move
 * together — the same doctrine as TokenFileNameDocsTest and
 * WatchCommandDocsTest.
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Docs;

use Phlix\Console\Config\Config;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Config\TokenStore;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class UpgradeGuideV1DocsTest extends TestCase
{
    /**
     * Classes whose `Class::method()` cites in the guide must resolve to
     * public methods; the keys are the short names the guide uses. When the
     * guide starts citing another real class, add it to this map — when it
     * cites a phantom, let this test fail loud.
     *
     * @var array<string, class-string>
     */
    private const CITABLE_CLASSES = [
        'Config' => Config::class,
        'TokenBundle' => TokenBundle::class,
        'TokenStore' => TokenStore::class,
    ];

    /**
     * Names that must never appear backticked in the guide: in-app screens,
     * never bin/phlix commands in any release.
     *
     * @var list<string>
     */
    private const NEVER_COMMANDS = ['browse', 'search'];

    /**
     * The sentence introducing the shipped command list. It is the pin's
     * quarry: a rewrite that drops it (or the whole CLI section) reddens
     * below with a fail-loud instead of passing vacuous.
     */
    private const COMMAND_LIST_MARKER = 'v1 ships these commands:';

    public function testEveryClassMethodCiteInTheGuideResolves(): void
    {
        $contents = self::read('docs/upgrade/v1.md');

        $cites = [];
        $matched = preg_match_all(
            '/`([A-Z][A-Za-z0-9]*)::([a-zA-Z_][a-zA-Z0-9_]*)\(\)`/',
            $contents,
            $cites,
            PREG_SET_ORDER
        );
        if ($matched === false) {
            throw new RuntimeException('Unable to scan docs/upgrade/v1.md for class-method cites.');
        }
        self::assertNotSame(
            0,
            $matched,
            'docs/upgrade/v1.md cites no `Class::method()` at all — the guide was '
            . 'rewritten and this pin lost its quarry; re-verify the guide rather '
            . 'than let the check pass vacuous.'
        );

        $unresolved = [];
        foreach ($cites as $cite) {
            [$text, $class, $method] = $cite;
            $fqcn = self::CITABLE_CLASSES[$class] ?? null;
            if ($fqcn === null) {
                $unresolved[] = $text . ' (class `' . $class . '` is not mapped in '
                    . self::class . ' — add it if the class is real)';
                continue;
            }

            $reflection = new ReflectionClass($fqcn);
            if (!$reflection->hasMethod($method) || !$reflection->getMethod($method)->isPublic()) {
                $unresolved[] = $text . ' (' . $fqcn . ' has no public ' . $method . '())';
            }
        }

        self::assertSame(
            [],
            $unresolved,
            'docs/upgrade/v1.md cites ' . count($unresolved) . ' method(s) that do not '
            . 'resolve against the shipped code: ' . implode('; ', $unresolved)
            . '. Fix the prose to the real API (or map/ship the class deliberately).'
        );
    }

    public function testTheGuideStillDocumentsTheExistsBehaviorChange(): void
    {
        self::assertStringContainsString(
            '`TokenStore::exists()`',
            self::read('docs/upgrade/v1.md'),
            'The v1 upgrade guide no longer cites `TokenStore::exists()` — the '
            . 'exists() semantics change IS the guide\'s breaking change. Keep the '
            . 'prose truthful, and move this pin with it if the story changes.'
        );
    }

    public function testTheGuideCarriesNoFileExistsPhantom(): void
    {
        self::assertStringNotContainsString(
            'fileExists',
            self::read('docs/upgrade/v1.md'),
            'docs/upgrade/v1.md resurrects `fileExists()` — a method TokenStore '
            . 'never had; the real file-based check is PHP is_file() on '
            . 'TokenStore::path(). If a fileExists() is ever deliberately shipped, '
            . 'update the prose and this pin together.'
        );
    }

    public function testTheCommandListMatchesTheRealCli(): void
    {
        $commands = self::cliCommands();
        $line = self::commandListLine();

        $cited = [];
        preg_match_all('/`(-{1,2}[A-Za-z]+|[a-z][a-z-]+)`/', $line, $matches);
        foreach ($matches[1] as $name) {
            $cited[] = $name;
        }
        $cited = array_values(array_unique($cited));
        self::assertNotEmpty(
            $cited,
            'The guide\'s command-list line cites no backticked command; rewrite it '
            . 'against bin/phlix instead of leaving a bare marker.'
        );

        $unknown = array_values(array_diff($cited, $commands));
        self::assertSame(
            [],
            $unknown,
            'docs/upgrade/v1.md cites command(s) bin/phlix does not dispatch: '
            . implode(', ', $unknown) . '. Real commands: ' . implode(', ', $commands) . '.'
        );

        $primaries = array_values(array_filter(
            $commands,
            static fn (string $name): bool => !str_starts_with($name, '-')
        ));
        $missing = array_values(array_diff($primaries, $cited));
        self::assertSame(
            [],
            $missing,
            'bin/phlix dispatches ' . implode(', ', $missing) . ' but the guide\'s '
            . 'command list omits it — move the guide and this check together.'
        );
    }

    public function testTheGuideNeverCitesPhantomCommands(): void
    {
        $contents = self::read('docs/upgrade/v1.md');

        foreach (self::NEVER_COMMANDS as $name) {
            self::assertStringNotContainsString(
                '`' . $name . '`',
                $contents,
                $name . ' was never a bin/phlix command in any release (no case '
                . 'label today, none in history); it is an in-app screen opened by '
                . 'its keyboard key. Do not restore it as a CLI verb.'
            );
        }
    }

    /**
     * Command names dispatched by bin/phlix's `switch ($cmd)` — including
     * `-V`/`--version` style aliases — read from the script itself.
     *
     * @return list<string>
     */
    private static function cliCommands(): array
    {
        $entrypoint = self::read('bin/phlix');
        $start = strpos($entrypoint, 'switch ($cmd) {');
        if ($start === false) {
            throw new RuntimeException(
                'bin/phlix no longer carries the `switch ($cmd)` command dispatch; '
                . 'update cliCommands() in ' . self::class . '.'
            );
        }

        preg_match_all("/case '([A-Za-z][A-Za-z-]*)':/", substr($entrypoint, $start), $matches);
        $commands = array_values(array_unique($matches[1]));
        self::assertNotEmpty(
            $commands,
            'No `case` command labels found under bin/phlix\'s `switch ($cmd)`; '
            . 'update cliCommands() in ' . self::class . '.'
        );

        /** @var list<string> $commands */
        return $commands;
    }

    private static function commandListLine(): string
    {
        foreach (explode("\n", self::read('docs/upgrade/v1.md')) as $line) {
            if (str_contains($line, self::COMMAND_LIST_MARKER)) {
                return $line;
            }
        }

        throw new RuntimeException(
            'docs/upgrade/v1.md lost its "' . self::COMMAND_LIST_MARKER . '" line; '
            . 'the CLI section must state the shipped command set (see bin/phlix '
            . 'dispatch) — restore it or move COMMAND_LIST_MARKER with it.'
        );
    }

    private static function read(string $relative): string
    {
        $contents = file_get_contents($relative);
        if ($contents === false) {
            throw new RuntimeException('Cannot read repo file: ' . $relative);
        }

        return $contents;
    }
}
