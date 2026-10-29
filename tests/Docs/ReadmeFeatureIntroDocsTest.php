<?php

/**
 * Guard against the M-CONSOLE-29 class (review console__newcomer): the README
 * feature intro undersold the actual product. The palette parenthetical still
 * carried the Phase-5-era shortlist ("search, jump to any library, open any
 * screen, log out, quit") while App::staticActions() ships twenty-three
 * labels across the whole screen registry — and it deferred "the full list"
 * to a *Controls* section that does not exist anywhere in the file. The
 * shipped subsystems a newcomer cannot see from the pitch: SyncPlay watch
 * parties, casting, item downloads, the admin client, and trickplay scrub
 * previews (the word "trickplay" appeared zero times in the README).
 *
 * Doctrine, per ScreenInventoryDocsTest (which pins the SAME live labels
 * against the manual's Screens table): the code side is parsed with
 * token_get_all — never restated from prose — and pinned against the docs
 * surface. Here the docs surface is README.md's feature intro (title through
 * "## Requirements"), so a palette action added to or renamed in App.php
 * reddens this suite until the intro enumerates it in the same change.
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Docs;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReadmeFeatureIntroDocsTest extends TestCase
{
    /** Source of truth for the shipped palette registry. */
    private const APP_SOURCE = 'src/App.php';

    /** The docs surface under guard. */
    private const README_SOURCE = 'README.md';

    /** The intro opens at the H1. */
    private const README_INTRO_START = "# phlix-console-client\n";

    /** ...and runs to the first non-feature section. */
    private const README_INTRO_END = "\n## Requirements";

    /**
     * Subsystems the review proved shipped-but-invisible; case-insensitive
     * substrings the feature intro must name. Each has a handler behind it:
     * SyncPlay/watch party -> src/Api/SyncPlay/SyncPlayService.php +
     * src/Ui/SyncPlayModal.php (`y`); trickplay -> PlayerScreen's lazy
     * sprite-preview fetch; download -> src/Download/DownloadService.php
     * (DetailScreen `d`); Cast -> src/Screen/CastScreen.php; Admin -> the
     * admin-gated palette action + the Admin* screen family; command
     * palette/direct-play -> App::staticActions() + the ffmpeg player.
     *
     * @var list<string>
     */
    private const SHIPPED_SUBSYSTEMS = [
        'command palette',
        'direct-play',
        'syncplay',
        'watch party',
        'trickplay',
        'download',
        'cast',
        'admin',
    ];

    public function testEveryPaletteActionLabelIsNamedInTheReadmeIntro(): void
    {
        $intro = mb_strtolower(self::readIntro());

        $missing = [];
        foreach (self::extractPaletteActionLabels() as $label) {
            if (!str_contains($intro, mb_strtolower($label))) {
                $missing[] = $label;
            }
        }

        sort($missing);

        self::assertSame(
            [],
            $missing,
            'src/App.php offers palette actions the README feature intro never names: '
            . implode(', ', $missing)
            . ' — the intro must enumerate the shipped registry (review M-CONSOLE-29: '
            . 'the shortlist undersold the product).'
        );
    }

    public function testTheReadmeIntroNamesEveryShippedSubsystem(): void
    {
        $intro = mb_strtolower(self::readIntro());

        $missing = [];
        foreach (self::SHIPPED_SUBSYSTEMS as $subsystem) {
            if (!str_contains($intro, $subsystem)) {
                $missing[] = $subsystem;
            }
        }

        self::assertSame(
            [],
            $missing,
            'The README feature intro no longer names: ' . implode(', ', $missing)
            . ' — each is a shipped subsystem with a handler; hiding it is the '
            . 'M-CONSOLE-29 defect class (underselling).'
        );
    }

    public function testEveryDeferredCrossReferenceInTheReadmeResolvesToARealSection(): void
    {
        $readme = self::readRepoFile(self::README_SOURCE);

        // "under *Controls* below" / "under **Run it** below" — the pointer
        // idiom the stale palette sentence used for a section that does not
        // exist. Every captured name must match a real README heading.
        $headings = [];
        foreach (explode("\n", $readme) as $line) {
            if (preg_match('/^#{1,6}\s+(.+?)\s*$/m', $line, $match) === 1) {
                $headings[] = mb_strtolower($match[1]);
            }
        }

        preg_match_all('/under \*{1,2}([^*]+)\*{1,2}/', $readme, $pointers);

        $dead = [];
        foreach ($pointers[1] as $target) {
            if (!in_array(mb_strtolower(trim($target)), $headings, true)) {
                $dead[] = trim($target);
            }
        }

        self::assertSame(
            [],
            $dead,
            'README.md defers to section(s) that do not exist: ' . implode(', ', $dead)
            . ' — M-CONSOLE-29: "the full list is under *Controls* below" pointed '
            . 'at a heading the file never had.'
        );
    }

    /**
     * The feature intro: H1 through the "## Requirements" heading.
     */
    private static function readIntro(): string
    {
        $readme = self::readRepoFile(self::README_SOURCE);
        $start = strpos($readme, self::README_INTRO_START);
        $end = strpos($readme, self::README_INTRO_END);
        if ($start === false || $end === false || $end <= $start) {
            throw new RuntimeException(
                'The README feature intro (' . self::README_INTRO_START . ' through '
                . self::README_INTRO_END . ') no longer delimits; relocate the markers.'
            );
        }

        return substr($readme, (int) $start, (int) $end - (int) $start);
    }

    /**
     * Literal first-argument strings of every `new PaletteAction(` in App
     * (both ternary arms included); per ScreenInventoryDocsTest, whose
     * token walk this mirrors so the census cannot drift between the two
     * docs surfaces.
     *
     * @return list<string>
     */
    private static function extractPaletteActionLabels(): array
    {
        $tokens = token_get_all(self::readRepoFile(self::APP_SOURCE));
        $labels = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'PaletteAction') {
                continue;
            }

            $previous = self::previousSignificant($tokens, $index);
            if (!is_array($previous) || $previous[0] !== T_NEW) {
                continue;
            }

            $open = self::nextSignificant($tokens, $index);
            if ($open !== '(') {
                continue;
            }

            // Collect string literals up to the first top-level comma
            // (the label-argument boundary); ternary labels yield both arms.
            $depth = 0;
            for ($cursor = $index + 1; $cursor < $count; $cursor++) {
                $current = $tokens[$cursor];
                if ($current === '(' || $current === '[') {
                    $depth++;
                    continue;
                }

                if ($current === ')' || $current === ']') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }

                    continue;
                }

                if ($depth === 1 && $current === ',') {
                    break;
                }

                if (is_array($current) && $current[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $labels[] = trim(substr($current[1], 1, -1));
                }
            }
        }

        return array_values(array_unique($labels));
    }

    /** @param list<array{0:int,1:string}|string> $tokens */
    private static function nextSignificant(array $tokens, int $index): array|string
    {
        $count = count($tokens);
        for ($cursor = $index + 1; $cursor < $count; $cursor++) {
            $token = $tokens[$cursor];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return '';
    }

    /** @param list<array{0:int,1:string}|string> $tokens */
    private static function previousSignificant(array $tokens, int $index): array|string|null
    {
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            $token = $tokens[$cursor];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    private static function readRepoFile(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        if (!is_file($path)) {
            throw new RuntimeException($relative . ' moved or was deleted; update ' . self::class . '.');
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read ' . $relative . '.');
        }

        return $contents;
    }
}
