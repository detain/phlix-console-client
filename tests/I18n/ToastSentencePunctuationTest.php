<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\I18n;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Toast punctuation law, in two ratified halves:
 *
 * 1. M-CONSOLE-09 (review console__copywriter m2): the composed exception
 *    family is normalized at runtime by ExceptionSentences::terminate(); the
 *    ERROR/INFO/WARNING literals that bypass it must end their sentence, and
 *    a colon-terminated literal is a prefix template whose tail is dynamic
 *    prose ('Failed to X: ') — such a prefix may carry trailing whitespace
 *    before the concatenation ('SyncPlay: ' . $detail).
 * 2. CU-029 (review console__writer #3, wave w12 L1): SUCCESS toasts take NO
 *    trailing period — the period-less success sentences ('Profile created',
 *    'Tuners rescanned', 'Plugin installed') were the majority and this trip
 *    was the drift. Error-path punctuation stays as law (1) ratified it.
 *
 * Both halves are pinned here — directly, for every
 * `ShowToastMsg::kind('<literal>')` call site parsed from source with
 * token_get_all (never restated from prose), and by named guard for the
 * ternary-fallback / mutationCmd-label shapes the parser cannot see through.
 *
 * The scanner leg is itself tripwired: testTheScannerLegConvictsItsOwnFixture
 * runs it over an embedded four-arm source and pins the exact conviction
 * list. That fixture is the measure-from-red the leg lacked for two eras —
 * a string-vs-array token compare (`$tokens[$j] !== '::'`, where '::'
 * arrives as a T_DOUBLE_COLON array token) had silently blinded the whole
 * scan, and a planted violation survived green [review w12 L1 round-2 Major].
 */
final class ToastSentencePunctuationTest extends TestCase
{
    /**
     * Sentence-final characters; ':' marks a concatenation prefix template
     * (whose trailing space before the `.` is lawful). One ellipsis form
     * only [review w12 L1 round-2 info 2].
     */
    private const TERMINATED = '/[.!?:…]\s*$/u';

    /**
     * The four ShowToastMsg factories, grouped by the punctuation law they
     * follow: 'success' is the CU-029 period-less family; the rest keep the
     * M-CONSOLE-09 terminated-sentence family.
     */
    private const SUCCESS_KIND = 'success';

    /**
     * Direct string-literal first arguments of ShowToastMsg factories in src/
     * must follow their kind's law: error/info/warning sentences end with
     * punctuation (a ':' prefix template counts); success sentences carry no
     * trailing punctuation at all.
     */
    public function testEveryDirectToastLiteralFollowsItsKindsPunctuationLaw(): void
    {
        $violations = [];
        foreach (self::phpFiles() as $file) {
            foreach (self::scanSource((string) file_get_contents($file)) as $violation) {
                $violations[] = basename($file) . ' ' . $violation;
            }
        }

        $this->assertSame([], $violations, 'toast punctuation drift: ' . implode(', ', $violations));
    }

    /**
     * Tripwire-theater cure [review w12 L1 round-2 Major]: the scanner must
     * convict on a known-bad fixture, so a future regression that blinds it
     * (an inverted token compare, a dropped arm) reddens here even while
     * src/ happens to be clean. The four arms pin both directions: the two
     * violations named, the two lawful shapes refused.
     */
    public function testTheScannerLegConvictsItsOwnFixture(): void
    {
        $source = "<?php\n"
            . "ShowToastMsg::success('Saved the record.');\n"
            . "ShowToastMsg::error('Nothing happened');\n"
            . "ShowToastMsg::success('Tuners rescanned');\n"
            . "ShowToastMsg::warning('Failed to load: ' . \$e);\n";

        $this->assertSame([
            "2: 'Saved the record.' (a success toast must carry no trailing punctuation)",
            "3: 'Nothing happened' (a error toast must end its sentence)",
        ], self::scanSource($source));
    }

    /**
     * The two App-level playback failure consts feed ShowToastMsg through
     * `self::CONST` — invisible to the literal scan, so their RUNTIME value
     * is pinned here.
     */
    public function testAppPlaybackFailureConstsAreTerminatedSentences(): void
    {
        $reflector = new ReflectionClass(\Phlix\Console\App::class);
        foreach (['PLAY_TRACK_FAILED', 'PLAY_AUDIOBOOK_FAILED'] as $name) {
            $constant = $reflector->getReflectionConstant($name);
            $this->assertNotFalse($constant, "App::{$name} must exist");
            $value = $constant->getValue();
            $this->assertIsString($value);
            $this->assertSame(
                1,
                preg_match(self::TERMINATED, $value),
                "App::{$name} ('{$value}') must end its sentence",
            );
        }
    }

    /**
     * Named SUCCESS fallbacks the parser cannot see through (mutationCmd
     * labels, ternary-fallback acks) — each pinned period-less, the
     * terminated shape explicitly refused [CU-029].
     */
    public function testNamedSuccessFallbacksCarryNoTrailingPeriod(): void
    {
        $pins = [
            'src/Screen/AdminUsersScreen.php' => ['User created', 'User updated', 'Done'],
            'src/Screen/AdminLibrariesScreen.php' => ['Library job queued'],
            'src/Screen/AdminUserProfilesScreen.php' => ['Done'],
            'src/Screen/ParentalControlsScreen.php' => ['Done'],
            'src/Screen/SharedWithMeScreen.php' => ['Share accepted', 'Share rejected', 'Done'],
            'src/Screen/FederationSharesScreen.php' => ['Share revoked', 'Done'],
        ];
        foreach ($pins as $relative => $sentences) {
            $source = (string) file_get_contents(__DIR__ . '/../../' . $relative);
            foreach ($sentences as $sentence) {
                $this->assertStringContainsString(
                    "'{$sentence}'",
                    $source,
                    "{$relative}: success fallback '{$sentence}' must be present",
                );
                $this->assertStringNotContainsString(
                    "'{$sentence}.",
                    $source,
                    "{$relative}: the period-ful success fallback '{$sentence}.' must not come back",
                );
            }
        }
    }

    /**
     * Named NON-toast fallbacks (rendered as screen body prose or error
     * lines, invisible to the literal scan): these keep the terminated
     * sentence law — the CU-029 period-less law is for success TOASTS only,
     * so the un-terminated shape is the regression being refused here.
     */
    public function testNamedFallbackSentencesOutsideSuccessToastsStayTerminated(): void
    {
        $pins = [
            'resources/lang/en.php' => ['Could not complete the action'],
            'src/Screen/AdminServerRestartScreen.php' => ['Restart signal sent'],
        ];
        foreach ($pins as $relative => $sentences) {
            $source = (string) file_get_contents(__DIR__ . '/../../' . $relative);
            foreach ($sentences as $sentence) {
                $this->assertStringContainsString(
                    "'{$sentence}.",
                    $source,
                    "{$relative}: body/error sentence '{$sentence}' must stay terminated",
                );
            }
        }
    }

    /**
     * The docblock echo of the subtitle toast must carry the same string as
     * the code (review-era drift: prose quoted the old period-less form).
     * Error-family toast: the terminated law (1) applies.
     */
    public function testSubtitleToastDocblockEchoesTheTerminatedLiteral(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Screen/PlayerScreen.php');

        $this->assertStringContainsString("'Could not load the subtitles.'", $source);
        $this->assertStringNotContainsString("'Could not load subtitles'", $source);
    }

    /**
     * Punctuation law for one source: every `ShowToastMsg::kind('literal'…)`
     * whose first argument opens with a string literal must follow its
     * kind's law. Line-numbered, human-readable violations.
     *
     * @return list<string> one entry per convicted call site
     */
    private static function scanSource(string $source): array
    {
        $violations = [];
        $tokens = token_get_all($source);
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'ShowToastMsg') {
                continue;
            }
            $j = self::nextMeaningful($tokens, $i + 1);
            if ($j === null || !is_array($tokens[$j]) || $tokens[$j][0] !== T_DOUBLE_COLON) {
                continue;
            }
            $k = self::nextMeaningful($tokens, $j + 1);
            if (
                $k === null || !is_array($tokens[$k]) || $tokens[$k][0] !== T_STRING
                || !in_array($tokens[$k][1], ['error', 'success', 'info', 'warning'], true)
            ) {
                continue;
            }
            $kind = $tokens[$k][1];
            $open = self::nextMeaningful($tokens, $k + 1);
            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }
            $lit = self::nextMeaningful($tokens, $open + 1);
            if ($lit === null || !is_array($tokens[$lit]) || $tokens[$lit][0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue; // dynamic first arg: self::CONST, ternary, concatenation…
            }
            $raw = $tokens[$lit][1];
            $inner = substr($raw, 1, -1);
            $isTerminated = preg_match(self::TERMINATED, $inner) === 1;
            if ($kind === self::SUCCESS_KIND ? $isTerminated : !$isTerminated) {
                $law = $kind === self::SUCCESS_KIND
                    ? 'a success toast must carry no trailing punctuation'
                    : 'a ' . $kind . ' toast must end its sentence';
                $violations[] = $tokens[$lit][2] . ": '{$inner}' ({$law})";
            }
        }

        return $violations;
    }

    /** @return iterable<string> every src/ PHP file, path-normalized */
    private static function phpFiles(): iterable
    {
        $root = new RecursiveDirectoryIterator(
            __DIR__ . '/../../src',
            RecursiveDirectoryIterator::SKIP_DOTS,
        );
        foreach (new RecursiveIteratorIterator($root) as $info) {
            if ($info->isFile() && $info->getExtension() === 'php') {
                yield (string) $info->getPathname();
            }
        }
    }

    /**
     * @param array<int, mixed> $tokens
     *
     * @return int|null next non-whitespace, non-comment index
     */
    private static function nextMeaningful(array $tokens, int $from): ?int
    {
        for ($i = $from, $n = count($tokens); $i < $n; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }
}
