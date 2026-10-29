<?php

/**
 * Guard against the M-CONSOLE-31 class (review console__newcomer): the real
 * newcomer guide (docs/clients/console.md) held the answers the README skips
 * — config-file layout, full keybinding tables, the render-mode reference,
 * genuine Troubleshooting — yet the README named it only as backticked
 * prose. Backticks are not links: GitHub arrival-readers (the 99%) had no
 * path into the manual, and the doc grew with no front door.
 *
 * Doctrine, per ScreenInventoryDocsTest: the docs surface is parsed, never
 * restated. Three laws here:
 *
 * 1. README.md carries a "## Documentation" front-door section naming the
 *    manual (red on the review-era file: the section did not exist).
 * 2. Every README mention of docs/clients/console.md sits inside a markdown
 *    link target (red on the review-era file: three backticked mentions,
 *    zero links).
 * 3. Every relative doc link in README.md and docs/clients/console.md
 *    resolves: the file exists, and any #anchor matches a heading of the
 *    target file under GitHub's slug rules. Anchor drift between the two
 *    pages (a manual section renamed without moving the README links) is
 *    exactly what a prose-only review never catches.
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Docs;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ManualLinkingDocsTest extends TestCase
{
    /** The newcomer guide the README must open a door into. */
    private const MANUAL = 'docs/clients/console.md';

    /** Surfaces whose relative links must all resolve. */
    private const LINKED_DOCS = ['README.md', self::MANUAL];

    /** The front-door heading the review asked for. */
    private const FRONT_DOOR_HEADING = "## Documentation\n";

    public function testTheReadmeCarriesADocumentationFrontDoor(): void
    {
        $readme = self::readRepoFile('README.md');

        self::assertStringContainsString(
            "\n" . self::FRONT_DOOR_HEADING,
            $readme,
            'README.md lost its "## Documentation" section — the manual '
            . '(docs/clients/console.md) is the newcomer guide and the README owes '
            . 'it a front door (review M-CONSOLE-31).'
        );

        $start = strpos($readme, self::FRONT_DOOR_HEADING);
        $rest = substr($readme, (int) $start + strlen(self::FRONT_DOOR_HEADING));
        $break = strpos($rest, "\n## ");
        $section = $break === false ? $rest : substr($rest, 0, $break);

        self::assertStringContainsString(
            'docs/clients/console.md',
            $section,
            'README.md "## Documentation" no longer links ' . self::MANUAL . '.'
        );
    }

    public function testEveryManualMentionInTheReadmeIsALink(): void
    {
        $readme = self::readRepoFile('README.md');

        // Strike every complete markdown link into the manual (label and
        // target together — a label may legitimately BE the path); whatever
        // mention survives was bare text. Backticks render as text on
        // GitHub — the unlinked-guide defect itself.
        $withoutLinks = preg_replace(
            '/\[[^\]]*\]\(' . preg_quote(self::MANUAL, '/') . '[^)]*\)/',
            '',
            $readme
        );

        self::assertIsString($withoutLinks);
        self::assertStringNotContainsString(
            self::MANUAL,
            $withoutLinks,
            'README.md mentions ' . self::MANUAL . ' as prose instead of a link; '
            . 'a GitHub reader cannot click a backtick (review M-CONSOLE-31).'
        );
    }

    /**
     * @dataProvider linkedDocProvider
     */
    public function testEveryRelativeLinkResolves(string $doc): void
    {
        $text = self::readRepoFile($doc);
        $dir = dirname($doc);

        preg_match_all('/\]\(([^)\s]+)\)/', $text, $links);

        $dead = [];
        foreach (array_unique($links[1]) as $target) {
            if (preg_match('#^(?:[a-z]+:|//|/|\#)#i', $target) === 1) {
                continue; // absolute URL, protocol-relative, root-, or in-page anchor
            }

            [$path, $anchor] = array_pad(explode('#', $target, 2), 2, null);
            $resolved = $path === '' ? $doc : $dir . '/' . $path;

            if (!is_file(self::root() . '/' . $resolved)) {
                $dead[] = $target . ' (file missing)';
                continue;
            }

            if ($anchor !== null && !in_array($anchor, self::headingSlugs($resolved), true)) {
                $dead[] = $target . ' (no such heading slug in ' . $resolved . ')';
            }
        }

        self::assertSame(
            [],
            $dead,
            $doc . ' carries dead relative link(s): ' . implode('; ', $dead)
            . ' — link integrity across README/manual is the M-CONSOLE-31 law.'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function linkedDocProvider(): array
    {
        $cases = [];
        foreach (self::LINKED_DOCS as $doc) {
            $cases[$doc] = [$doc];
        }

        return $cases;
    }

    /**
     * GitHub anchor slugs of every ATX heading in a repo doc: lowercase,
     * markdown/emphasis and code spans unwrapped, characters outside
     * [a-z0-9 -_] dropped, spaces collapsed to single hyphens.
     *
     * @return list<string>
     */
    private static function headingSlugs(string $relativeDoc): array
    {
        $slugs = [];
        foreach (explode("\n", self::readRepoFile($relativeDoc)) as $line) {
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $match) !== 1) {
                continue;
            }

            $title = $match[2];
            // Unwrap [text](url), `code`, **bold**, _em_ before slugifying.
            $title = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $title);
            $title = (string) preg_replace('/`([^`]*)`/', '$1', $title);
            $title = (string) preg_replace('/(\*\*|_)(.*?)\1/', '$2', $title);
            $slug = strtolower(rtrim((string) preg_replace('/[^a-z0-9 _-]+/', '', strtolower($title)), ' '));
            // GitHub maps every remaining space to a hyphen 1:1 (a stripped
            // "&" leaves two spaces — hence watching--ratings, not -ratings).
            $slug = str_replace(' ', '-', $slug);

            $slugs[] = $slug;
        }

        return $slugs;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function readRepoFile(string $relative): string
    {
        $path = self::root() . '/' . $relative;
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
