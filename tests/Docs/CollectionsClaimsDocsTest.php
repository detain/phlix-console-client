<?php

/**
 * Guard against the M-CONSOLE-22 round-2 class (review console__enduser): the
 * Collections row asserted "items are added/removed from the collection's
 * detail screen" — a flow that exists nowhere in the console. DetailScreen.php
 * carries zero collection/playlist membership surface; the `a`/`r` keys on
 * CollectionsScreen/PlaylistsScreen only raise the ADD_ITEM_NOT_IMPLEMENTED
 * notice (whose own "collection detail screen" pointer is an in-code mislabel,
 * ledger-noted); and ApiClient's membership calls (addPlaylistItem /
 * removePlaylistItem / createPlaylist) have no caller anywhere in src/.
 *
 * The same manual pass swept every Screens-table row's action claims against
 * its handler and corrected two more attribution phantoms: the **Series** row
 * named a screen that does not exist (the drill-down is nested Detail screens
 * in container mode) and the **Stats** row claimed the metrics HUD (Stats
 * screen is a read-only server-statistics view; the HUD is the palette's
 * Show/Hide metrics overlay).
 *
 * Doctrine, per ScreenInventoryDocsTest / DetailScreenClaimsDocsTest: pinned
 * row texts move only with a deliberate rotation, the phantom sentence is
 * banned outright, every promised surface has a producer regex over the real
 * source, the negative pins (no membership surface in DetailScreen, no
 * console caller of the membership API) redden the moment the code catches up
 * with — or contradicts — the manual, and the row-label set is pinned so an
 * orphan row (the Series failure mode) cannot reappear unannounced.
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Docs;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class CollectionsClaimsDocsTest extends TestCase
{
    /** The manual the rows live in. */
    private const MANUAL = 'docs/clients/console.md';

    /** Section marker of the screens table. */
    private const SCREENS_SECTION = '### Screens';

    /**
     * The four r2-swept row texts (whitespace-normalized). A reword reddens
     * here so the replacement sentence is re-walked against its handler in
     * the same change.
     */
    private const PINNED_ROWS = [
        'Collections' => 'Your collections; `Enter` opens one, `d` deletes it (typed `delete` confirms);'
            . ' `a`/`r` show a notice only — item add/remove is NOT implemented in the console'
            . ' (the notice\'s "collection detail screen" is a mislabel: the detail screen has no'
            . ' membership surface; collection contents are managed on the server\'s web portal)',
        'Playlists' => 'Your playlists — the same server-side list as Collections'
            . ' (`GET /api/v1/collections`), with the same keys including the notice-only `a`/`r` pair',
        'Series' => 'Not a screen of its own — `Enter` on a series opens nested **Detail** screens'
            . ' in container mode: a seasons grid, then each season\'s episodes grid,'
            . ' with breadcrumbs tracking the drill-down',
        'Stats' => 'Read-only server statistics screen — counts and health reported by the server'
            . ' via the admin API; the live metrics HUD is separate, toggled by the palette\'s'
            . ' Show/Hide metrics actions',
    ];

    /**
     * Every bolded first-cell label the Screens table may carry. The full
     * census (not just the class-mapped rows — Series is a flow, and the
     * guard's row→manual coverage in ScreenInventoryDocsTest cannot see an
     * orphan) so any added, renamed or dropped row reddens here and forces
     * the sweep to be re-run.
     *
     * @var list<string>
     */
    private const ROW_LABELS = [
        'Home',
        'Library',
        'Detail',
        'Series',
        'Music',
        'Music Artists',
        'Audiobooks',
        'Books',
        'Photos',
        'Player',
        'Cast',
        'Search',
        'Command palette',
        'Admin',
        'Settings',
        'Stats',
        'Server Setup',
        'Login',
        'Register',
        'Collections',
        'Playlists',
        'Favorites',
        'Recently Watched',
        'Recommendations',
        'Servers',
        'Shared With Me',
        'Invite Links',
        'Federation Shares',
    ];

    /**
     * Sentences that assert console membership editing — the exact phantom
     * M-CONSOLE-22 r1 proved false. Banned across the whole table.
     *
     * @var list<string>
     */
    private const BANNED_PATTERNS = [
        '/items are added\/removed/i',
    ];

    public function testTheSweptRowTextsArePinnedVerbatim(): void
    {
        foreach (self::PINNED_ROWS as $label => $pinned) {
            self::assertSame(
                $pinned,
                self::extractScreensRow($label),
                'The **' . $label . '** row moved. Re-verify it against its handlers'
                . ' (Collections/Playlists: the a/r arms in their screens; Series: DetailScreen'
                . ' container mode; Stats: StatsScreen + the App metrics-overlay toggle),'
                . ' then rotate the pin in the same change.'
            );
        }
    }

    public function testTheMembershipPhantomSentenceIsBannedFromTheTable(): void
    {
        $table = self::extractScreensTableBody();

        foreach (self::BANNED_PATTERNS as $pattern) {
            self::assertSame(
                0,
                preg_match_all($pattern, $table),
                'The Screens table asserts console membership editing again — the M-CONSOLE-22'
                . ' phantom. The console has no add/remove surface: a/r only raise the'
                . ' ADD_ITEM_NOT_IMPLEMENTED notice.'
            );
        }
    }

    public function testTheNoticeIsStillWhatAAndRProduceOnBothListScreens(): void
    {
        foreach (['CollectionsScreen', 'PlaylistsScreen'] as $class) {
            $source = self::readRepoFile('src/Screen/' . $class . '.php');

            self::assertSame(
                1,
                preg_match('/private const ADD_ITEM_NOT_IMPLEMENTED/', $source),
                $class . ' lost the ADD_ITEM_NOT_IMPLEMENTED notice the pinned rows describe.'
            );

            foreach (['a', 'r'] as $rune) {
                self::assertSame(
                    1,
                    preg_match("/rune === '$rune'[\s\S]{0,160}ADD_ITEM_NOT_IMPLEMENTED/", $source),
                    $class . ': the \'' . $rune . '\' arm no longer raises the notice — if a real'
                    . ' membership surface shipped, update the Collections/Playlists rows and'
                    . ' this pin together.'
                );
            }
        }
    }

    public function testDetailScreenStillCarriesNoMembershipSurface(): void
    {
        $source = self::readRepoFile('src/Screen/DetailScreen.php');

        self::assertSame(
            0,
            preg_match('/collection|playlist/i', $source),
            'DetailScreen.php gained collection/playlist wording. The r2 Collections row'
            . ' states the detail screen has no membership surface — if that became false,'
            . ' rewrite the row to the shipped flow and rotate the pin, never the other way round.'
        );
    }

    public function testTheMembershipApiMethodsStillHaveNoConsoleCaller(): void
    {
        $definer = 'src/Api/ApiClient.php';
        $offenders = [];

        foreach (self::srcPhpFiles() as [ $relative, $source ]) {
            if ($relative === $definer) {
                continue;
            }

            if (preg_match('/\b(addPlaylistItem|removePlaylistItem|createPlaylist)\s*\(/', $source) === 1) {
                $offenders[] = $relative;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'src/ outside ApiClient.php now calls the membership API — the console grew the'
            . ' surface the manual says is not implemented. Update the Collections/Playlists rows'
            . ' (and their pins / the notice tests) in the same change.'
        );
    }

    public function testTheSweptHandlersStillProduceWhatTheRowsPromise(): void
    {
        $detail = self::readRepoFile('src/Screen/DetailScreen.php');
        self::assertSame(
            1,
            preg_match('/container mode/i', $detail),
            'The Series row credits DetailScreen container mode for the seasons/episodes'
            . ' drill-down; DetailScreen.php no longer mentions it.'
        );
        self::assertSame(
            1,
            preg_match('/implements Breadcrumbed/', $detail),
            'The Series row promises breadcrumbs; DetailScreen no longer implements Breadcrumbed.'
        );

        $stats = self::readRepoFile('src/Screen/StatsScreen.php');
        self::assertMatchesRegularExpression(
            '/StatsLoadedMsg/',
            $stats,
            'The Stats row describes the fetched server-statistics view; StatsScreen no longer handles StatsLoadedMsg.'
        );

        $app = self::readRepoFile('src/App.php');
        self::assertSame(
            1,
            preg_match('/new StatsScreen\(new AdminClient/', $app),
            'The Stats row names the admin-API route; App.php no longer builds StatsScreen over AdminClient.'
        );
        self::assertSame(
            1,
            preg_match('/metricsVisible \? \'Hide metrics\' : \'Show metrics\'/', $app),
            'The Stats row points the HUD at the palette Show/Hide metrics toggle; that action moved.'
        );
    }

    public function testTheScreensTableCarriesExactlyTheSweptLabelSet(): void
    {
        $labels = array_keys(self::extractScreensRows());

        $missing = array_values(array_diff(self::ROW_LABELS, $labels));
        $unknown = array_values(array_diff($labels, self::ROW_LABELS));

        self::assertSame(
            [],
            $missing,
            'Rows vanished from the Screens table: ' . implode(', ', $missing) . '.'
        );
        self::assertSame(
            [],
            $unknown,
            'The Screens table gained row labels with no swept handler behind them: '
            . implode(', ', $unknown) . '. Document the real screen (add it to ScreenInventoryDocsTest'
            . ' census territory) or fold it into the row that owns the flow.'
        );
    }

    /** Row text of a bolded **label** in the docs/clients/console.md "Screens" table. */
    private static function extractScreensRow(string $label): string
    {
        $rows = self::extractScreensRows();
        if (!isset($rows[$label])) {
            throw new RuntimeException(
                'The Screens table has no **' . $label . '** row; did it move out of ' . self::MANUAL . '?'
            );
        }

        return $rows[$label];
    }

    /**
     * The "Screens" table rows: bolded first-cell label mapped to the
     * whitespace-normalized row text (same parsing idiom as the sibling docs
     * suites).
     *
     * @return array<string, string>
     */
    private static function extractScreensRows(): array
    {
        $rows = [];
        foreach (explode("\n", self::extractScreensTableBody()) as $line) {
            if (preg_match('/^\|\s*\*\*(.+?)\*\*\s*\|(.*)$/', trim($line), $match) === 1) {
                $rows[$match[1]] = (string) preg_replace('/\s+/', ' ', trim($match[2], " \t|"));
            }
        }

        if ($rows === []) {
            throw new RuntimeException('The Screens table parsed to zero rows; did its idiom change?');
        }

        return $rows;
    }

    private static function extractScreensTableBody(): string
    {
        $doc = self::readRepoFile(self::MANUAL);
        $start = strpos($doc, self::SCREENS_SECTION);
        if ($start === false) {
            throw new RuntimeException(
                'The "' . self::SCREENS_SECTION . '" section moved out of ' . self::MANUAL . '.'
            );
        }

        $body = substr($doc, (int) $start);
        $end = strpos($body . "\n", "\n## ");

        return $end === false ? $body : substr($body, 0, (int) $end);
    }

    /**
     * Every .php file under src/ as [repo-relative path, contents].
     *
     * @return iterable<string, array{0: string, 1: string}>
     *
     * @throws RuntimeException
     */
    private static function srcPhpFiles(): iterable
    {
        $root = dirname(__DIR__, 2) . '/src';
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($walk as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = (string) substr($file->getPathname(), (int) strlen(dirname(__DIR__, 2)) + 1);
            $contents = file_get_contents($file->getPathname());
            if (!is_string($contents)) {
                throw new RuntimeException('Unable to read ' . $relative . '.');
            }

            yield $relative => [ $relative, $contents ];
        }
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
