<?php

declare(strict_types=1);

namespace Phlix\Console\Tests;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\App;
use Phlix\Console\Config\Config;
use Phlix\Console\Config\ServerEntry;
use Phlix\Console\Config\TokenStore;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Route;
use Phlix\Console\Screen\LoginScreen;
use Phlix\Console\Store\AuthStore;
use Phlix\Console\Store\LibrariesStore;
use Phlix\Console\Store\MediaStore;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Image\Mosaic;

/**
 * M-CONSOLE-44 [review console__tuispec]: bracketed paste arrives from
 * sugar-core as ONE PasteMsg (InputReader.php:124) while every text surface
 * in this app — palette, Search, FilterBar, the SyncPlay room name, the
 * sugar-forms fields — consumes only Char/Space KeyMsgs, so an untranslated
 * paste was a silent no-op on all of them.
 *
 * The cure is TWO halves: bin/phlix now constructs Program with
 * ProgramOptions(bracketedPaste: true) — terminal mode 2004, without which
 * no paste is ever wrapped/emitted — plus the dispatch() PasteMsg branch +
 * App::pasteAsKeys(): the paste is replayed as the SAME key sequence the
 * terminal emits for the identical characters typed (space → KeyType::Space
 * per InputReader.php:499 parity). These pins lock the behavior class:
 *
 *  - the entrypoint enable of mode 2004 (consumption without enablement is
 *    half a cure);
 *  - palette capture of a replayed paste (structural + end-state + typed
 *    parity — the design law itself asserted);
 *  - Space keeps KeyType::Space (focus-safe, matches typing);
 *  - line endings/tabs are FLATTENED, never Enter/Tab (no half-form submits,
 *    no focus jumps) — safety law;
 *  - headline symptom: a paste into the login field lands in the form value;
 *  - invisible format chars (ZWSP, bidi) are stripped, never carried silently;
 *  - the MAX_PASTE_REPLAY_KEYS=4096 cap (hostile-clipboard DoS valve);
 *  - an all-filtered paste produces no Cmd churn at all.
 *
 * Mutation teeth: drop the dispatch branch → tests 1/3/4 red (PasteMsg falls
 * through, filterText stays ''); flip newline to Enter → test 3 red; drop the
 * cap → test 6 red; space-as-Char → test 2 red.
 */
final class AppPasteTest extends TestCase
{
    /** Mirrors App::MAX_PASTE_REPLAY_KEYS (private const; pinned by value). */
    private const EXPECTED_PASTE_CAP = 4096;

    private string $dir;
    private string|false $prevXdg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/phlix-paste-' . bin2hex(random_bytes(6));
        // Redirect config + token persistence into a temp dir.
        $this->prevXdg = getenv('XDG_CONFIG_HOME');
        putenv('XDG_CONFIG_HOME=' . $this->dir);
    }

    protected function tearDown(): void
    {
        $this->prevXdg === false ? putenv('XDG_CONFIG_HOME') : putenv('XDG_CONFIG_HOME=' . $this->prevXdg);
        foreach (glob($this->dir . '/phlix/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . '/phlix');
        @rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * Build an App directly over a hand-made screen stack (AppTest::appWithStack
     * idiom). $loggedIn seeds a logged-in AuthStore — the gate
     * App::paletteAndSearchAvailable() reads.
     *
     * @param list<array{route: Route, screen: Model}> $stack
     */
    private function appWithStack(array $stack, bool $loggedIn = false): App
    {
        $api = new ApiClient('https://srv', new FakeTransport());

        return new App(
            new Config(servers: [new ServerEntry(id: 's1', label: 'Server', url: 'https://srv')], activeServerId: 's1'),
            $loggedIn ? $this->loggedInAuthStore() : new AuthStore($api, TokenStore::default()),
            $api,
            new LibrariesStore($api),
            new MediaStore($api),
            new PosterLoader(Mosaic::halfBlock()),
            $stack,
        );
    }

    /** @see AppTest::loggedInAuthStore() — the same login-gate replication. */
    private function loggedInAuthStore(): AuthStore
    {
        $transport = (new FakeTransport())->json(200, [
            'access_token' => 'a', 'refresh_token' => 'r', 'token_type' => 'Bearer', 'expires_in' => 3600,
            'user' => ['id' => 'u1', 'username' => 'root', 'is_admin' => 0, 'status' => 'active'],
        ]);
        $auth = new AuthStore(new ApiClient('https://srv', $transport), TokenStore::default());
        $this->await($auth->login('root', 'secret'));

        return $auth;
    }

    private function paletteApp(): App
    {
        return $this->appWithStack([['route' => Route::Browse, 'screen' => new PasteSinkScreen()]], loggedIn: true);
    }

    private function ctrlK(): KeyMsg
    {
        return new KeyMsg(KeyType::Char, 'k', ctrl: true);
    }

    /**
     * Run a (possibly batched) Cmd and collect the Msgs its children produce
     * (AppTest::collectBatch idiom; paste replays are synchronous sends).
     *
     * @return list<Msg>
     */
    private function collectBatch(?\Closure $cmd): array
    {
        if ($cmd === null) {
            return [];
        }
        $result = $cmd();
        if ($result instanceof BatchMsg) {
            $msgs = [];
            foreach ($result->cmds as $child) {
                foreach ($this->collectBatch($child) as $m) {
                    $msgs[] = $m;
                }
            }

            return $msgs;
        }
        if ($result instanceof AsyncCmd) {
            $msg = $this->await($result->promise);

            return $msg instanceof Msg ? [$msg] : [];
        }

        return $result instanceof Msg ? [$result] : [];
    }

    /** Settle a promise on the shared event loop (AppTest::await idiom). */
    private function await(PromiseInterface $promise, float $timeout = 2.0): mixed
    {
        $state = ['done' => false, 'value' => null, 'error' => null];
        $promise->then(
            function ($v) use (&$state): void {
                $state['value'] = $v;
                $state['done'] = true;
                Loop::stop();
            },
            function ($e) use (&$state): void {
                $state['error'] = $e;
                $state['done'] = true;
                Loop::stop();
            },
        );

        if ($state['done']) {
            // The promise settled synchronously (a store wraps the sync
            // FakeTransport in a Deferred). React may still have enqueued the
            // Deferred's handler on the loop's futureTick queue — flush it with a
            // single immediate tick so no residual work leaks into a later test's
            // Loop::run(); a futureTick stop returns at once (no blocking wait).
            Loop::futureTick(static fn () => Loop::stop());
            Loop::run();
        } else {
            $timer = Loop::addTimer($timeout, static fn () => Loop::stop());
            Loop::run();
            Loop::cancelTimer($timer);
        }

        if ($state['error'] !== null) {
            throw $state['error'];
        }

        return $state['value'];
    }

    /** @return list<KeyMsg> every replayed child, typed; fails if a non-KeyMsg leaked in */
    private function pasteKeys(?\Closure $cmd): array
    {
        $all = $this->collectBatch($cmd);
        $keys = [];
        foreach ($all as $m) {
            if ($m instanceof KeyMsg) {
                $keys[] = $m;
            }
        }
        self::assertCount(count($all), $keys, 'the paste replay must contain KeyMsgs only');

        return $keys;
    }

    /** Replay each produced key through update() exactly as Program::dispatch would. */
    private function replay(App $app, array $keys): App
    {
        $acc = $app;
        foreach ($keys as $k) {
            [$acc] = $acc->update($k);
        }

        return $acc;
    }

    public function testPasteWhilePaletteOpenTypesTheQuery(): void
    {
        [$open] = $this->paletteApp()->update($this->ctrlK());
        self::assertNotNull($open->palette());

        [$next, $cmd] = $open->update(new PasteMsg('Home'));

        self::assertSame($open, $next, 'the paste folds no model state itself — it replays keys');
        self::assertNotNull($cmd, 'a consumed paste must return a replay Cmd');
        $keys = $this->pasteKeys($cmd);
        self::assertCount(4, $keys);
        foreach (['H', 'o', 'm', 'e'] as $i => $rune) {
            self::assertSame(KeyType::Char, $keys[$i]->type);
            self::assertSame($rune, $keys[$i]->rune);
        }

        $typed = $this->replay($next, $keys);
        self::assertNotNull($typed->palette());
        self::assertSame('Home', $typed->palette()->filterText());

        // The design law, asserted: replayed paste state is EQUAL to the same
        // characters typed one key at a time (palette state may hold any label
        // ranking; equality pins the law, a hardcoded label would not).
        [$reference] = $this->paletteApp()->update($this->ctrlK());
        foreach (['H', 'o', 'm', 'e'] as $rune) {
            [$reference] = $reference->update(new KeyMsg(KeyType::Char, rune: $rune));
        }
        self::assertSame($reference->palette()->visibleLabels(), $typed->palette()->visibleLabels());
        self::assertSame($reference->palette()->selectedAction()?->label, $typed->palette()->selectedAction()?->label);
    }

    public function testBracketedPasteModeIsEnabledAtTheEntrypoint(): void
    {
        // Consumption without enablement is half a cure: unless the app asks
        // the terminal for mode 2004, pastes never arrive wrapped in
        // ESC[200~…ESC[201~ and InputReader surfaces no PasteMsg to replay.
        // The `run` entrypoint must construct Program with bracketedPaste on.
        $bin = (string) file_get_contents(dirname(__DIR__) . '/bin/phlix');
        self::assertMatchesRegularExpression(
            '/new ProgramOptions\(\s*bracketedPaste:\s*true\s*\)/',
            $bin,
            'bin/phlix must enable bracketed paste (terminal mode 2004)',
        );
    }

    public function testPasteSpaceBecomesSpaceKeyType(): void
    {
        [, $cmd] = $this->paletteApp()->update(new PasteMsg('a b'));

        $keys = $this->pasteKeys($cmd);
        self::assertCount(3, $keys);
        self::assertSame(KeyType::Char, $keys[0]->type);
        self::assertSame('a', $keys[0]->rune);
        // InputReader.php:499 parity: a typed 0x20 is a Space key, never a
        // Char — screens that match Space separately must see the same shape.
        self::assertSame(KeyType::Space, $keys[1]->type);
        self::assertSame(' ', $keys[1]->rune);
        self::assertSame(KeyType::Char, $keys[2]->type);
        self::assertSame('b', $keys[2]->rune);
    }

    public function testPasteNeverProducesEnterOrTab(): void
    {
        [, $cmd] = $this->paletteApp()->update(new PasteMsg("https://example.com\n\tuser"));

        $keys = $this->pasteKeys($cmd);
        foreach ($keys as $k) {
            self::assertNotSame(KeyType::Enter, $k->type, 'a paste must never submit a half-filled form');
            self::assertNotSame(KeyType::Tab, $k->type, 'a paste must never jump focus');
        }
        $text = implode('', array_map(static fn (KeyMsg $k): string => $k->rune, $keys));
        self::assertSame('https://example.comuser', $text, 'line endings and tabs are flattened, content kept');
    }

    public function testPasteIntoLoginFieldLandsInFormValue(): void
    {
        $app = $this->appWithStack([['route' => Route::Login, 'screen' => LoginScreen::create()]]);

        [, $cmd] = $app->update(new PasteMsg('token value'));

        $typed = $this->replay($app, $this->pasteKeys($cmd));
        $screen = $typed->screen();
        self::assertInstanceOf(LoginScreen::class, $screen);
        self::assertFalse($screen->form->isSubmitted(), 'a paste must never submit the form');
        // The headline symptom of the review, pinned end-to-end at the surface
        // that matters: the clipboard text reaches the focused field.
        self::assertSame('token value', $screen->form->getString('username'));
    }

    public function testPasteStripsInvisibleFormatChars(): void
    {
        [, $cmd] = $this->paletteApp()->update(new PasteMsg("pass\xE2\x80\x8Bword")); // zero-width space

        $keys = $this->pasteKeys($cmd);
        $text = implode('', array_map(static fn (KeyMsg $k): string => $k->rune, $keys));
        self::assertSame('password', $text);
        self::assertCount(8, $keys);
        foreach ($keys as $k) {
            self::assertSame(0, preg_match('/\p{C}/u', $k->rune), 'no invisible format char may ride a replayed key');
        }
    }

    public function testPasteReplayIsCapped(): void
    {
        [, $cmd] = $this->paletteApp()->update(new PasteMsg(str_repeat('a', 5000)));

        // Hostile-clipboard DoS valve: only the cap's worth of keys is replayed.
        self::assertCount(self::EXPECTED_PASTE_CAP, $this->pasteKeys($cmd));
    }

    public function testEmptyAfterFilterPasteIsNoop(): void
    {
        $app = $this->paletteApp();

        [$next, $cmd] = $app->update(new PasteMsg("\n\n"));

        self::assertSame($app, $next);
        self::assertNull($cmd, 'an all-filtered paste must not emit empty Cmd churn');
    }
}

/**
 * A no-op Model standing where a real screen would be (AppTest file-scoped
 * fake idiom — tests/PSR1.MultipleClasses is excluded by phpcs-tests.xml).
 */
final class PasteSinkScreen implements Model
{
    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        return [$this, null];
    }

    public function view(): string
    {
        return '';
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
