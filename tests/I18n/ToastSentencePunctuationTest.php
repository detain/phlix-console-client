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
 * Regression tests for review console__copywriter m2 (M-CONSOLE-09): the
 * toast family carried two grammars side by side — period-less standalone
 * sentences ('Could not play this track', 'User updated') living beside
 * period-ed siblings ('Setting updated.', 'Server removed.', 'PIN must be
 * 4 or 6 digits.').
 *
 * Ratified law (the review's own suggestion): a user-facing sentence always
 * ends in sentence punctuation; a colon-terminated literal is a prefix
 * template whose tail is dynamic prose ('Failed to X: ' — its M-CONSOLE-08
 * template-family law is a separate item), and labels/field titles never
 * take a period. The composed exception family is normalized at runtime by
 * ExceptionSentences::terminate(); the bare literals that bypass it are
 * pinned here — directly, for every `ShowToastMsg::kind('<literal>')` call
 * site parsed from source with token_get_all (never restated from prose),
 * and by named guard for the const / fallback shapes the parser cannot see
 * through.
 */
final class ToastSentencePunctuationTest extends TestCase
{
    /** Sentence-final characters; ':' marks a concatenation prefix template. */
    private const TERMINATED = '/[.!?:…\x{2026}]$/u';

    /**
     * Every direct string-literal first argument of a ShowToastMsg factory
     * in src/ must be a terminated sentence or an explicit ':' prefix.
     */
    public function testEveryDirectToastLiteralEndsItsSentence(): void
    {
        $violations = [];
        foreach (self::phpFiles() as $file) {
            $tokens = token_get_all((string) file_get_contents($file));
            $count = count($tokens);
            for ($i = 0; $i < $count; $i++) {
                $token = $tokens[$i];
                if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'ShowToastMsg') {
                    continue;
                }
                $j = self::nextMeaningful($tokens, $i + 1);
                if ($j === null || $tokens[$j] !== '::') {
                    continue;
                }
                $k = self::nextMeaningful($tokens, $j + 1);
                if (
                    $k === null || !is_array($tokens[$k]) || $tokens[$k][0] !== T_STRING
                    || !in_array($tokens[$k][1], ['error', 'success', 'info', 'warning'], true)
                ) {
                    continue;
                }
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
                if (preg_match(self::TERMINATED, $inner) !== 1) {
                    $violations[] = basename($file) . ':' . $tokens[$lit][2] . " '{$inner}'";
                }
            }
        }

        $this->assertSame([], $violations, 'unterminated toast sentence literals: ' . implode(', ', $violations));
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
     * Named fallback literals the parser cannot see through: mutationCmd /
     * ternary-fallback / ack sites, each pinned in its terminated form and
     * the un-terminated shape explicitly refused.
     */
    public function testNamedFallbackLiteralsAreTerminated(): void
    {
        $pins = [
            'src/Screen/AdminUsersScreen.php' => ['User updated', 'User created'],
            'src/Screen/AdminLibrariesScreen.php' => ['Library job queued'],
            'src/Ui/SyncPlayModal.php' => ['Could not complete the action'],
            'src/Screen/AdminServerRestartScreen.php' => ['Restart signal sent'],
        ];
        foreach ($pins as $relative => $sentences) {
            $source = (string) file_get_contents(__DIR__ . '/../../' . $relative);
            foreach ($sentences as $sentence) {
                $this->assertStringContainsString(
                    "'{$sentence}.",
                    $source,
                    "{$relative}: '{$sentence}' must be a terminated sentence",
                );
                $this->assertStringNotContainsString(
                    "'{$sentence}',",
                    $source,
                    "{$relative}: the un-terminated '{$sentence}' literal must not come back",
                );
            }
        }
    }

    /**
     * The docblock echo of the subtitle toast must carry the same string as
     * the code (review-era drift: prose quoted the old period-less form).
     */
    public function testSubtitleToastDocblockEchoesTheTerminatedLiteral(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Screen/PlayerScreen.php');

        $this->assertStringContainsString("'Could not load subtitles.'", $source);
        $this->assertStringNotContainsString("'Could not load subtitles'", $source);
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
