<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\AuthUser;
use Phlix\Console\Api\Dto\Library;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Msg\BrowseFavoritesLoadedMsg;
use Phlix\Console\Msg\LibrariesLoadedMsg;
use Phlix\Console\Screen\BrowseScreen;
use Phlix\Console\Store\FavoritesStore;
use Phlix\Console\Store\LibrariesStore;
use Phlix\Console\Store\MediaStore;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Image\Mosaic;

/**
 * w13L8-R4 [review console chain ed4423b, MAJOR-1]: the zero-card favorites
 * state against the no-dead-keys law — 575b338's own words: "a hint that
 * lies about a key is worse than no hint at all".
 *
 * The reviewer's EXECUTED probe (cols 120 rows 40, "same shape holds @168"):
 * a zero-library account cycles 'a' to the Favorites filter and the favorites
 * response is EMPTY. onFavorites() still raised a card-less rail, so
 * railIds()===["favorites"] bypassed CU-049's honest empty arm, focus
 * defaulted to RAILS, and railsHint() published "↑↓ rails / ←→ items /
 * ⏎ open / s shuffle / u up-next / [ ] page" over cards that do not exist —
 * every press a null-cmd over a byte-identical view (6/6 inert, executed by
 * the review). The cure mirrors the sibling doctrine (onContinueWatching /
 * onMostWatched / onNextUp: "Empty: render nothing (not an empty box)"), so
 * the state falls into the honest empty arm instead of advertising keys it
 * can never serve.
 *
 * w13L8-R5 [review w13L8round4, MAJOR-R4-1]: that cure widened one arm's
 * reach past its truth. An account WITH libraries cycling to Favorites and
 * getting an empty answer fell into the SAME arm and printed "No libraries
 * yet." — a false statement about the user's own data (the 'a' step clears
 * the library rails before fetching; librariesLoaded is true and the shelves
 * exist) — plus the sidebar the pre-R4 box carried visibly. Two laws now hold
 * this state, one test each: (Law B) no empty-state message may misdescribe
 * WHY the state is empty — the libraries-account landing names Favorites,
 * never the libraries-empty copy, and keeps the sidebar; (Law A, permanent,
 * shape-agnostic) no hint row may advertise a key the current state cannot
 * serve — the probe below re-runs the reviewer's six tokens over the restored
 * card-less favorites box, where railsHint() publishes only the four arms
 * (Tab / a / R / q) that genuinely deliver with zero cards. A zero-LIBRARIES
 * account keeps R4's honest empty-arm landing unchanged.
 *
 * w13L8-R6 [review w13L8round5, minors R5-1..R5-4 — the same two laws, four
 * states deeper]: (R5-1) a POPULATED favorites answer over a stale screen
 * cursor rendered NO box — the raise collapses ids to ['favorites'] and only
 * the empty branch clamped, so view() sliced past the single rail while the
 * FULL hint advertised five unservable keys. (R5-2) Law B survived on the
 * cycle-OFF leg: 'a' back to All for an account WITH shelves fell into the
 * empty arm and printed "No libraries yet." over the shelves the Favorites
 * step had hidden. (R5-3) Law A survived in the SIDEBAR zone: the card-less
 * favorites box keeps the stale one-entry sidebar, whose hint still published
 * '↑↓ library' (clamps over a single entry) and '⏎ open' (railById over the
 * cleared libraryRails — the null-cmd the reviewer executed). (R5-4) the root
 * card: railsHint()'s trim was a binary ANY-cards gate — a populated
 * single-rail favorites box advertised ↑↓ (one rail clamps), ←→ (one card
 * clamps), u (no up-next rail) and [ ] (paging is the Most Watched rail's).
 * The cure makes both hint rows per-segment honest, single-sourced from the
 * arms in handleRailsKey()/handleSidebarKey().
 */
final class BrowseScreenZeroCardFavoritesTest extends TestCase
{
    /**
     * The probe keys of the reviewer's conviction: the hint token that
     * publishes the arm, and the keystroke that must then be served
     * ("[ ]  page" stands for both paging keys and is pressed twice). The
     * R6 round adds the SIDEBAR-zone token so every audit in this file
     * covers both hint rows (rails hints never contain that substring, so
     * the pre-existing cells are unaffected). NOTE the audit convention:
     * pair tokens ('↑↓', '←→', '[ ]') press ONE forward direction and the
     * audited screen must not sit at that direction's clamp edge.
     *
     * @var list<array{0: string, 1: KeyType, 2: string}>
     */
    private const PROBES = [
        ['↑↓  rails', KeyType::Down, ''],
        ['←→  items', KeyType::Right, ''],
        ['⏎  open', KeyType::Enter, ''],
        ['s  shuffle', KeyType::Char, 's'],
        ['u  up-next', KeyType::Char, 'u'],
        ['[ ]  page', KeyType::Char, '['],
        ['[ ]  page', KeyType::Char, ']'],
        ['↑↓  library', KeyType::Down, ''],
    ];

    private function screenWith(FakeTransport $transport, int $cols = 168): BrowseScreen
    {
        $api = new ApiClient('https://srv', $transport);

        return new BrowseScreen(
            AuthUser::fromArray(['id' => 'u', 'username' => 'joe', 'display_name' => 'Joe Huss']),
            new LibrariesStore($api),
            new MediaStore($api),
            new FavoritesStore($api),
            new PosterLoader(Mosaic::halfBlock()),
            'https://srv',
            cols: $cols,
            rows: 40,
        );
    }

    /**
     * The probe state, built through the REAL key path (never by poking the
     * handler): zero libraries ANSWERED (CU-049's state, not the spinner),
     * 'a' cycled to Favorites, the favorites answer EMPTY (or populated for
     * the preservation pin). The fetch pin proves the cycle reached its
     * fetching step — the Favorites step is the only one that queries in
     * this state, exactly as CU-049 round-2's test pins. $cols widens the
     * frame for the R6 populated audits, which need the FULL hint row
     * published intact to convict it (Chrome's fitHint drops words from the
     * middle at narrower widths).
     */
    private function favoritesCycledWith(FakeTransport $transport, int $cols = 168): BrowseScreen
    {
        [$screen] = $this->screenWith($transport, $cols)->update(new LibrariesLoadedMsg([]));
        [$screen] = $screen->update(new KeyMsg(KeyType::Char, 'a')); // All → Watched
        [$screen] = $screen->update(new KeyMsg(KeyType::Char, 'a')); // Watched → Unwatched
        [$screen, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'a')); // → Favorites
        self::assertInstanceOf(\Closure::class, $cmd, 'the cycle must REACH the Favorites step');

        foreach ($this->runBatch($cmd) as $msg) {
            [$screen] = $screen->update($msg);
        }

        self::assertSame(1, $transport->requestCount(), 'only the Favorites step queries — the answer arrived');

        return $screen;
    }

    /**
     * The R5 probe state through the same real 'a' key path, over an account
     * WITH libraries: one Movies shelf ANSWERED (its media batch is dropped —
     * the Favorites step clears the library rails anyway, the injection
     * idiom BrowseScreenTest established), then 'a' cycles to Favorites and
     * the favorites answer is whatever the transport carries. requestCount()
     * 1 pins the answer that arrived is the Favorites one.
     */
    private function librariesFavoritesCycledWith(FakeTransport $transport): BrowseScreen
    {
        [$screen] = $this->screenWith($transport)->update(new LibrariesLoadedMsg([
            Library::fromArray(['id' => 'lib-a', 'name' => 'Movies', 'type' => 'movie']),
        ]));
        [$screen] = $screen->update(new KeyMsg(KeyType::Char, 'a')); // All → Watched
        [$screen] = $screen->update(new KeyMsg(KeyType::Char, 'a')); // Watched → Unwatched
        [$screen, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'a')); // → Favorites
        self::assertInstanceOf(\Closure::class, $cmd, 'the cycle must REACH the Favorites step');

        foreach ($this->runBatch($cmd) as $msg) {
            [$screen] = $screen->update($msg);
        }

        self::assertSame(1, $transport->requestCount(), 'only the Favorites step queries — the answer arrived');

        return $screen;
    }

    /**
     * The R5-1 probe state [review w13L8 round-5]: SIX shelves answered, the
     * cursor scrolled deep into them (Down x3 — rows 40 puts it at rail 3),
     * then 'a' cycles to Favorites and the answer is POPULATED. The raise
     * collapses ids to ['favorites'] with the cursor stranded outside the
     * set: the un-clamped populated branch renders no box and publishes the
     * FULL hint over keys the stale focus cannot serve.
     */
    private function populatedFavoritesOverStaleCursorWith(FakeTransport $transport): BrowseScreen
    {
        $libraries = [];
        for ($i = 1; $i <= 6; $i++) {
            $libraries[] = Library::fromArray(['id' => 'lib-' . $i, 'name' => 'Shelf ' . $i, 'type' => 'movie']);
        }

        [$screen] = $this->screenWith($transport, 240)->update(new LibrariesLoadedMsg($libraries));
        [$screen] = $screen->update(new KeyMsg(KeyType::Down));
        [$screen] = $screen->update(new KeyMsg(KeyType::Down));
        [$screen] = $screen->update(new KeyMsg(KeyType::Down));

        [$screen] = $screen->update(new KeyMsg(KeyType::Char, 'a')); // All → Watched
        [$screen] = $screen->update(new KeyMsg(KeyType::Char, 'a')); // Watched → Unwatched
        [$screen, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'a')); // → Favorites
        self::assertInstanceOf(\Closure::class, $cmd, 'the cycle must REACH the Favorites step');

        foreach ($this->runBatch($cmd) as $msg) {
            [$screen] = $screen->update($msg);
        }

        self::assertSame(1, $transport->requestCount(), 'only the Favorites step queries — the answer arrived');

        return $screen;
    }

    /**
     * The shape-agnostic Law A audit: every probe token the published view
     * ADVERTISES must be SERVED — a null-cmd over a byte-identical view is
     * the advertised-dead shape the reviewer executed.
     *
     * @return list<string> the convicted tokens (empty means the hint lies about nothing)
     */
    private function advertisedDeadTokens(BrowseScreen $screen): array
    {
        $view = $screen->view();

        $advertisedDead = [];
        foreach (self::PROBES as [$token, $type, $rune]) {
            if (!str_contains($view, $token)) {
                continue; // not advertised in this shape — honest either way
            }

            [$next, $cmd] = $screen->update(new KeyMsg($type, $rune));
            if ($cmd === null && $next->view() === $view) {
                $advertisedDead[] = $token;
            }
        }

        return array_values(array_unique($advertisedDead));
    }

    public function testTheZeroCardFavoritesStateNeverAdvertisesAKeyItCannotServe(): void
    {
        // THE CONVICTION [review ed4423b MAJOR-1]. Shape-agnostic by law:
        // whether the cure falls into the empty arm or trims the hint, every
        // token this state PUBLISHES must be DELIVERED — a null-cmd over a
        // byte-identical view is the advertised-dead shape the reviewer
        // executed. Red at ed4423b with the probe's tokens in the verdict.
        $transport = (new FakeTransport())->json(200, ['items' => [], 'total' => 0, 'limit' => 18, 'offset' => 0]);
        $screen = $this->favoritesCycledWith($transport);

        self::assertSame(
            [],
            $this->advertisedDeadTokens($screen),
            'the zero-card favorites hint lies about these keys (press = null cmd, view byte-identical)',
        );
    }

    public function testAnEmptyFavoritesResponseOnAZeroLibraryAccountRaisesNoRail(): void
    {
        // Sibling-doctrine mirror of testEmptyMostWatchedAddsNoRail and
        // testEmptyContinueWatchingAddsNoRail — for the ZERO-LIBRARIES half
        // of the split law [w13L8-R5]: this account's emptiness IS CU-049's
        // state, so the answer renders nothing and the view names the real
        // reason through the honest empty arm. The libraries-account half
        // (the box that names Favorites) is pinned via the real key path in
        // testTheLibrariesAccountEmptyFavoritesNamesFavoritesNotLibraries.
        [$next] = $this->screenWith(new FakeTransport())->update(new BrowseFavoritesLoadedMsg([]));

        self::assertSame([], $next->railIds(), 'empty favorites renders nothing here');
        self::assertNull($next->rail('favorites'), 'no card-less rail exists to be focused');
    }

    public function testTheZeroCardFavoritesStateFallsIntoTheHonestEmptyArm(): void
    {
        // The R4 cure's landing, via the same real key path, KEPT: CU-049's
        // empty arm is the honest naming FOR THIS FIXTURE (the libraries
        // answer was empty), and its hint ('R  refresh      q  quit')
        // advertises only the pre-routing live keys [round-2/round-3 folds].
        // Red at ed4423b: the card-less rail box renders here instead.
        $transport = (new FakeTransport())->json(200, ['items' => [], 'total' => 0, 'limit' => 18, 'offset' => 0]);
        $screen = $this->favoritesCycledWith($transport);

        self::assertSame([], $screen->railIds(), 'no rail is raised over an empty answer');

        $view = $screen->view();
        self::assertStringNotContainsString('♥ Favorites', $view, 'the empty box does not render');
        self::assertStringContainsString('No libraries yet.', $view, 'the state names itself (this account has none)');
        self::assertStringContainsString('R  refresh', $view, 'the honest arm still advertises its live escape keys');
        self::assertStringContainsString('q  quit', $view);
    }

    public function testAPopulatedFavoritesResponseStillRaisesItsRail(): void
    {
        // Preservation pin, green at the parent: the cure must not cost the
        // real Favorites path — favorites WITH cards still lift the rail
        // (the R3 favoritesRaisedOverZeroLibraries state over the same
        // 'a'-cycle key path).
        $transport = (new FakeTransport())->json(200, [
            'items' => [['id' => 'f1', 'name' => 'F', 'type' => 'movie', 'poster_url' => null]],
            'total' => 1,
            'limit' => 18,
            'offset' => 0,
        ]);
        $screen = $this->favoritesCycledWith($transport);

        self::assertSame(['favorites'], $screen->railIds(), 'an answer WITH cards still raises the rail');
        $rail = $screen->rail('favorites');
        self::assertNotNull($rail);
        self::assertCount(1, $rail->cards);
    }

    public function testTheLibrariesAccountEmptyFavoritesNamesFavoritesNotLibraries(): void
    {
        // LAW B [review w13L8round4, MAJOR-R4-1 — the executed R4-DD
        // contrast]. An account WITH libraries cycles to Favorites and the
        // answer is EMPTY: the view must name the FAVORITES emptiness and
        // keep the sidebar, never print CU-049's libraries-empty copy over
        // shelves the user does have. RED at parent 7f47494c by construction:
        // the R4 cure routed this state into the arm that renders "No
        // libraries yet." with the 'Movies' shelf hidden — the review's
        // tip-vs-parent executed contrast, now pinned.
        $transport = (new FakeTransport())->json(200, ['items' => [], 'total' => 0, 'limit' => 18, 'offset' => 0]);
        $screen = $this->librariesFavoritesCycledWith($transport);

        $view = $screen->view();
        self::assertStringNotContainsString('No libraries yet.', $view, 'the account has libraries — that is a lie');
        self::assertSame(['favorites'], $screen->railIds(), 'the card-less Favorites box is the honest landing');
        self::assertStringContainsString('♥ Favorites', $view, 'the view names the filter that IS empty');
        self::assertStringContainsString('Movies', $view, 'the sidebar the box carries stays visible');
        self::assertStringContainsString('a  filter(♥ Favorites)', $view, 'the cycle-out key stays published');
        self::assertStringContainsString('Tab  menu', $view, 'the focus toggle is published where it delivers');
        self::assertStringContainsString('R  refresh', $view, 'the pre-routing hoists stay published');
        self::assertStringContainsString('q  quit', $view);
    }

    public function testTheLibrariesAccountZeroFavoritesStateNeverAdvertisesAKeyItCannotServe(): void
    {
        // LAW A over the R5 state (the permanent shape-agnostic law, second
        // fixture): the restored card-less favorites box must publish ONLY
        // what zero cards can serve. With the railsHint() trim in place none
        // of the six card-dependent tokens is advertised, so the audit is
        // green; strip the trim and all six convict over byte-identical
        // views (mutation-proven in the round-5 register).
        $transport = (new FakeTransport())->json(200, ['items' => [], 'total' => 0, 'limit' => 18, 'offset' => 0]);
        $screen = $this->librariesFavoritesCycledWith($transport);

        self::assertSame(
            [],
            $this->advertisedDeadTokens($screen),
            'the libraries-account zero-card favorites hint lies about these keys',
        );
    }

    public function testPopulatedFavoritesOverAStaleScreenCursorStillRenderTheirBox(): void
    {
        // LAW A + the visible box [review w13L8 round-5, R5-1 — the
        // invisible-box state]. The reviewer's executed probe: six shelves,
        // Down x3, 'a' x3, favorites answer WITH a card. The populated raise
        // never clamped the screen cursor (the round-5 register's "self-
        // clamps on read" premise, disproven), so view() sliced the window
        // past the single rail and rendered NO box while railsHint()
        // published five keys the stale focus could not serve. RED at parent
        // 4a60f8ca: the cursor pin and the '♥ Favorites' containment are the
        // invisible box; the audit carries the reviewer's five convictions
        // ({←→ items, ⏎ open, s shuffle, u up-next, [ ] page} — only ↑↓
        // rescued, exactly as recorded). The cure routes the populated raise
        // through the same single-source clamp the empty branch uses.
        $transport = (new FakeTransport())->json(200, [
            'items' => [['id' => 'f1', 'name' => 'F', 'type' => 'movie', 'poster_url' => null]],
            'total' => 1,
            'limit' => 18,
            'offset' => 0,
        ]);
        $screen = $this->populatedFavoritesOverStaleCursorWith($transport);

        self::assertSame(['favorites'], $screen->railIds());
        self::assertSame(0, $screen->railCursor(), 'the stale shelf cursor clamps into the one-rail favorites set');
        self::assertStringContainsString(
            '♥ Favorites',
            $screen->view(),
            'the box lands inside the window at the clamped cursor',
        );

        $view = $screen->view();
        self::assertStringNotContainsString(
            'No libraries yet.',
            $view,
            'the account has shelves — a visible box names Favorites, never the libraries lie',
        );
        self::assertSame(
            [],
            $this->advertisedDeadTokens($screen),
            'the stale-cursor favorites hint lies about these keys',
        );
    }

    public function testCyclingOffFavoritesReRaisesTheShelvesTheStepHid(): void
    {
        // LAW B, second leg [review w13L8 round-5, R5-2 — the cycle-OFF
        // lie]. The 'a' Favorites step hides the library rails to render the
        // favorites answer; cycling OFF cleared the favorites rail but never
        // restored the shelves, so ids fell to [] while librariesLoaded
        // stayed true — and the empty arm printed "No libraries yet." over
        // an account that HAS a Movies shelf. RED at parent on the closure
        // pin (the cure-off leg returned null) and on the lie string (the
        // reviewer's executed C1 contrast). The cure re-fetches exactly the
        // legs the Favorites step cleared (continue + libraries), guarded by
        // accountHasLibraries() so the zero-library account keeps its honest
        // null-cmd landing (BrowseScreenTest's favorites-cycle pins).
        $transport = (new FakeTransport())
            ->json(200, ['items' => [], 'total' => 0, 'limit' => 18, 'offset' => 0]) // the Favorites answer
            ->json(200, ['items' => []]) // continue re-fetch (empty: no rail)
            // the shelves re-answer: the cycled-off legs come back
            ->json(200, ['libraries' => [['id' => 'lib-a', 'name' => 'Movies', 'type' => 'movie']]]);
        $screen = $this->librariesFavoritesCycledWith($transport);

        [$screen, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'a')); // Favorites → All
        self::assertInstanceOf(\Closure::class, $cmd, 'cycling off re-queries the legs the Favorites step cleared');

        foreach ($this->runBatch($cmd) as $msg) {
            [$screen] = $screen->update($msg);
        }

        self::assertSame(
            3,
            $transport->requestCount(),
            'favorites, continue, libraries — the per-library media batch is dropped, the file idiom',
        );
        self::assertSame(['lib-a'], $screen->railIds(), 'the shelves the Favorites step hid come back');

        $view = $screen->view();
        self::assertStringContainsString('Movies', $view, 'the raised rail renders');
        self::assertStringNotContainsString(
            'No libraries yet.',
            $view,
            'the account has a Movies shelf — that string is a lie',
        );
        self::assertStringNotContainsString('♥ Favorites', $view, 'the favorites rail is gone with its filter');
        self::assertSame([], $this->advertisedDeadTokens($screen), 'the cycle-off hint lies about these keys');
    }

    public function testTheCardLessFavoritesBoxTrimsTheStaleSidebarKeys(): void
    {
        // LAW A in the SIDEBAR zone [review w13L8 round-5, R5-3]. Tab off the
        // R5 cure state: the sidebar still carries the single shelf the
        // Favorites step hid — its rail is gone, its entry is stale. The
        // un-trimmed hint published '↑↓  library' (Sidebar::down clamps over
        // a one-entry list) and '⏎  open' (openLibrary -> railById over the
        // cleared libraryRails -> null) — both null-cmds over byte-identical
        // views, the reviewer's executed C3 pair. RED at parent on both
        // containment flips AND the audit; post-cure the hint composes to
        // the arms the state serves: H / P / Tab / q.
        $transport = (new FakeTransport())->json(200, ['items' => [], 'total' => 0, 'limit' => 18, 'offset' => 0]);
        $screen = $this->librariesFavoritesCycledWith($transport);
        [$screen, $cmd] = $screen->update(new KeyMsg(KeyType::Tab));
        self::assertNull($cmd, 'Tab is a focus move');

        $view = $screen->view();
        self::assertStringNotContainsString('↑↓  library', $view, 'one stale entry — the arrows move nothing');
        self::assertStringNotContainsString('⏎  open', $view, 'the rail behind the stale entry is gone');
        self::assertStringContainsString('H  history', $view, 'the user-riding arms stay published');
        self::assertStringContainsString('P  playlists', $view);
        self::assertStringContainsString('Tab  rails', $view);
        self::assertStringContainsString('q  quit', $view);
        self::assertSame(
            [],
            $this->advertisedDeadTokens($screen),
            'the sidebar hint lies about these keys',
        );
    }

    public function testThePopulatedSingleRailFavoritesHintTrimsWhatASingleRailCannotServe(): void
    {
        // LAW A per SEGMENT, not per ANY [review w13L8 round-5, R5-4 — the
        // root card]. The R3 law state: zero libraries, favorites answer
        // WITH one card. anyRailHasCards() published the FULL row over: ↑↓
        // (a one-rail set clamps the cursor both ways), ←→ (Rail::moveCursor
        // clamps on a single card), u (no up-next rail exists) and [ ]
        // (paging belongs to the Most Watched rail alone) — while ⏎ and s
        // ARE genuinely served by the focused card. RED at parent on the
        // four containment flips and the four-token audit (exactly the
        // reviewer's conviction set). cols 240 so the FULL row publishes
        // intact for the audit to see the lies.
        $transport = (new FakeTransport())->json(200, [
            'items' => [['id' => 'f1', 'name' => 'F', 'type' => 'movie', 'poster_url' => null]],
            'total' => 1,
            'limit' => 18,
            'offset' => 0,
        ]);
        $screen = $this->favoritesCycledWith($transport, cols: 240);

        self::assertSame(['favorites'], $screen->railIds(), 'the populated rail stands (R3 law kept)');

        $view = $screen->view();
        self::assertStringContainsString(
            '⏎  open',
            $view,
            'the focused card is real — open and shuffle stay published',
        );
        self::assertStringContainsString('s  shuffle', $view);
        self::assertStringContainsString('Tab  menu', $view);
        self::assertStringContainsString('a  filter(♥ Favorites)', $view);
        self::assertStringContainsString('R  refresh', $view);
        self::assertStringContainsString('q  quit', $view);
        self::assertStringNotContainsString('↑↓  rails', $view, 'one rail: the arrows clamp both ways');
        self::assertStringNotContainsString('←→  items', $view, 'one card: left/right clamp');
        self::assertStringNotContainsString('u  up-next', $view, 'no up-next rail exists in this state');
        self::assertStringNotContainsString('[ ]  page', $view, 'paging belongs to the Most Watched rail');

        self::assertSame(
            [],
            $this->advertisedDeadTokens($screen),
            'the single-rail populated hint lies about these keys',
        );
    }

    public function testAPopulatedFavoritesPairStillAdvertisesAndServesItemMovement(): void
    {
        // The cure's other edge: per-segment truth may not eat a LIVE key.
        // With TWO cards in the focused rail, '←→  items' is genuinely
        // served and must stay published (the no-over-trim leg — the same
        // containment was green at the parent and must survive the cure).
        // The audit leg REDS at the parent too: the single-rail two-card
        // state still published the FULL row, so ↑↓/u/[ ] convict there —
        // R5-4's root card is about RAIL count, not card count. Audited
        // from the fresh cursor-0 screen (the pair convention presses
        // Right; cursor 1 would sit on the clamp edge).
        $transport = (new FakeTransport())->json(200, [
            'items' => [
                ['id' => 'f1', 'name' => 'F', 'type' => 'movie', 'poster_url' => null],
                ['id' => 'f2', 'name' => 'G', 'type' => 'movie', 'poster_url' => null],
            ],
            'total' => 2,
            'limit' => 18,
            'offset' => 0,
        ]);
        $screen = $this->favoritesCycledWith($transport, cols: 240);

        $view = $screen->view();
        self::assertStringContainsString('←→  items', $view, 'two cards — the pair is live and stays advertised');
        self::assertStringContainsString('⏎  open', $view);

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Right));
        self::assertNull($cmd, 'the movement is a pure state move');
        self::assertNotSame($view, $next->view(), 'the advertised ←→ visibly moves the card focus');

        self::assertSame([], $this->advertisedDeadTokens($screen), 'the two-card hint lies about these keys');
    }

    // ---- harness (offline FakeTransport kit, mirrors BrowseScreenTest) ----

    /** @return list<Msg> */
    private function runBatch(?\Closure $cmd): array
    {
        if ($cmd === null) {
            return [];
        }

        $result = $cmd();
        $children = $result instanceof BatchMsg ? $result->cmds : [$cmd];

        $msgs = [];
        foreach ($children as $child) {
            $msg = $this->runCmd($child);
            if ($msg !== null) {
                $msgs[] = $msg;
            }
        }

        return $msgs;
    }

    private function runCmd(\Closure $cmd): ?Msg
    {
        $result = $cmd();
        if ($result instanceof AsyncCmd) {
            return $this->await($result->promise);
        }

        return $result instanceof Msg ? $result : null;
    }

    private function await(PromiseInterface $promise, float $timeout = 5.0): mixed
    {
        $state = ['done' => false, 'value' => null, 'error' => null];
        $timer = null;
        // Cancelling the safety timer on settle is essential: otherwise the
        // loop's stream_select stays blocked on that far-future timer for
        // the whole timeout after stop() (BrowseScreenTest's lesson).
        $settle = static function () use (&$timer): void {
            if ($timer !== null) {
                Loop::cancelTimer($timer);
                $timer = null;
            }
            Loop::stop();
        };
        $promise->then(
            function ($v) use (&$state, $settle): void {
                $state['value'] = $v;
                $state['done'] = true;
                $settle();
            },
            function ($e) use (&$state, $settle): void {
                $state['error'] = $e;
                $state['done'] = true;
                $settle();
            },
        );

        if (!$state['done']) {
            $timer = Loop::addTimer($timeout, static fn () => Loop::stop());
            Loop::run();
            if ($timer !== null) {
                Loop::cancelTimer($timer);
            }
        }

        if (!$state['done']) {
            throw new \RuntimeException('cmd did not settle in time');
        }
        if ($state['error'] !== null) {
            throw $state['error'];
        }

        return $state['value'];
    }
}
