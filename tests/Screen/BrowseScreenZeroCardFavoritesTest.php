<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\AuthUser;
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
 */
final class BrowseScreenZeroCardFavoritesTest extends TestCase
{
    /**
     * The probe keys of the reviewer's conviction: the hint token that
     * publishes the arm, and the keystroke that must then be served
     * ("[ ]  page" stands for both paging keys and is pressed twice).
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
     * this state, exactly as CU-049 round-2's test pins.
     */
    private function favoritesCycledWith(FakeTransport $transport): BrowseScreen
    {
        [$screen] = $this->screenWith($transport)->update(new LibrariesLoadedMsg([]));
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

    public function testTheZeroCardFavoritesStateNeverAdvertisesAKeyItCannotServe(): void
    {
        // THE CONVICTION [review ed4423b MAJOR-1]. Shape-agnostic by law:
        // whether the cure falls into the empty arm or trims the hint, every
        // token this state PUBLISHES must be DELIVERED — a null-cmd over a
        // byte-identical view is the advertised-dead shape the reviewer
        // executed. Red at ed4423b with the probe's tokens in the verdict.
        $transport = (new FakeTransport())->json(200, ['items' => [], 'total' => 0, 'limit' => 18, 'offset' => 0]);
        $screen = $this->favoritesCycledWith($transport);
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

        self::assertSame(
            [],
            array_values(array_unique($advertisedDead)),
            'the zero-card favorites hint lies about these keys (press = null cmd, view byte-identical)',
        );
    }

    public function testAnEmptyFavoritesResponseRaisesNoRail(): void
    {
        // Sibling-doctrine mirror of testEmptyMostWatchedAddsNoRail and
        // testEmptyContinueWatchingAddsNoRail: the answer "your favorites
        // are empty" renders nothing, not an empty box.
        [$next] = $this->screenWith(new FakeTransport())->update(new BrowseFavoritesLoadedMsg([]));

        self::assertSame([], $next->railIds(), 'empty favorites renders nothing');
        self::assertNull($next->rail('favorites'), 'no card-less rail exists to be focused');
    }

    public function testTheZeroCardFavoritesStateFallsIntoTheHonestEmptyArm(): void
    {
        // The cure's landing, via the same real key path: CU-049's empty arm
        // names the state, and its hint ('R  refresh      q  quit')
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
