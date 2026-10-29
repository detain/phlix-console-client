<?php

/**
 * Guard against the M-CONSOLE-22 class (review console__enduser): the live
 * screen registry in src/App.php had grown far past the manual's "Screens"
 * table — ten registered screens (Collections, Playlists, Favorites, the
 * watch-history screen, Recommendations, Servers, Shared With Me, Invite
 * Links, Federation Shares, Register) appeared in no row at all, and the
 * Command palette row still advertised the Phase-5-era shortlist
 * ("search, jump, settings, stats, admin") while staticActions() ships
 * more than twenty labels.
 *
 * Doctrine, per PlayerKeyDocsTest / WatchStateLoopDocsTest /
 * TokenFileNameDocsTest: the code side is parsed with token_get_all —
 * never restated from prose — and pinned against the docs surface. Here
 * the registry itself is re-derived AT TEST RUNTIME from the screens App
 * instantiates (`new XScreen(` plus the three `XScreen::create(` boot
 * forms), so a screen that gains or loses an instantiation point, or a
 * palette action added to staticActions(), reddens this suite until the
 * manual and the pinned lists move in the same change.
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Docs;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ScreenInventoryDocsTest extends TestCase
{
    /** Source of truth: the App — screens it instantiates and actions it offers. */
    private const APP_SOURCE = 'src/App.php';

    /** Section marker of the screens table in docs/clients/console.md. */
    private const SCREENS_SECTION = '### Screens';

    /** Row label of the palette entry inside that table. */
    private const PALETTE_ROW = 'Command palette';

    /** Row label of the admin entry inside that table. */
    private const ADMIN_ROW = 'Admin';

    /**
     * Every screen class App instantiates, in the parser's sorted order.
     * Rotating this list is the drift ceremony: add a screen to App and
     * this pin reddens with instructions, exactly like the docs keys of
     * PlayerKeyDocsTest.
     *
     * @var list<string>
     */
    private const LIVE_SCREENS = [
        'AdminAuthProvidersScreen',
        'AdminBackupScreen',
        'AdminDashboardScreen',
        'AdminDlnaScreen',
        'AdminDuplicatesScreen',
        'AdminFilesystemScreen',
        'AdminLibrariesScreen',
        'AdminLiveTvScreen',
        'AdminLogsScreen',
        'AdminMenuScreen',
        'AdminMetricsScreen',
        'AdminPluginCatalogScreen',
        'AdminPluginDetailScreen',
        'AdminPluginUpdateScreen',
        'AdminPluginsScreen',
        'AdminRemoteAccessScreen',
        'AdminServerRestartScreen',
        'AdminSettingsScreen',
        'AdminTranscodingScreen',
        'AdminUserProfilesScreen',
        'AdminUsersScreen',
        'AdminWatchHistoryScreen',
        'AdminWebhooksScreen',
        'AlbumScreen',
        'ArtistAlbumsScreen',
        'AudiobookDetailScreen',
        'AudiobooksScreen',
        'BookDetailScreen',
        'BooksScreen',
        'BrowseScreen',
        'CastScreen',
        'CollectionsScreen',
        'DetailScreen',
        'FavoritesScreen',
        'FederationSharesScreen',
        'InviteLinksScreen',
        'LibraryScreen',
        'LoginScreen',
        'MusicArtistsScreen',
        'MusicScreen',
        'ParentalControlsScreen',
        'PhotoAlbumScreen',
        'PhotoViewerScreen',
        'PhotosScreen',
        'PlayerScreen',
        'PlaylistsScreen',
        'RecommendationsScreen',
        'RegisterScreen',
        'SearchScreen',
        'ServerScreen',
        'ServersScreen',
        'SettingsScreen',
        'SharedWithMeScreen',
        'StatsScreen',
        'WatchHistoryScreen',
    ];

    /**
     * Which "Screens" table row covers each live screen. Sub-screens fold
     * into the row a user navigates by (the album list belongs to the
     * **Music** row; every Admin* surface and the parental-controls form
     * belong to the **Admin** row, whose text must then name them all —
     * asserted by ADMIN_ROW_SURFACES).
     *
     * @var array<string, string>
     */
    private const SCREEN_ROWS = [
        'AdminAuthProvidersScreen' => 'Admin',
        'AdminBackupScreen' => 'Admin',
        'AdminDashboardScreen' => 'Admin',
        'AdminDlnaScreen' => 'Admin',
        'AdminDuplicatesScreen' => 'Admin',
        'AdminFilesystemScreen' => 'Admin',
        'AdminLibrariesScreen' => 'Admin',
        'AdminLiveTvScreen' => 'Admin',
        'AdminLogsScreen' => 'Admin',
        'AdminMenuScreen' => 'Admin',
        'AdminMetricsScreen' => 'Admin',
        'AdminPluginCatalogScreen' => 'Admin',
        'AdminPluginDetailScreen' => 'Admin',
        'AdminPluginUpdateScreen' => 'Admin',
        'AdminPluginsScreen' => 'Admin',
        'AdminRemoteAccessScreen' => 'Admin',
        'AdminServerRestartScreen' => 'Admin',
        'AdminSettingsScreen' => 'Admin',
        'AdminTranscodingScreen' => 'Admin',
        'AdminUserProfilesScreen' => 'Admin',
        'AdminUsersScreen' => 'Admin',
        'AdminWatchHistoryScreen' => 'Admin',
        'AdminWebhooksScreen' => 'Admin',
        'ParentalControlsScreen' => 'Admin',
        'AlbumScreen' => 'Music',
        'ArtistAlbumsScreen' => 'Music Artists',
        'AudiobookDetailScreen' => 'Audiobooks',
        'AudiobooksScreen' => 'Audiobooks',
        'BookDetailScreen' => 'Books',
        'BooksScreen' => 'Books',
        'BrowseScreen' => 'Home',
        'CastScreen' => 'Cast',
        'CollectionsScreen' => 'Collections',
        'DetailScreen' => 'Detail',
        'FavoritesScreen' => 'Favorites',
        'FederationSharesScreen' => 'Federation Shares',
        'InviteLinksScreen' => 'Invite Links',
        'LibraryScreen' => 'Library',
        'LoginScreen' => 'Login',
        'MusicArtistsScreen' => 'Music Artists',
        'MusicScreen' => 'Music',
        'PhotoAlbumScreen' => 'Photos',
        'PhotoViewerScreen' => 'Photos',
        'PhotosScreen' => 'Photos',
        'PlayerScreen' => 'Player',
        'PlaylistsScreen' => 'Playlists',
        'RecommendationsScreen' => 'Recommendations',
        'RegisterScreen' => 'Register',
        'SearchScreen' => 'Search',
        'ServerScreen' => 'Server Setup',
        'ServersScreen' => 'Servers',
        'SettingsScreen' => 'Settings',
        'SharedWithMeScreen' => 'Shared With Me',
        'StatsScreen' => 'Stats',
        'WatchHistoryScreen' => 'Recently Watched',
    ];

    /**
     * The ten rows the review proved missing (console__enduser M22), by
     * the exact table labels they now carry. Permanent regression pin:
     * deleting one of these rows reddens here even if the class map is
     * edited alongside.
     *
     * @var list<string>
     */
    private const TEN_REVIEW_SCREENS = [
        'Collections',
        'Playlists',
        'Favorites',
        'Recently Watched',
        'Recommendations',
        'Servers',
        'Shared With Me',
        'Invite Links',
        'Federation Shares',
        'Register',
    ];

    /**
     * Phrases the **Admin** row must name — one per admin-family screen
     * (case-insensitive substrings of the row text).
     *
     * @var list<string>
     */
    private const ADMIN_ROW_SURFACES = [
        'dashboard',
        'users',
        'auth providers',
        'backups',
        'plugins',
        'logs',
        'metrics',
        'server settings',
        'transcoding',
        'libraries',
        'filesystem',
        'DLNA',
        'port-forward',
        'server restart',
        'live TV',
        'parental',
        'watch history',
        'webhooks',
        'duplicates',
    ];

    /**
     * Every literal label App hands to a PaletteAction (static actions in
     * sorted order, plus the dynamic `Go to ` library-jump prefix), pinned
     * so a new or renamed action reddens the census and its docs row
     * together.
     *
     * @var list<string>
     */
    private const PALETTE_LABELS = [
        'Admin',
        'Collections',
        'Favorites',
        'Federation Shares',
        'Go to',
        'Hide metrics',
        'Home',
        'Invite Links',
        'Log out',
        'Music Artists',
        'Pause playback',
        'Photos',
        'Playlists',
        'Quit',
        'Resume playback',
        'Search',
        'Servers',
        'Settings',
        'Shared With Me',
        'Show metrics',
        'Stats',
        'Stop playback',
        'Watch History',
    ];

    public function testTheLiveScreenRegistryCensusMatchesThePinnedList(): void
    {
        $live = self::extractLiveScreenClasses();
        sort($live);

        self::assertSame(
            self::LIVE_SCREENS,
            $live,
            'src/App.php instantiates a different set of screens than the pinned registry. '
            . 'A new screen must also gain a docs/clients/console.md "Screens" row (via '
            . 'ScreenInventoryDocsTest::SCREEN_ROWS) in the same change; a removed one must '
            . 'lose its row here and in the manual.'
        );
    }

    public function testTheScreenRowMapCoversExactlyTheLiveRegistry(): void
    {
        $mapped = array_keys(self::SCREEN_ROWS);
        sort($mapped);

        self::assertSame(
            self::LIVE_SCREENS,
            $mapped,
            'SCREEN_ROWS and the live registry drifted apart; every screen App '
            . 'instantiates needs exactly one row mapping.'
        );
    }

    public function testEveryLiveScreenClassHasAnImplementationFile(): void
    {
        $missing = [];
        foreach (self::LIVE_SCREENS as $class) {
            if (!is_file(dirname(__DIR__, 2) . '/src/Screen/' . $class . '.php')) {
                $missing[] = $class;
            }
        }

        self::assertSame([], $missing, 'Pinned screens with no src/Screen class file: ' . implode(', ', $missing));
    }

    public function testEveryLiveScreenIsCoveredByAScreensTableRow(): void
    {
        $rows = self::extractScreensTable();

        $missing = [];
        foreach (self::SCREEN_ROWS as $class => $label) {
            if (!isset($rows[$label])) {
                $missing[] = $class . ' -> **' . $label . '**';
            }
        }

        self::assertSame(
            [],
            $missing,
            'docs/clients/console.md "Screens" table has no row for: ' . implode('; ', $missing)
            . '. This is the M-CONSOLE-22 defect — document the screen.'
        );
    }

    public function testTheTenPreviouslyUndocumentedScreensEachCarryTheirOwnRow(): void
    {
        $rows = self::extractScreensTable();

        $missing = array_values(array_diff(self::TEN_REVIEW_SCREENS, array_keys($rows)));

        self::assertSame(
            [],
            $missing,
            'The screens console__enduser M22 proved missing lost their rows again: '
            . implode(', ', $missing)
        );
    }

    public function testTheAdminRowNamesEveryAdminSurface(): void
    {
        $rows = self::extractScreensTable();
        self::assertArrayHasKey(self::ADMIN_ROW, $rows, 'The **Admin** row vanished from the Screens table.');

        $text = mb_strtolower($rows[self::ADMIN_ROW]);
        $missing = [];
        foreach (self::ADMIN_ROW_SURFACES as $surface) {
            if (!str_contains($text, mb_strtolower($surface))) {
                $missing[] = $surface;
            }
        }

        self::assertSame(
            [],
            $missing,
            'The **Admin** row no longer names: ' . implode(', ', $missing)
            . ' — every admin-family screen must be findable through it.'
        );
    }

    public function testThePaletteLabelCensusMatchesThePinnedList(): void
    {
        $labels = self::extractPaletteActionLabels();
        sort($labels);

        self::assertSame(
            self::PALETTE_LABELS,
            $labels,
            'src/App.php offers a different set of palette labels than the pinned census. '
            . 'Any add/rename must move PALETTE_LABELS and the manual\'s Command palette row together.'
        );
    }

    public function testEveryPaletteActionLabelIsDocumentedInTheCommandPaletteRow(): void
    {
        $rows = self::extractScreensTable();
        self::assertArrayHasKey(self::PALETTE_ROW, $rows, 'The Command palette row vanished from the Screens table.');

        $row = $rows[self::PALETTE_ROW];
        $missing = [];
        foreach (self::PALETTE_LABELS as $label) {
            if (!str_contains($row, $label)) {
                $missing[] = $label;
            }
        }

        self::assertSame(
            [],
            $missing,
            'The Command palette row no longer carries: ' . implode(', ', $missing)
            . ' — every shipped action label belongs in it.'
        );
    }

    /**
     * Screen classes App instantiates: an uppercase `*Screen` T_STRING
     * directly invoked — `new XScreen(` or the boot-time `XScreen::create(`
     * forms (Login, Register, Servers, Server). `use` statements, instanceof
     * arms, doc prose and `->popScreen()` helpers are shape-excluded, and
     * comment tokens are invisible to the walk by construction.
     *
     * @return list<string>
     */
    private static function extractLiveScreenClasses(): array
    {
        $tokens = token_get_all(self::readRepoFile(self::APP_SOURCE));
        $screens = [];

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            $name = $token[1];
            if (!str_ends_with($name, 'Screen') || !ctype_upper($name[0])) {
                continue;
            }

            $previous = self::previousSignificant($tokens, (int) $index);
            if (is_array($previous) && ($previous[0] === T_OBJECT_OPERATOR || $previous[0] === T_DOUBLE_COLON)) {
                continue;
            }

            $next = self::nextSignificant($tokens, (int) $index);
            if ($next === '(' || (is_array($next) && $next[0] === T_DOUBLE_COLON)) {
                $screens[$name] = true;
            }
        }

        return array_keys($screens);
    }

    /**
     * Literal first-argument strings of every `new PaletteAction(` in App:
     * one label for plain arms, both arms of the visibility/session
     * ternaries (`'Show metrics' : 'Hide metrics'` style). The dynamic
     * library jump contributes its `'Go to '` prefix.
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

            // Walk from the opening paren (depth 0 until it is consumed),
            // collecting string literals up to the first top-level comma
            // (the label argument boundary): plain arms yield one label,
            // ternaries yield two.
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

    /**
     * The docs/clients/console.md "Screens" table: row label (the bolded
     * first cell) mapped to the full row text.
     *
     * @return array<string, string>
     */
    private static function extractScreensTable(): array
    {
        $doc = self::readRepoFile('docs/clients/console.md');
        $start = strpos($doc, self::SCREENS_SECTION);
        if ($start === false) {
            throw new RuntimeException(
                'The "' . self::SCREENS_SECTION . '" section moved out of docs/clients/console.md.'
            );
        }

        $body = substr($doc, (int) $start);
        $end = strpos($body . "\n", "\n## ");

        $rows = [];
        foreach (explode("\n", $end === false ? $body : substr($body, 0, (int) $end)) as $line) {
            if (preg_match('/^\|\s*\*\*(.+?)\*\*\s*\|(.*)$/', trim($line), $match) === 1) {
                $rows[$match[1]] = $match[2];
            }
        }

        if ($rows === []) {
            throw new RuntimeException('The Screens table parsed to zero rows; did its idiom change?');
        }

        return $rows;
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
