<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Docs;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use SugarCraft\Testing\Graphics\Mode;

/**
 * Guard against the M-CONSOLE-49 class (review console__writer): the graphics
 * doc described hand-made `SixelDecoder`/`Iterm2Decoder`/`KittyDecoder` test
 * doubles in present tense — classes the suite had already deleted as theater
 * (their own successor, PosterGraphicsStreamTest, says so in its header), and
 * presented them as the poster ENCODERS, a pipeline that in reality runs
 * MosaicFactory → sugar-image Mosaic → the protocol/cell renderers.
 *
 * Doctrine, per TokenFileNameDocsTest and ConfigVocabularyDocsTest: real state
 * is DERIVED from the code at runtime, never restated from prose. Eight laws:
 *
 * 1. README and docs/** name none of the retired decoder doubles. A doc
 *    re-advertising them reddens here until the prose AND this pin move again.
 * 2. src/, bin/ and tests/ carry none of those class names either (this file
 *    excepted — it must name them to forbid them). Re-introducing real doubles
 *    is allowed only together with a deliberate rewrite of the doc and both pins.
 * 3. The modes the graphics suite actually covers are derived by CALLING
 *    PosterGraphicsStreamTest's own data provider; for every yielded protocol
 *    the doc must name the matching sugar-testing `<Name>Stream` and that class
 *    must exist. A new provider mode with no doc coverage reddens here.
 * 4. Every backticked `tests/...php` path the doc cites exists on disk.
 * 5. Every encoder/renderer class the doc names on code terms (backticked
 *    short name) exists via the autoloader — a vendor rename reddens here and
 *    sends the doc and this list to move together.
 * 6. The Architecture diagram's edges match the code's. The mode is parsed from
 *    CLI argv in bin/phlix and turned into a Mosaic there at container-build
 *    time via MosaicFactory::forPosterGrid(); PlayerScreen is DOWNSTREAM (it
 *    opens with PosterLoader::protocol()/cellSize(), so it must not appear as
 *    the mode's source). The r1 review caught the diagram re-rooted on a
 *    "PlayerScreen —render mode→ MosaicFactory" edge the code contradicts; this
 *    law pins the true root so the diagram and the call graph move together.
 *    The r2 follow-up (rider R-CONSOLE-20) adds the box's SYMBOL truth: a
 *    box line truncated to fit may not invent `Mosaic::forPosterGrid` — the
 *    method belongs to MosaicFactory, which the box header already names.
 *    Rider R-CONSOLE-21 adds the edge's CHRONOLOGY truth: the argv parse is
 *    a top-level bin/phlix statement; buildContainer only CONSUMES the
 *    parsed mode, so the label must state the two steps, not one.
 * 7. The half-block colour depth is stated as 24-bit on every doc surface
 *    and matches what HalfBlockRenderer actually emits (Ansi::fgRgb/bgRgb);
 *    the fossil 16/256 claim reddens here (review M-CONSOLE-06).
 * 8. The Fallback Strategy prose is delegation-true (review M-CONSOLE-31,
 *    residual half): detection is attributed to the vendor seam the code
 *    actually calls (`Mosaic::auto()`), the retired DECRQSS / OSC 10/110/111
 *    and the invented cell-mode ladder fictions stay banned, and the
 *    precedence chain the doc states is byte-pinned to the vendor Mosaic
 *    docblock's own Precedence line.
 *
 * Historical CHANGELOG entries are exempt forever (history is verbatim); the
 * doc sweep deliberately never reads it.
 */
final class TerminalGraphicsDocsTest extends TestCase
{
    private const GRAPHICS_DOC = 'docs/terminal-graphics.md';

    /**
     * The retired test doubles (and fossil encoder identities) the doc must
     * never re-advertise.
     *
     * @var list<string>
     */
    private const DEAD_DOUBLES = ['SixelDecoder', 'Iterm2Decoder', 'KittyDecoder'];

    /**
     * Classes the cured doc cites for the real pipeline: short name => FQCN.
     *
     * @var array<string, string>
     */
    private const CITED_CLASSES = [
        'MosaicFactory' => 'Phlix\\Console\\Media\\MosaicFactory',
        'PosterLoader' => 'Phlix\\Console\\Media\\PosterLoader',
        'Capabilities' => 'Phlix\\Console\\Capabilities',
        'Mosaic' => 'SugarCraft\\Image\\Mosaic',
        'ImageSource' => 'SugarCraft\\Image\\ImageSource',
        'ImageLayer' => 'SugarCraft\\Image\\ImageLayer',
        'SixelRenderer' => 'SugarCraft\\Image\\Renderer\\SixelRenderer',
        'Iterm2Renderer' => 'SugarCraft\\Image\\Renderer\\Iterm2Renderer',
        'KittyRenderer' => 'SugarCraft\\Image\\Renderer\\KittyRenderer',
        'HalfBlockRenderer' => 'SugarCraft\\Image\\Renderer\\HalfBlockRenderer',
        'QuarterBlockRenderer' => 'SugarCraft\\Image\\Renderer\\QuarterBlockRenderer',
        'AsciiRenderer' => 'SugarCraft\\Image\\Renderer\\AsciiRenderer',
    ];

    public function testNoDocAdvertisesTheRetiredDecoderDoubles(): void
    {
        $hits = self::findDeadNames(self::proseFiles());

        self::assertSame(
            [],
            $hits,
            'README/docs name the retired graphics decoder doubles (review M-CONSOLE-49). '
            . 'The doubles were deleted as theater; describe the real harness '
            . '(PosterGraphicsStreamTest + sugar-testing protocol streams) instead: '
            . self::describe($hits)
        );
    }

    public function testCodeCarriesNoRetiredDecoderDoubles(): void
    {
        $hits = self::findDeadNames(self::codeFiles());

        self::assertSame(
            [],
            $hits,
            'the repo re-introduced the retired decoder double names in code (review M-CONSOLE-49). '
            . 'If real doubles for the graphics protocols are back deliberately, the doc, its '
            . 'Testing section, and DEAD_DOUBLES here must all move together: '
            . self::describe($hits)
        );
    }

    public function testDocNamesAStreamDecoderForEachCoveredGraphicsMode(): void
    {
        // Derive coverage from the suite's own provider — never from prose.
        $covered = [];
        foreach (self::streamTestProvider() as $label => $arguments) {
            $protocol = is_array($arguments) ? ($arguments[1] ?? null) : null;
            if (!$protocol instanceof Mode) {
                throw new RuntimeException(
                    'PosterGraphicsStreamTest row ' . $label . ' no longer yields a Mode as '
                    . 'its second provider argument — law 3 derives from that shape; inspect the provider.'
                );
            }

            $covered[$protocol->name] = true;
        }

        self::assertNotEmpty(
            $covered,
            'PosterGraphicsStreamTest yields no graphics protocols any more — the provider '
            . 'shape moved and this pin cannot derive coverage; update it deliberately.'
        );

        $doc = self::readRepoFile(self::GRAPHICS_DOC);

        foreach (array_keys($covered) as $caseName) {
            $fqcn = 'SugarCraft\\Testing\\Graphics\\' . $caseName . 'Stream';
            self::assertTrue(
                class_exists($fqcn),
                $fqcn . ' (derived from the provider\'s ' . $caseName . ' case) no longer exists; '
                . 'the doc Testing section and this pin need a deliberate update.'
            );
            self::assertMatchesRegularExpression(
                '/\b' . preg_quote($caseName, '/') . 'Stream\b/',
                $doc,
                'the graphics suite covers ' . $caseName . ' but ' . self::GRAPHICS_DOC
                . ' no longer names the ' . $caseName . 'Stream decoder that proves it.'
            );
        }
    }

    public function testEveryTestFileCitedByTheDocExists(): void
    {
        $doc = self::readRepoFile(self::GRAPHICS_DOC);

        $ok = preg_match_all('/`(tests\/[^`\s]+\.php)`/', $doc, $matches);
        if (!$ok || !isset($matches[1]) || $matches[1] === []) {
            throw new RuntimeException(
                self::GRAPHICS_DOC . ' cites no `tests/...php` path — the Testing section lost '
                . 'its anchors; this pin refuses to pass vacuously.'
            );
        }

        foreach (array_unique($matches[1]) as $relative) {
            self::assertFileExists(
                self::repoRoot() . '/' . $relative,
                self::GRAPHICS_DOC . ' cites ' . $relative . ' which no longer exists on disk.'
            );
        }
    }

    /**
     * Law 5, and the tolerance the M-CONSOLE-49 r1 review asked to have on
     * record (rider R-CONSOLE-22, "worth a docblock word"): the doc-side
     * check is a word-boundary regex on the short name, so it is DELIBERATELY
     * satisfied by unbackticked mentions too — the Architecture diagram's box
     * text (the MosaicFactory header, the ImageSource row, the renderer row)
     * counts just like the backticked prose mentions. The law guards that
     * every class the poster pipeline is built on stays NAMED in the doc —
     * the vendor-rename and doc-sweep alarm this file exists for — not how a
     * mention is typeset. A backtick-requiring regex would redden on
     * legitimate box-width reality: ASCII art cannot carry backticks without
     * tearing the diagram's column alignment. Today no mention form is
     * load-bearing alone — all twelve cited names carry a backticked prose
     * mention at tip — so the tolerance costs nothing now and keeps the
     * diagram's own boxes from being deleted out from under a future prose
     * edit. The class-exists half of the law is unaffected by this note.
     */
    public function testEncoderClassesTheDocNamesStillExist(): void
    {
        $doc = self::readRepoFile(self::GRAPHICS_DOC);

        foreach (self::CITED_CLASSES as $shortName => $fqcn) {
            self::assertTrue(
                class_exists($fqcn),
                $fqcn . ' was renamed or removed; ' . self::GRAPHICS_DOC
                . ' and CITED_CLASSES here must follow together.'
            );
            self::assertMatchesRegularExpression(
                '/\b' . preg_quote($shortName, '/') . '\b/',
                $doc,
                self::GRAPHICS_DOC . ' no longer names ' . $shortName
                . ' — keep the encoder prose on code terms with the real pipeline.'
            );
        }
    }

    public function testTheArchitectureDiagramEdgesMatchTheCodeEdges(): void
    {
        // Law 6, derived from the call graph — never from prose. Review
        // M-CONSOLE-49 r1 caught the diagram rooted on a "PlayerScreen —render
        // mode→ MosaicFactory" edge the code contradicts; these checks pin the
        // true direction so a re-rooted fiction reddens here.
        $bin = self::readRepoFile('bin/phlix');
        self::assertStringContainsString(
            "'--mode='",
            $bin,
            'bin/phlix no longer parses a --mode= argv flag — the CLI-argv root of the '
            . self::GRAPHICS_DOC . ' Architecture diagram (and law 6) must move together.'
        );
        self::assertStringContainsString(
            'MosaicFactory::forPosterGrid($mode)',
            $bin,
            'bin/phlix no longer builds the poster Mosaic at container-build time; the '
            . 'diagram root claims it does, so doc, code and this law must move together.'
        );

        $player = self::readRepoFile('src/Screen/PlayerScreen.php');
        self::assertStringNotContainsString(
            'MosaicFactory',
            $player,
            'PlayerScreen now references MosaicFactory. If the player really became the '
            . 'mode source upstream of the factory, the diagram root, this law and review '
            . 'M-CONSOLE-49 must all be revisited deliberately — until then the player is '
            . 'a downstream consumer of PosterLoader::protocol().'
        );

        $app = self::readRepoFile('src/App.php');
        self::assertStringContainsString(
            'PlayerScreen::productionFactory($this->posters->protocol()',
            $app,
            'src/App.php no longer opens PlayerScreen from PosterLoader::protocol(); the '
            . 'doc note below the diagram states that edge, so doc, code and law 6 move together.'
        );

        $doc = self::readRepoFile(self::GRAPHICS_DOC);

        // Review M-CONSOLE-49 r2 N1 (rider R-CONSOLE-20): the diagram's second
        // box once read `Mosaic::forPosterGrid($mode)` — a box-width truncation
        // that invented a symbol. forPosterGrid lives on MosaicFactory (the box
        // header), never on the vendor Mosaic. Ban the phantom prefix so a
        // re-truncated box line reddens here. The literal is exact:
        // `MosaicFactory::forPosterGrid` does NOT contain `Mosaic::forPosterGrid`,
        // so a future fully-spelled class name stays green on its own merits.
        self::assertStringNotContainsString(
            'Mosaic::forPosterGrid',
            $doc,
            self::GRAPHICS_DOC . ' re-claims `Mosaic::forPosterGrid` — no such symbol '
            . '(review M-CONSOLE-49 r2 N1). The method belongs to MosaicFactory: under the '
            . 'box header spell the line forPosterGrid($mode), or the full class if it fits.'
        );

        // Review M-CONSOLE-49 r2 N2 (rider R-CONSOLE-21): the edge label once
        // read "mode string, parsed at / container build (no screen yet)" —
        // a chronology compression. The argv parse is NOT part of the
        // container build: it runs as column-0 (file-scope) statements at
        // bin/phlix top level ($KNOWN_MODES :653, the $argv flag foreach
        // :657), and the parsed $mode is only CONSUMED later, inside
        // buildContainer() — MosaicFactory::forPosterGrid($mode) at :110 —
        // which the top-level dispatch reaches only when a command needs
        // the graph (:787). The CHANGELOG has stated it exactly right all
        // along ("parsed from CLI argv and consumed at container-build
        // time"); the diagram now mirrors the two-step phrasing. The ban
        // reddens a revert to the single-step label; the pins hold the
        // two verbs present in order with their true locations; the
        // code-side check derives top-level-ness from whitespace — a parse
        // hoisted into a function no longer matches the column-0 literal.
        self::assertStringNotContainsString(
            'parsed at',
            $doc,
            self::GRAPHICS_DOC . ' re-claims the mode is "parsed at container build" — '
            . 'review M-CONSOLE-49 r2 N2: the parse runs at bin/phlix top level and the '
            . 'container build only consumes it. Mirror the two-step phrasing.'
        );
        self::assertMatchesRegularExpression(
            '/parsed from CLI argv[^\n]*\n\s*│\s*consumed in buildContainer/',
            $doc,
            self::GRAPHICS_DOC . ' no longer states the edge chronology in two steps '
            . '("parsed from CLI argv" then "consumed in buildContainer", adjacent label '
            . 'lines) — review M-CONSOLE-49 r2 N2.'
        );
        $knownModesAtTop = "\n" . '$KNOWN_MODES = [';
        self::assertStringContainsString(
            $knownModesAtTop,
            $bin,
            'bin/phlix no longer starts the mode parse as a column-0 (top-level) statement; '
            . 'the edge label says parse and container-build consumption are separate steps, '
            . 'so code, label and this law must move together (review M-CONSOLE-49 r2 N2).'
        );

        foreach (['forPosterGrid', 'PlayerScreen', 'productionFactory'] as $anchor) {
            self::assertMatchesRegularExpression(
                '/\b' . preg_quote($anchor, '/') . '\b/',
                $doc,
                self::GRAPHICS_DOC . ' no longer names ' . $anchor . ' — keep the diagram '
                . 'rooted at the CLI mode source with PlayerScreen shown downstream (law 6).'
            );
        }
    }

    /**
     * M-CONSOLE-06: the half-block colour-depth claim must say the same
     * truecolor thing on all three doc surfaces — the graphics protocol
     * table, the README mode table, and the manual's Render modes row —
     * and must match the code: HalfBlockRenderer tints every cell through
     * Ansi::fgRgb()/Ansi::bgRgb() (24-bit escapes, no palette quantizing
     * path). The fossil table cell claimed "16/256" and contradicted the
     * other two docs; re-inverting either side reddens here.
     */
    public function testHalfBlockDepthIsStatedConsistentlyAcrossDocs(): void
    {
        $renderer = self::repoFile('vendor/sugarcraft/sugar-image/src/Renderer/HalfBlockRenderer.php');
        self::assertStringContainsString(
            'Ansi::fgRgb(',
            $renderer,
            'HalfBlockRenderer no longer tints cells through Ansi::fgRgb() — '
            . 'the half-block depth claims in three docs need re-deriving.'
        );

        $surfaces = [
            'docs/terminal-graphics.md' => '/^\| \*\*Half-block\*\*.*$/m',
            'README.md' => '/^\| `halfblock`.*$/m',
            'docs/clients/console.md' => '/^\| `halfblock`.*$/m',
        ];
        foreach ($surfaces as $relative => $rowPattern) {
            $text = self::repoFile($relative);
            if (!preg_match($rowPattern, $text, $row)) {
                throw new RuntimeException(
                    $relative . ' lost the half-block mode row this law guards.'
                );
            }

            self::assertStringContainsString(
                '24-bit',
                $row[0],
                $relative . ' no longer states the half-block row as 24-bit.'
            );
        }

        foreach (['docs/terminal-graphics.md', 'README.md', 'docs/clients/console.md'] as $relative) {
            self::assertStringNotContainsString(
                '16/256',
                self::repoFile($relative),
                $relative . ' re-claims a 16/256 colour half-block — the '
                . 'renderer emits per-cell Ansi::fgRgb()/Ansi::bgRgb() truecolor.'
            );
        }
    }

    /**
     * M-CONSOLE-31 (residual half of review m5): the Fallback Strategy prose
     * must be delegation-true. src/Capabilities.php issues no terminal queries
     * itself — it asks sugar-image's Mosaic::auto(), and the probing machinery
     * (env table first, then a DA1 device-attributes query to fill gaps the
     * env leaves, XTWINOPS cell-size replies) lives vendor-side. Code side:
     * Capabilities is pinned to the delegation and to zero DECRQSS. Doc side:
     * the retired "DECRQSS … OSC 10/110/111" attribution and the invented
     * halfblock → quarterblock → ansi256 → ascii ladder stay banned, and the
     * precedence chain the doc claims must be byte-identical to the vendor
     * Mosaic docblock's own Precedence line, so a vendor reorder reddens the
     * prose too instead of letting it drift into fiction.
     */
    public function testFallbackProseIsDelegationTrue(): void
    {
        $cap = self::repoFile('src/Capabilities.php');
        self::assertStringContainsString(
            'Mosaic::auto()',
            $cap,
            'src/Capabilities.php no longer delegates detection to Mosaic::auto(); the doc '
            . 'Fallback section and this law must be re-derived from the new code shape.'
        );
        self::assertStringNotContainsString(
            'DECRQSS',
            $cap,
            'src/Capabilities.php now issues DECRQSS queries itself. If the repo really grew '
            . 'its own probing, the delegation prose and this law move together deliberately.'
        );

        $doc = self::readRepoFile(self::GRAPHICS_DOC);
        self::assertMatchesRegularExpression(
            '/`Mosaic::auto\(\)`/',
            $doc,
            'the graphics doc no longer names `Mosaic::auto()` as the detection seam — '
            . 'review M-CONSOLE-31: attribute detection to the vendor delegation, not to Capabilities.'
        );
        foreach (
            [
                'DECRQSS',
                'OSC 10/110/111',
                'halfblock → quarterblock → ansi256 → ascii',
            ] as $fiction
        ) {
            self::assertStringNotContainsString(
                $fiction,
                $doc,
                'the graphics doc re-claims "' . $fiction . '" — retired fiction per review '
                . 'M-CONSOLE-31 (the vendor probes are DA1/XTWINOPS; no cell-mode fallback ladder '
                . 'ever existed — quarterblock/ansi256/ascii are explicit --mode picks).'
            );
        }

        $mosaic = self::repoFile('vendor/sugarcraft/sugar-image/src/Mosaic.php');
        if (!preg_match('/^\s*\*\s*Precedence: (kitty[^\n]*)$/m', $mosaic, $m)) {
            throw new RuntimeException(
                'sugar-image Mosaic lost the "Precedence: kitty …" docblock line law 8 derives '
                . 'from — re-derive the doc Fallback chain from the vendor source deliberately.'
            );
        }

        self::assertStringContainsString(
            rtrim($m[1]),
            $doc,
            'the doc Fallback precedence chain no longer matches the vendor Mosaic docblock '
            . '(vendor states "' . rtrim($m[1]) . '"); doc and vendor prose move together.'
        );
    }

    private static function repoFile(string $relative): string
    {
        $contents = @file_get_contents(self::repoRoot() . '/' . $relative);
        if ($contents === false) {
            throw new RuntimeException('cannot read repo file: ' . $relative);
        }

        return $contents;
    }

    /**
     * The provider lives in the graphics suite; call it through a tiny probe
     * so this file stays the only place allowed to spell the dead names.
     */
    private static function streamTestProvider(): iterable
    {
        $class = 'Phlix\\Console\\Tests\\Graphics\\PosterGraphicsStreamTest';
        if (!class_exists($class)) {
            throw new RuntimeException($class . ' vanished; law 3 of ' . self::class . ' derives from it.');
        }

        return $class::graphicsModeProvider();
    }

    /**
     * Prose side: README.md plus every .md under docs/ (CHANGELOG.md is
     * deliberately unreachable here — history stays verbatim).
     *
     * @return array<string, string>
     */
    private static function proseFiles(): array
    {
        $files = ['README.md' => self::readRepoFile('README.md')];
        foreach (self::scannedFiles(['docs'], ['md']) as $relative => $text) {
            $files[$relative] = $text;
        }

        return $files;
    }

    /**
     * Code side: src/, bin/, tests/ — with this guard file excepted, since it
     * must name the dead classes to forbid them.
     *
     * @return array<string, string>
     */
    private static function codeFiles(): array
    {
        $files = [];
        $self = 'tests/Docs/' . basename(__FILE__);
        foreach (self::scannedFiles(['src', 'bin', 'tests'], ['php', '']) as $relative => $text) {
            if ($relative === $self) {
                continue;
            }

            $files[$relative] = $text;
        }

        return $files;
    }

    /**
     * @param list<string> $dirs
     * @param list<string> $extensions empty-string extension admits extensionless bin/phlix
     * @return array<string, string>
     */
    private static function scannedFiles(array $dirs, array $extensions): array
    {
        $root = self::repoRoot();
        $files = [];

        foreach ($dirs as $dir) {
            $absolute = $root . '/' . $dir;
            if (!is_dir($absolute)) {
                throw new RuntimeException($dir . '/ disappeared; update ' . self::class . '.');
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            /** @var SplFileInfo $info */
            foreach ($iterator as $info) {
                if (!$info->isFile()) {
                    continue;
                }

                if (!in_array($info->getExtension(), $extensions, true)) {
                    continue;
                }

                $relative = ltrim(str_replace($root, '', $info->getPathname()), '/');
                $contents = file_get_contents($info->getPathname());
                if ($contents === false) {
                    throw new RuntimeException('could not read ' . $relative);
                }

                $files[$relative] = $contents;
            }
        }

        return $files;
    }

    /**
     * @param array<string, string> $files
     * @return list<string> "file:line" hits for every dead-name occurrence
     */
    private static function findDeadNames(array $files): array
    {
        $hits = [];
        foreach ($files as $relative => $text) {
            foreach (explode("\n", $text) as $number => $line) {
                foreach (self::DEAD_DOUBLES as $dead) {
                    if (str_contains($line, $dead)) {
                        $hits[] = $relative . ':' . ($number + 1) . ' ' . $dead;
                    }
                }
            }
        }

        return $hits;
    }

    /**
     * @param list<string> $hits
     */
    private static function describe(array $hits): string
    {
        return implode('; ', $hits);
    }

    private static function readRepoFile(string $relative): string
    {
        $path = self::repoRoot() . '/' . $relative;
        if (!is_file($path)) {
            throw new RuntimeException($relative . ' moved or was deleted; update ' . self::class . '.');
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('could not read ' . $relative);
        }

        return $contents;
    }

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
