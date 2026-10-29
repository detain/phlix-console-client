<?php

/**
 * Guard against the M-CONSOLE-28 class (review console__newcomer): the README
 * once introduced the product as "A full-window terminal (TUI) client for
 * Phlix" without ever answering WHAT Phlix is, and its "## Requirements" list
 * named PHP, ffmpeg and a terminal but never the one thing the client cannot
 * run without — a running Phlix server. Every later step in the README is a
 * dead end for a reader who does not already host one (README.md:9 /
 * :132-138 review-era coords; the block drifted, the gap stayed).
 *
 * Doctrine, per WatchStateLoopDocsTest / StaticAnalysisClaimsDocsTest: the
 * claims checked here are README surfaces pinned against the canonical
 * server-project coordinates, and the server URL is an exact-match constant —
 * a rewording that drops the definition, the self-host statement, or the link
 * reddens this suite with a message naming both surfaces. If the server repo
 * ever moves, rotate SERVER_REPO_URL and both README sites together (same law
 * as the extension census in RuntimeRequirementsDocsTest).
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Docs;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WhatIsPhlixDocsTest extends TestCase
{
    /** Canonical server-project URL — the one link every client README owes. */
    private const SERVER_REPO_URL = 'https://github.com/detain/phlix-server';

    /** Opening prose block: from the H1 to the first section heading. */
    private const README_TITLE = "# phlix-console-client\n";

    private const README_FIRST_SECTION = "\n## Render modes";

    /** Source-install requirements list markers (shared with RuntimeRequirementsDocsTest). */
    private const README_REQUIREMENTS_START = "## Requirements\n";

    private const README_REQUIREMENTS_END = "\n## Install";

    public function testTheOpeningBlockAnswersWhatPhlixIs(): void
    {
        $opening = self::openingBlock();

        // The newcomer's first screen must say Phlix is a media server and
        // that you run it yourself — not just brand-drop "for Phlix".
        self::assertMatchesRegularExpression(
            '/\bmedia server\b/i',
            $opening,
            'README.md opening no longer defines Phlix as a media server '
            . '(review M-CONSOLE-28: "What is Phlix?" was never answered).'
        );
        self::assertMatchesRegularExpression(
            '/(you (run|host) (it )?yourself|self-host)/i',
            $opening,
            'README.md opening no longer states that Phlix is run/hosted by '
            . 'the user (the self-host fact a newcomer needs to evaluate it).'
        );
    }

    public function testTheOpeningBlockLinksTheServerProject(): void
    {
        self::assertStringContainsString(
            self::SERVER_REPO_URL,
            self::openingBlock(),
            'README.md opening no longer links ' . self::SERVER_REPO_URL
            . ' — the server is a separate project and the reader must be able to find it.'
        );
    }

    public function testTheServerPrerequisiteLeadsTheRequirementsList(): void
    {
        $section = self::extract(
            self::read('README.md'),
            self::README_REQUIREMENTS_START,
            self::README_REQUIREMENTS_END
        );

        // First bullet — the thing without which every later step is a dead
        // end must be stated first, where a newcomer is about to provision.
        // The block spans every wrapped line up to the next bullet.
        $lines = explode("\n", $section);
        $firstStart = null;
        foreach ($lines as $i => $line) {
            if (str_starts_with($line, '- ')) {
                $firstStart = $i;
                break;
            }
        }
        self::assertNotNull($firstStart, 'README.md "## Requirements" lost its first bullet');
        $firstBullet = [];
        for ($i = $firstStart; $i < count($lines); $i++) {
            if ($i > $firstStart && str_starts_with($lines[$i], '- ')) {
                break;
            }
            $firstBullet[] = $lines[$i];
        }
        $bulletText = implode("\n", $firstBullet);
        self::assertMatchesRegularExpression(
            '/(running|up and running) phlix|phlix server|phlix media server|phlix-server/i',
            $bulletText,
            'README.md "## Requirements" no longer leads with the Phlix server '
            . 'prerequisite (review M-CONSOLE-28: unstated server = dead ends).'
        );
        self::assertStringContainsString(
            self::SERVER_REPO_URL,
            $bulletText,
            'The Requirements server bullet must carry the canonical link '
            . self::SERVER_REPO_URL . ' (unstated AND unlinked was the defect).'
        );
    }

    public function testTheServerUrlIsCarriedAtExactlyTheTwoPinnedSites(): void
    {
        $count = substr_count(self::read('README.md'), self::SERVER_REPO_URL);

        self::assertSame(
            2,
            $count,
            'README.md names ' . self::SERVER_REPO_URL . ' ' . $count
            . ' times; the pin expects exactly the opening-block link and the '
            . 'Requirements bullet. Add new sites deliberately and rotate this census.'
        );
    }

    private static function openingBlock(): string
    {
        return self::extract(
            self::read('README.md'),
            self::README_TITLE,
            self::README_FIRST_SECTION
        );
    }

    private static function read(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("cannot read {$path}");
        }
        return $contents;
    }

    private static function extract(string $haystack, string $start, string $end): string
    {
        $from = strpos($haystack, $start);
        if ($from === false) {
            throw new RuntimeException('missing start marker: ' . trim($start));
        }
        $to = strpos($haystack, $end, $from);
        if ($to === false) {
            throw new RuntimeException('missing end marker: ' . trim($end));
        }
        return substr($haystack, $from, $to - $from);
    }
}
