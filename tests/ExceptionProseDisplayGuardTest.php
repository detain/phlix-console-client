<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Display-surface law for {@see \Phlix\Console\I18n\ExceptionSentences}
 * (review console__writer M15): a screen must never put a raw
 * `$e->getMessage()` in front of the user. Raw transport/library prose
 * (`SQLSTATE[...]`, curl socket errors, vendor type errors) reached red
 * toasts at 44 sites across 24 screens while the sanitizer carried the
 * doctrine; every one now goes through `ExceptionSentences::sentence()`
 * (standalone reason slot) or `::composed()` (context + at most one
 * sanitized reason).
 *
 * The law is structural, line-scoped, and fail-loud:
 *
 *  - a line in `src/Screen/` that calls `->getMessage()` must either pass
 *    it through ExceptionSentences, or be a PROBE (`str_contains` /
 *    `stripos` — reads the text for classification, never displays it),
 *    or be one of the two explicitly-carried exemptions below;
 *  - comments/docblocks (`*`, `//`) are prose about the law, not violations;
 *  - the exemptions are asserted to still be what they claim: the curated
 *    `DownloadException` arm (app-authored single-line failure prose that
 *    OUTRANKS the generic sentence for usefulness) and the login/register
 *    maps (server envelope prose is wire truth by ExceptionSentences' own
 *    doctrine). If an exemption's surrounding code changes, the pin
 *    reddens and the author re-adjudicates it here.
 *
 * Scope is the render surface: `error_log()` sinks (DownloadService, the
 * WS callback guards) are where the diagnostics are SUPPOSED to land and
 * are deliberately outside this law.
 */
final class ExceptionProseDisplayGuardTest extends TestCase
{
    /** Screen directory whose toasts/overlays this law guards. */
    private const SCREEN_DIR = 'src/Screen';

    /**
     * Carried exemptions: file basename => [needle proving the shape, allowed line].
     * Both must appear together or the exemption is stale (and the raw site
     * becomes a violation again).
     *
     * @var array<string, array{string, string}>
     */
    private const EXEMPTIONS = [
        'DetailScreen.php' => [
            'instanceof DownloadException',
            '? $e->getMessage()',
        ],
    ];

    public function testNoScreenDisplaysARawThrowableMessage(): void
    {
        $root = self::repoRoot();
        $files = glob($root . '/' . self::SCREEN_DIR . '/*.php');
        self::assertIsArray($files, 'src/Screen is missing — wrong venue, not a clean scan');
        self::assertGreaterThan(10, count($files), 'the screen census collapsed; guard mis-located');

        $violations = [];
        foreach ($files as $path) {
            $lines = file($path);
            self::assertIsArray($lines);
            $basename = basename($path);

            foreach ($lines as $index => $line) {
                if (!str_contains($line, '->getMessage()')) {
                    continue;
                }

                $trimmed = trim($line);
                if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')) {
                    continue; // prose about the law
                }

                if (
                    str_contains($line, 'ExceptionSentences::')
                    || str_contains($line, 'str_contains(')
                    || str_contains($line, 'stripos(')
                ) {
                    continue; // sanitized or a probe
                }

                if (self::exempted($basename, $lines, (int) $index)) {
                    continue;
                }

                $violations[] = $basename . ':' . ($index + 1) . ' ' . $trimmed;
            }
        }

        self::assertSame(
            [],
            $violations,
            'Raw $e->getMessage() reached the render surface — internals (SQLSTATE, curl, vendor'
            . ' type errors) leaking into user-visible toasts. Route through'
            . ' ExceptionSentences::sentence()/composed() [review M15]. Violations: '
            . implode(' | ', $violations),
        );
    }

    public function testTheExceptionSentencesChokepointIsLiveNotDecorative(): void
    {
        $root = self::repoRoot();
        $users = 0;
        foreach ((array) glob($root . '/' . self::SCREEN_DIR . '/*.php') as $path) {
            if (str_contains((string) file_get_contents((string) $path), 'ExceptionSentences::')) {
                $users++;
            }
        }

        self::assertGreaterThanOrEqual(
            20,
            $users,
            'The M15 sweep left fewer than twenty screens consuming the chokepoint — something'
            . ' re-inlined raw prose or the sanitizer went unused again.',
        );
    }

    /**
     * @param list<string> $lines
     * @phpstan-param array<array-key, string> $lines
     */
    private static function exempted(string $basename, array $lines, int $index): bool
    {
        if (!isset(self::EXEMPTIONS[$basename])) {
            return false;
        }

        [$needle, $allowed] = self::EXEMPTIONS[$basename];
        if (trim($lines[$index]) !== $allowed) {
            return false;
        }

        // The curated-instanceof shape must sit in the three lines above.
        $window = implode('', array_slice($lines, max(0, $index - 3), 3));

        return str_contains($window, $needle);
    }

    private static function repoRoot(): string
    {
        $root = dirname(__DIR__);
        if (!is_file($root . '/bin/phlix')) {
            throw new RuntimeException('repo root lookup failed from ' . __DIR__);
        }

        return $root;
    }
}
