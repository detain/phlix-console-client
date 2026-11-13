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
        'KeyHelpScreen',
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
        'KeyHelpScreen' => 'Command palette',
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
     * Every palette action's LABEL SOURCE in App, in sorted order. Post-CU-009 the
     * labels are `Lang::t('palette.<name>')` keys (the primary navigation surface
     * is translated like the rest of the app); the census now pins the translation
     * KEY each arm renders from, so a new/renamed action still reddens the census
     * and its docs row together. The library jump contributes its
     * `palette.go_to` key; the resolved English value (via en.php) is what the
     * Command palette row must name.
     *
     * @var list<string>
     */
    private const PALETTE_LABEL_KEYS = [
        'palette.admin',
        'palette.collections',
        'palette.favorites',
        'palette.federation_shares',
        'palette.go_to',
        'palette.hide_metrics',
        'palette.home',
        'palette.invite_links',
        'palette.keys',
        'palette.music_artists',
        'palette.pause_playback',
        'palette.photos',
        'palette.playlists',
        'palette.quit',
        'palette.resume_playback',
        'palette.search',
        'palette.servers',
        'palette.settings',
        'palette.shared_with_me',
        'palette.show_metrics',
        'palette.sign_out',
        'palette.stats',
        'palette.stop_playback',
        'palette.watch_history',
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
        $labels = self::extractPaletteActionKeys();
        sort($labels);

        self::assertSame(
            self::PALETTE_LABEL_KEYS,
            $labels,
            'src/App.php offers a different set of palette label keys than the pinned census. '
            . 'Any add/rename must move PALETTE_LABEL_KEYS and the manual\'s Command palette row together.'
        );
    }

    public function testEveryPaletteActionLabelIsDocumentedInTheCommandPaletteRow(): void
    {
        $rows = self::extractScreensTable();
        self::assertArrayHasKey(self::PALETTE_ROW, $rows, 'The Command palette row vanished from the Screens table.');

        $row = $rows[self::PALETTE_ROW];
        $english = require __DIR__ . '/../../resources/lang/en.php';
        $missing = [];
        foreach (self::PALETTE_LABEL_KEYS as $key) {
            // The row documents the English label each action renders (the
            // library jump as its bare 'Go to' prefix, placeholder stripped).
            $label = rtrim((string) ($english[$key] ?? $key));
            if (str_ends_with($label, ' {name}')) {
                $label = rtrim(substr($label, 0, -strlen(' {name}')));
            }

            if (!str_contains($row, $label)) {
                $missing[] = "$key => $label";
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
     * The translation key behind every `new PaletteAction(` label in App:
     * the first-argument `Lang::t('palette.<name>')` key — one for plain
     * arms, both arms of the visibility/session ternaries, and the
     * library jump's `palette.go_to`. A raw literal label (pre-CU-009
     * shape or a planted one) yields no key and reddens the census:
     * the palette stays translated by law.
     *
     * @return list<string>
     */
    private static function extractPaletteActionKeys(): array
    {
        $tokens = token_get_all(self::readRepoFile(self::APP_SOURCE));
        $keys = [];
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

            // Walk from the opening paren (depth 0 until it is consumed) up to
            // the first top-level comma (the label-argument boundary), reading
            // each `Lang::t(` call site: 'Lang' T_DOUBLE_COLON 't' '(' then the
            // first string literal is the key. Token ARRAYS are compared by
            // their [0] id — never as strings (estate T_DOUBLE_COLON scanner
            // class, cure precedent a3f500d1).
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

                if (!is_array($current) || $current[0] !== T_STRING || $current[1] !== 't') {
                    continue;
                }

                $owner = self::previousSignificant($tokens, $cursor);
                if (!is_array($owner) || $owner[0] !== T_DOUBLE_COLON) {
                    continue;
                }

                $ownerIndex = self::previousSignificantIndex($tokens, $cursor);
                $namespace = $ownerIndex === null ? null : self::previousSignificant($tokens, $ownerIndex);
                if (!is_array($namespace) || $namespace[0] !== T_STRING || $namespace[1] !== 'Lang') {
                    continue;
                }

                $callOpenIndex = self::nextSignificantIndex($tokens, $cursor);
                if ($callOpenIndex === null || $tokens[$callOpenIndex] !== '(') {
                    continue;
                }

                $keyToken = self::nextSignificant($tokens, $callOpenIndex);
                if (is_array($keyToken) && $keyToken[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $keys[] = trim(substr($keyToken[1], 1, -1));
                }
            }
        }

        return array_values(array_unique($keys));
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

    /** Index of the next non-trivia token after $index, or null at EOF. */
    private static function nextSignificantIndex(array $tokens, int $index): ?int
    {
        $count = count($tokens);
        for ($cursor = $index + 1; $cursor < $count; $cursor++) {
            $token = $tokens[$cursor];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $cursor;
        }

        return null;
    }

    /** Index of the previous non-trivia token before $index, or null at BOF. */
    private static function previousSignificantIndex(array $tokens, int $index): ?int
    {
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            $token = $tokens[$cursor];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $cursor;
        }

        return null;
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
