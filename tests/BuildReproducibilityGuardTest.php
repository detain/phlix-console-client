<?php

/**
 * Guard for the stable-PHAR reproducibility design (2026-11-11).
 *
 * Baseline forensics on two consecutive `box compile` runs of the same tree:
 * identical sizes, identical entry names/order/mtimes (box normalizes mtimes
 * to the epoch), but 112 differing bytes — the whole nondeterminism class was
 * box's random auto-generated stub alias (`box-auto-generated-alias-<12hex>`
 * at four stub sites, 12 B each) plus its 64 B phar-signature cascade. The
 * fix is the pinned `"alias": "phlix.phar"` in box.json; after it, two builds
 * are byte-identical.
 *
 * Doctrine (shared with BuildPharScriptGuardTest): the census is DERIVED from
 * the files on disk, never restated from prose. Removing the alias, the CI
 * rebuild gate, or the script's x2 assert reddens exactly one named test
 * here — the design may not die silently.
 */

declare(strict_types=1);

namespace Phlix\Console\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BuildReproducibilityGuardTest extends TestCase
{
    private const BOX_JSON = 'box.json';

    private const BUILD_WORKFLOW = '.github/workflows/build.yml';

    private const BUILD_SCRIPT = 'scripts/build-phar.sh';

    /** The exact step name the workflow gate must carry. */
    private const GATE_STEP_NAME = 'Assert reproducible phar build';

    public function testTheBoxConfigPinsThePharAlias(): void
    {
        $decoded = json_decode(
            (string) file_get_contents(self::repoPath(self::BOX_JSON)),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertIsArray($decoded);
        self::assertSame(
            'phlix.phar',
            $decoded['alias'] ?? null,
            self::BOX_JSON . ': the pinned "alias" disappeared. Without it box generates a '
            . 'random stub alias per build (the stable-PHAR nondeterminism class): the phar '
            . 'hash stops being reproducible and the x2 rebuild gate reddens CI.'
        );
        self::assertMatchesRegularExpression(
            '/^[A-Za-z0-9._-]+\.phar$/',
            (string) $decoded['alias'],
            self::BOX_JSON . ': "alias" must be a plain .phar filename (box embeds it in the '
            . 'stub mapPhar call; paths or empty aliases break self-referencing phar:// URLs).'
        );
    }

    public function testTheWorkflowCarriesTheReproducibleBuildGate(): void
    {
        $text = self::read(self::BUILD_WORKFLOW);
        $step = self::workflowStepBody($text, self::GATE_STEP_NAME);

        // The gate compiles a second time...
        self::assertStringContainsString('box compile', $step);
        // ...hashes before and after...
        self::assertStringContainsString('BEFORE=$(sha256sum build/phlix.phar', $step);
        self::assertStringContainsString('AFTER=$(sha256sum build/phlix.phar', $step);
        // ...and fails loud, named, on a mismatch.
        self::assertStringContainsString('if [ "$BEFORE" != "$AFTER" ]; then', $step);
        self::assertStringContainsString('exit 1', $step);
        self::assertStringNotContainsString(
            'continue-on-error',
            $step,
            'the reproducibility step gained a continue-on-error override — that neuters the gate.'
        );

        // Whole-file posture: this workflow never carried a continue-on-error.
        self::assertStringNotContainsString(
            'continue-on-error',
            $text,
            self::BUILD_WORKFLOW . ': a continue-on-error appeared — no gate here may be muted.'
        );

        // Exactly two compiles: the build leg and the rebuild gate. Counted
        // LINE-ANCHORED (an invocation line, optionally `run:`-prefixed), not
        // by naive substring: the gate's own ::error:: prose contains the
        // words "box compile" and a raw substr_count would convict that prose
        // as a third invocation. Prose is not a call site; only anchored
        // command lines are.
        self::assertSame(
            2,
            preg_match_all('/^\s*(?:run: )?box compile$/m', $text),
            self::BUILD_WORKFLOW . ': expected exactly two `box compile` invocations '
            . '(build + reproducibility rebuild).'
        );
    }

    public function testTheBuildScriptCompilesTwiceAndComparesSha(): void
    {
        $code = self::executableCode();

        // The box 3 relic flag must stay gone from EXECUTABLE code (box ^4.7
        // has no --no-progress). The script's explanatory comment names the
        // retired option to record WHY it is absent — prose naming a retired
        // command is fine, per the BuildPharScriptGuardTest precedent; the
        // walk below strips it.
        self::assertStringNotContainsString(
            '--no-progress',
            $code,
            self::BUILD_SCRIPT . ': the dead `--no-progress` option is back in executable code; '
            . 'humbug/box ^4 rejects it and the script dies before producing any phar.'
        );

        self::assertSame(
            2,
            substr_count($code, '"$BOX_BIN" compile'),
            self::BUILD_SCRIPT . ': expected exactly two box compiles (build + x2 reproducibility assert).'
        );
        self::assertStringContainsString('phar build is not reproducible', $code);
        self::assertStringContainsString('sha256sum', $code);
    }

    /**
     * The extension ladder must probe case-insensitively [stable-PHAR].
     * `php -m` lists builtins in engine display case — "Phar", not "phar" —
     * so the historical `grep -q "^phar$"` failed the script's OWN required
     * extension and every local build died before box ran. A silent revert
     * to the case-sensitive probe must not be possible.
     */
    public function testTheExtensionProbeIsCaseInsensitive(): void
    {
        $code = self::executableCode();

        self::assertStringNotContainsString(
            'grep -q "',
            $code,
            self::BUILD_SCRIPT . ': the case-sensitive extension probe is back; php -m prints '
            . '"Phar" and the ladder would reject its own required extension again.'
        );
        self::assertStringContainsString(
            'grep -qi "',
            $code,
            self::BUILD_SCRIPT . ': the -i flag on the php -m probe disappeared — same defect, other direction.'
        );
    }

    /**
     * The build script with full-line comments removed: `#` introducers
     * (leading whitespace allowed) and the shebang — the same walk shape
     * BuildPharScriptGuardTest uses, so explanatory prose never pollutes
     * the executable-code census.
     *
     * @return non-empty-string
     */
    private static function executableCode(): string
    {
        $lines = explode("\n", self::read(self::BUILD_SCRIPT));
        $code = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*#/', $line) === 1) {
                continue;
            }
            $code[] = $line;
        }

        $stripped = implode("\n", $code);
        if ($stripped === '') {
            throw new RuntimeException('The executable-code walk emptied ' . self::BUILD_SCRIPT);
        }

        return $stripped;
    }

    /**
     * Slice one workflow step out of a YAML text by its "- name: " label, up to
     * the next step label in the same job (or end of file).
     */
    private static function workflowStepBody(string $yaml, string $stepName): string
    {
        foreach (explode('- name: ', $yaml) as $chunk) {
            if (str_starts_with($chunk, $stepName)) {
                return $chunk;
            }
        }

        self::fail(
            self::BUILD_WORKFLOW . ': step "' . $stepName . '" not found — the reproducibility '
            . 'gate was renamed or removed; the stable-PHAR x2 sha256 leg is load-bearing.'
        );
    }

    private static function read(string $relative): string
    {
        $content = file_get_contents(self::repoPath($relative));
        if (!is_string($content)) {
            throw new RuntimeException("Unreadable {$relative} — run from the repository root.");
        }

        return $content;
    }

    private static function repoPath(string $relative): string
    {
        $path = dirname(__DIR__) . '/' . $relative;
        if (!is_file($path)) {
            throw new RuntimeException("Expected {$relative} to exist at {$path}.");
        }

        return $path;
    }
}
