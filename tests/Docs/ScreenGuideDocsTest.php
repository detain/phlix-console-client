<?php

/**
 * Guard against the M-CONSOLE-17 class (review console__docmaint, M4): the dev
 * guide's "Adding a Screen" recipe contradicted what actually ships — an
 * `App::boot(...): ?App` signature missing the seventh `$audioFactory` param,
 * `Loadable` described (and `use`-d) as a trait when it is an interface, the
 * phantom `HomeScreen.php` (the home screen is `BrowseScreen.php`), an
 * `$messageHandlers` array in `boot()` that never existed, `$this->dispatch()`
 * calls from screens (dispatch is private to the App), `composer phpcs`
 * (the script is `cs`), a `tests/phpunit/` directory (the suite is flat
 * `tests/`), and a `PHLIX_DEBUG` variable the entrypoint never reads.
 *
 * Every code fact below is derived from the live tree — reflection over
 * App/Chrome/screen contracts, composer.json, phpunit.xml.dist, the bin/phlix
 * getenv() census, and an index of real source files — never restated from
 * prose, then pinned into docs/dev/client-console.md. A code rename reddens
 * the reflection pins; a drifting guide reddens the doc pins; either way the
 * message names the surface that must move together — the same doctrine as
 * TokenFileNameDocsTest, RuntimeRequirementsDocsTest, WatchCommandDocsTest
 * and PlayerKeyDocsTest.
 *
 * The recipe itself is compile-pinned: every complete PHP block the guide
 * carries is syntax-linted with the running PHP binary, so "the recipe cannot
 * compile" can never be true of this document again.
 *
 * Follow-up (this commit): the boot-return pin reads the literal `): self`
 * from src/App.php because PHP 8.5 reflection resolves `self` to the
 * declaring FQCN (which reddened the 8.5 test leg of the landing commit), and
 * the Step-1 example's "modeled on src/Screen/X.php" attribution became a
 * derived code pin — the named screen must itself show the example's shape
 * (Chrome frame, ApiClient, Cmd::promise, NavigateBackMsg), which StatsScreen
 * stopped doing long before the guide copied it.
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Docs;

use Phlix\Console\App;
use Phlix\Console\Ui\Chrome;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionEnum;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;
use SimpleXMLElement;

final class ScreenGuideDocsTest extends TestCase
{
    /** The document under guard. */
    private const GUIDE = 'docs/dev/client-console.md';

    /**
     * The recipe's OWN fiction: files the guide tells the reader to create.
     * These are the only `*.php` names the guide may carry that do not exist
     * in the tree; new worked examples join this list deliberately.
     *
     * @var list<string>
     */
    private const RECIPE_FICTION = [
        'MyDto.php',
        'MyNewLoadedMsg.php',
        'MyNewScreen.php',
        'OpenMyNewMsg.php',
    ];

    /**
     * Screen-contract vocabulary the guide's table classifies:
     * FQCN => [basename, doc kind, guide label]. The kind must match PHP itself.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const CONTRACTS = [
        \SugarCraft\Core\Model::class => ['Model.php', 'interface', 'SugarCraft\Core\Model'],
        \SugarCraft\Core\SubscriptionCapable::class => ['SubscriptionCapable.php', 'trait', 'SubscriptionCapable'],
        \Phlix\Console\Screen\Loadable::class => ['Loadable.php', 'interface', 'Loadable'],
        \Phlix\Console\Screen\Themed::class => ['Themed.php', 'interface', 'Themed'],
        \Phlix\Console\Screen\ThemedScreen::class => ['ThemedScreen.php', 'trait', 'ThemedScreen'],
        \Phlix\Console\Screen\Shimmering::class => ['Shimmering.php', 'interface', 'Shimmering'],
        \Phlix\Console\Screen\ShimmeringScreen::class => ['ShimmeringScreen.php', 'trait', 'ShimmeringScreen'],
        \Phlix\Console\Screen\Breadcrumbed::class => ['Breadcrumbed.php', 'interface', 'Breadcrumbed'],
        \Phlix\Console\Screen\Teardownable::class => ['Teardownable.php', 'interface', 'Teardownable'],
        \Phlix\Console\Screen\CapturesSlash::class => ['CapturesSlash.php', 'marker', 'CapturesSlash'],
    ];

    /**
     * Census pin: every PHLIX_* env var the shipped entrypoint reads, sorted
     * (the same set WatchCommandDocsTest derives for the README / console.md
     * surfaces — re-pinned here for the dev guide's Debugging section).
     *
     * @var list<string>
     */
    private const BIN_ENV_CENSUS = [
        'PHLIX_HUB_SERVER_ID',
        'PHLIX_HUB_TOKEN',
        'PHLIX_HUB_URL',
        'PHLIX_POSTER_CACHE_ENTRIES',
        'PHLIX_RELAY_TOKEN',
        'PHLIX_SERVER_URL',
    ];

    public function testBootSignatureIsPinnedVerbatimToTheGuide(): void
    {
        $boot = new ReflectionMethod(App::class, 'boot');

        $parts = [];
        foreach ($boot->getParameters() as $param) {
            $parts[] = $this->renderParameter($param);
        }

        // PHP 8.5's reflection resolves the `self` return literal to the
        // declaring class FQCN (where 8.3/8.4 report the token 'self'), so the
        // pinned literal is re-read from the source — the version-stable
        // ground truth — and reflection only cross-checks the declared type.
        self::assertContains(
            (string) $boot->getReturnType(),
            ['self', App::class],
            'App::boot() no longer returns self; re-derive the guide pin.'
        );
        $returnLiteral = self::declaredBootReturnType();
        self::assertSame(
            'self',
            $returnLiteral,
            'App::boot() source no longer declares `): self`; the guide and this pin move together.'
        );

        $expected = 'App::boot(' . implode(', ', $parts) . '): ' . $returnLiteral;
        self::assertStringContainsString(
            $expected,
            self::readDoc(),
            'App::boot() moved; the guide documents it as the factory entrypoint — update '
            . 'the Key-methods line in ' . self::GUIDE . ' and this pin together.'
        );
        self::assertStringNotContainsString(
            '): ?App',
            self::readDoc(),
            'The guide resurrected the old nullable-App boot return.'
        );
    }

    public function testChromeFrameSignatureIsPinnedToTheGuide(): void
    {
        $frame = new ReflectionMethod(Chrome::class, 'frame');

        $names = array_map(
            static fn (ReflectionParameter $param): string => $param->getName(),
            $frame->getParameters()
        );
        self::assertSame(
            ['title', 'body', 'hint', 'cols', 'rows', 'trail', 'theme'],
            $names,
            'Chrome::frame() changed its parameter list: move the guide sentence that '
            . 'spells out the signature and this pin together.'
        );

        $required = array_filter(
            $frame->getParameters(),
            static fn (ReflectionParameter $param): bool => !$param->isDefaultValueAvailable()
        );
        self::assertCount(
            5,
            $required,
            'Chrome::frame() gained or dropped a required parameter; the worked example '
            . 'and this pin must move together.'
        );

        $guide = self::readDoc();
        self::assertStringContainsString(
            'Chrome::frame($title, $body, $hint, $cols, $rows, $trail, $theme)',
            $guide,
            'The guide no longer spells the shipped Chrome::frame() signature.'
        );
        self::assertStringContainsString(
            "Chrome::frame('My New Screen', \$body, 'Esc  back', \$this->cols, \$this->rows, [], \$this->theme())",
            $guide,
            'The worked example stopped matching the real call shape the shipped screens use.'
        );
    }

    public function testScreenContractsAreClassifiedAsPhpSays(): void
    {
        $guide = self::readDoc();

        foreach (self::CONTRACTS as $fqcn => [$basename, $docKind, $label]) {
            $isInterface = interface_exists($fqcn);
            $isTrait = trait_exists($fqcn);

            self::assertTrue(
                $isInterface || $isTrait,
                $basename . ' is neither interface nor trait anymore; the guide table and CONTRACTS need re-derivation.'
            );

            $phpKind = $isTrait ? 'trait' : ($docKind === 'marker' ? 'marker' : 'interface');
            self::assertSame(
                $docKind,
                $phpKind,
                $basename . ' changed kind in code (' . ($isTrait ? 'trait' : 'interface') . '); '
                . 'update CONTRACTS and the ' . self::GUIDE . ' table together.'
            );

            self::assertStringContainsString(
                '| `' . $label . '` | ' . $docKind . ' |',
                $guide,
                'The contract-table row for ' . $basename . ' drifted from the pinned kind `' . $docKind . '`.'
            );
        }
    }

    public function testNavigationContractIsPinnedAndThePhantomRouterIsGone(): void
    {
        self::assertTrue((new ReflectionMethod(App::class, 'dispatch'))->isPrivate());
        self::assertTrue((new ReflectionMethod(App::class, 'push'))->isPrivate());
        self::assertTrue(enum_exists(\Phlix\Console\Route::class));
        self::assertFalse(
            (new ReflectionEnum(\Phlix\Console\Route::class))->isBacked(),
            'Route gained a backing type; re-check the guide prose about it.'
        );

        // Screens cannot call the App's router; they emit msgs via Cmd::send.
        foreach (glob(dirname(__DIR__, 2) . '/src/Screen/*.php') ?: [] as $screen) {
            self::assertStringNotContainsString(
                '$this->dispatch(',
                (string) file_get_contents($screen),
                basename($screen) . ' now calls dispatch() itself — the navigation story in the guide must move too.'
            );
        }

        $guide = self::readDoc();
        self::assertStringNotContainsString(
            '$messageHandlers',
            $guide,
            'The guide resurrected a router mechanism the repo never had.'
        );
        self::assertStringNotContainsString(
            'URL mapping',
            $guide,
            'Route.php is an enum of destinations, not a URL map; the guide must not call it one.'
        );
        self::assertStringNotContainsString(
            'HomeScreen',
            $guide,
            'HomeScreen.php does not exist; the home screen is BrowseScreen.php.'
        );
        self::assertStringNotContainsString(
            'extends ThemedScreen',
            $guide,
            'ThemedScreen is a trait; no screen extends it.'
        );
        self::assertStringContainsString(
            'Cmd::send(new OpenMyNewMsg())',
            $guide,
            'The guide dropped the shipped screen-to-App emit form '
            . '(a screen returns Cmd::send; it never calls dispatch()).'
        );
        self::assertStringContainsString(
            'return [$this->push(Route::MyNew, $screen), $screen->init()];',
            $guide,
            'The opener example no longer matches the shipped open*() shape (push the frame, run init).'
        );
    }

    public function testEveryFileNamedInTheGuideExists(): void
    {
        $known = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src', RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $known[$file->getFilename()] = true;
            }
        }

        preg_match_all('/([A-Z][A-Za-z0-9]*\.php)/', self::readDoc(), $matches);
        self::assertNotEmpty($matches[1]);

        foreach (array_unique($matches[1]) as $named) {
            if (in_array($named, self::RECIPE_FICTION, true)) {
                continue;
            }

            self::assertArrayHasKey(
                $named,
                $known,
                self::GUIDE . ' names ' . $named . ' but src/ has no such file — '
                . 'the tree or prose must follow the code '
                . '(or a new worked example joins RECIPE_FICTION).'
            );
        }

        // Positive pins for the exact files the review found misnamed.
        self::assertArrayHasKey('BrowseScreen.php', $known);
        self::assertStringContainsString('BrowseScreen.php', self::readDoc());
    }

    public function testComposerScriptsArePinnedToTheRealKeys(): void
    {
        $decoded = json_decode(self::readFile('composer.json'), true);
        self::assertIsArray($decoded);

        $scripts = array_keys((array) ($decoded['scripts'] ?? []));
        self::assertContains('cs', $scripts);
        self::assertContains('cs:fix', $scripts);
        self::assertContains('phpstan', $scripts);
        self::assertContains('test', $scripts);
        self::assertNotContains(
            'phpcs',
            $scripts,
            'composer gained a `phpcs` script; the guide pin below must flip with it.'
        );

        $guide = self::readDoc();
        self::assertStringContainsString('composer cs', $guide);
        self::assertStringContainsString('composer cs:fix', $guide);
        self::assertStringContainsString('composer phpstan', $guide);
        self::assertStringContainsString('composer test', $guide);
        self::assertStringNotContainsString(
            'composer phpcs',
            $guide,
            'The guide advertises a composer script that does not exist.'
        );
    }

    public function testTheTestSuiteDirectoryIsFlat(): void
    {
        $xml = new SimpleXMLElement(self::readFile('phpunit.xml.dist'));
        $directory = (string) $xml->testsuites->testsuite->directory;

        self::assertSame(
            'tests',
            $directory,
            'phpunit.xml.dist moved the suite; update the Testing section and this pin together.'
        );
        self::assertDirectoryExists(dirname(__DIR__, 2) . '/tests/Docs');
        self::assertDirectoryExists(dirname(__DIR__, 2) . '/tests/Screen');

        $guide = self::readDoc();
        self::assertStringNotContainsString(
            'tests/phpunit',
            $guide,
            'No tests/phpunit/ directory exists; the suite is flat under tests/.'
        );
        self::assertStringContainsString('flat under `tests/`', $guide);
        self::assertStringContainsString('tests/Screen/', $guide);
        self::assertStringContainsString('tests/Docs/', $guide);
    }

    public function testTheGuideNamesOnlyShippedEnvVars(): void
    {
        $guide = self::readDoc();

        preg_match_all('/PHLIX_[A-Z_]+/', $guide, $matches);
        self::assertNotEmpty($matches[0]);

        foreach (array_unique($matches[0]) as $named) {
            self::assertContains(
                $named,
                self::BIN_ENV_CENSUS,
                self::GUIDE . ' names ' . $named . ', which no shipped entrypoint reads '
                . '(re-derive against the bin/phlix getenv() census).'
            );
        }

        self::assertStringNotContainsString('PHLIX_DEBUG', $guide, 'PHLIX_DEBUG is not read anywhere in the repo.');

        foreach (self::BIN_ENV_CENSUS as $var) {
            self::assertStringContainsString($var, $guide, 'The guide dropped ' . $var . ' from its env-var prose.');
        }
    }

    public function testCompletePhpBlocksInTheGuideCompile(): void
    {
        $complete = array_values(array_filter(
            self::phpBlocks(),
            static fn (string $block): bool => (bool) preg_match(
                '/^(?:final\s+)?(?:readonly\s+)?class\s+[A-Z]/m',
                $block
            )
        ));

        self::assertGreaterThanOrEqual(
            4,
            count($complete),
            'The guide lost its complete worked examples; the compile guard would go vacuous.'
        );

        foreach ($complete as $index => $block) {
            $path = tempnam(sys_get_temp_dir(), 'phlix-guide-');
            self::assertIsString($path);

            try {
                file_put_contents($path, $block);
                $out = [];
                exec(escapeshellarg(PHP_BINARY) . ' -l -n ' . escapeshellarg($path) . ' 2>&1', $out, $status);
                self::assertSame(
                    0,
                    $status,
                    'Guide block #' . $index . ' fails `php -l` — the recipe cannot compile: ' . implode("\n", $out)
                );
            } finally {
                unlink($path);
            }
        }
    }

    /**
     * The Step-1 example's "modeled on src/Screen/X.php" attribution is a CODE
     * claim, not prose: the named screen must actually exhibit the exact shape
     * the example shows (Chrome frame, ApiClient injection, Cmd::promise fetch,
     * NavigateBackMsg return). The original rewrite named StatsScreen, whose
     * view() returns a raw string and whose constructor takes an AdminClient —
     * a true-in-2023 fact that had quietly rotted. Pinning the attribution to
     * the tree means a model-on rename that breaks the example's shape reddens
     * this test instead of silently shipping fiction.
     */
    public function testTheWorkedExampleIsModeledOnAScreenThatMatchesTheExample(): void
    {
        if (preg_match('/modeled on src\/Screen\/([A-Za-z0-9]+)\.php/', self::readDoc(), $m) !== 1) {
            self::fail(
                'The Step-1 example no longer names exactly one "modeled on src/Screen/X.php" '
                . 'source; the guide and this pin move together.'
            );
        }

        $guideScreen = $m[1];
        $code = self::readFile('src/Screen/' . $guideScreen . '.php');

        self::assertStringContainsString(
            'Chrome::frame(',
            $code,
            $guideScreen . ' does not render through the shared Chrome the example renders through.'
        );
        self::assertStringContainsString(
            'ApiClient $api',
            $code,
            $guideScreen . ' does not inject the ApiClient the example injects.'
        );
        self::assertStringContainsString(
            'Cmd::promise',
            $code,
            $guideScreen . ' does not fetch on init via Cmd::promise the way the example does.'
        );
        self::assertStringContainsString(
            'NavigateBackMsg',
            $code,
            $guideScreen . ' does not return through NavigateBackMsg the way the example does.'
        );
    }

    /**
     * The guide's convention for a parameter of App::boot(): short class name,
     * leading `\` on nullable classes (the in-source style), then the default.
     */
    private function renderParameter(ReflectionParameter $param): string
    {
        $type = $param->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type, 'App::boot() gained a union type; rework this pin.');

        $name = $type->getName();
        $short = $type->isBuiltin() ? $name : self::lastSegment($name);
        $rendered = $type->allowsNull() && !$type->isBuiltin()
            ? '?\\' . $short
            : ($type->allowsNull() ? '?' . $short : $short);

        return $rendered . ' $' . $param->getName()
            . ($param->isDefaultValueAvailable() ? ' = ' . self::renderDefault($param) : '');
    }

    /**
     * The literal return-type token of App::boot() as declared in src/App.php.
     * Version-stable by construction: PHP 8.5 reflection resolves `self` to
     * the declaring FQCN, so the pin must not trust getName() for it.
     */
    private static function declaredBootReturnType(): string
    {
        $src = self::readFile('src/App.php');
        if (preg_match('/public static function boot\((?:[^{}]*)\): (\??[A-Za-z_\\\\]+) \{/s', $src, $m) === 1) {
            return $m[1];
        }

        throw new RuntimeException('App::boot() signature moved; re-derive declaredBootReturnType().');
    }

    /**
     * Every fenced ```php block in the guide, in order.
     *
     * @return list<string>
     */
    private static function phpBlocks(): array
    {
        preg_match_all('/```php\n(.*?)```/s', self::readDoc(), $matches);

        return $matches[1];
    }

    private static function lastSegment(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    private static function renderDefault(ReflectionParameter $param): string
    {
        $default = $param->getDefaultValue();

        return match (true) {
            $default === null => 'null',
            $default === true => 'true',
            $default === false => 'false',
            is_string($default) => "'" . $default . "'",
            $default === [] => '[]',
            default => throw new RuntimeException(
                'App::boot() default ' . var_export($default, true) . ' is not representable by this pin.'
            ),
        };
    }

    private static function readDoc(): string
    {
        return self::readFile(self::GUIDE);
    }

    private static function readFile(string $relative): string
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
