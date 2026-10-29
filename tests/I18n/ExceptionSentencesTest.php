<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\I18n;

use Phlix\Console\Api\ApiError;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\NetworkError;
use Phlix\Console\I18n\ExceptionSentences;
use Phlix\Console\Ui\Chrome;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for review console__copywriter [M6]: raw exception/server
 * text must never be appended whole into end-user toasts (internals leak),
 * and composition sites must never nest two sentence prefixes
 * ("Could not add server: Could not reach the server: cURL error 7…").
 *
 * Doctrine under test (see the class docblock of {@see ExceptionSentences}):
 * server WIRE prose (ApiError, minted from the envelope in
 * ApiClient::decode() and boundary-sanitized there) is intentionally
 * user-facing and passes through; PHP/socket/cURL internals — SQLSTATE
 * text, filesystem paths, transport prose — reach the log surface only.
 *
 * Mutation teeth: reverting the `friendlyError()` delegation, the ApiClient
 * NetworkError source fix, or the ladder arms reddens the named tests below
 * (the AppTest headline pin covers the login integration path).
 */
final class ExceptionSentencesTest extends TestCase
{
    /**
     * A transport/database-shaped payload carrying every internal-info class
     * named in the review: SQLSTATE, credentials-bearing host, home path,
     * raw cURL prose, and a local process failure. None of these tokens may
     * ever appear in a user-facing sentence.
     */
    private const HOSTILE = "SQLSTATE[HY000] [1045] Access denied for user 'phlix'@'10.0.0.7' "
        . '/home/deploy/.phlix/config.json cURL error 7: Failed to connect to 192.168.1.50 port 8097 '
        . 'mpv IPC /usr/bin/mpv exited status 1';

    /** @return list<string> substrings of HOSTILE to assert absence of, one per leak class */
    private static function hostileTokens(): array
    {
        return ['SQLSTATE', "'phlix'@", '10.0.0.7', '/home/deploy', 'cURL error', '192.168.1.50', 'mpv IPC'];
    }

    /**
     * Fail-loud tripwire: the payload really carries every token we assert
     * absent below — if HOSTILE is ever edited thinner, the tests would
     * pass vacuously.
     */
    public function testTheHostilePayloadCarriesEveryAssertedToken(): void
    {
        foreach (self::hostileTokens() as $token) {
            self::assertStringContainsString($token, self::HOSTILE, "HOSTILE payload lost token {$token}");
        }
    }

    // ---- ladder: unknown throwables never leak --------------------------

    public function testUnknownThrowableGetsGenericSentenceNotInternals(): void
    {
        $text = ExceptionSentences::sentence(new \RuntimeException(self::HOSTILE), 'login');

        self::assertSame('Something went wrong. Check the log for details.', $text);
        self::assertInternalsAbsent($text);
    }

    public function testScreenSuppliedGenericReplacesTheDefaultSentence(): void
    {
        self::assertSame(
            'Action failed.',
            ExceptionSentences::sentence(new \TypeError(self::HOSTILE), 'parental controls action', 'Action failed.'),
        );
    }

    // ---- ladder: network family -------------------------------------------

    public function testNetworkErrorRendersTheHouseSentenceWithoutTransportProse(): void
    {
        // Post source-fix shape: raw prose rides `previous`, never the message.
        $error = new NetworkError(ExceptionSentences::network(), 0, null, new \RuntimeException(self::HOSTILE));

        $text = ExceptionSentences::sentence($error, 'login');

        self::assertSame('Could not reach the server. Check the URL and your connection.', $text);
        self::assertInternalsAbsent($text);
    }

    public function testLegacyPrefixedNetworkErrorStillRendersCuratedSentence(): void
    {
        // Belt-and-braces: even if some path re-introduces the appended
        // construction, the ladder classifies by TYPE and the raw tail is
        // still dropped. This is the friendlyError-reversion mutation tooth.
        $error = new NetworkError(
            'Could not reach the server: ' . self::HOSTILE,
            0,
            null,
            new \RuntimeException(self::HOSTILE),
        );

        $text = ExceptionSentences::sentence($error, 'login');

        self::assertSame('Could not reach the server. Check the URL and your connection.', $text);
        self::assertInternalsAbsent($text);
    }

    // ---- ladder: wire prose is intentional --------------------------------

    public function testWireProsePassesThroughByteIdentical(): void
    {
        self::assertSame(
            'Invalid username or password',
            ExceptionSentences::sentence(new AuthError('Invalid username or password', 401), 'login'),
        );
        self::assertSame(
            'Group is full.',
            ExceptionSentences::sentence(new ApiError('Group is full.', 409), 'syncplay'),
        );
    }

    public function testWireProseIsFlattenedAndCellCapped(): void
    {
        $multiline = "line one\n" . str_repeat('x', 300) . "\nline three";

        $text = ExceptionSentences::sentence(new ApiError($multiline, 500), 'detail');

        self::assertStringNotContainsString("\n", $text);
        self::assertLessThanOrEqual(160, mb_strwidth($text, 'UTF-8'));
        self::assertStringEndsWith('…', $text);
    }

    // ---- composition: never nest two sentence prefixes -------------------

    public function testComposedDedupsNetworkErrorToTheHouseSentenceAlone(): void
    {
        $error = new NetworkError(ExceptionSentences::network(), 0, null, new \RuntimeException(self::HOSTILE));

        $text = ExceptionSentences::composed('Could not add server', $error);

        self::assertSame('Could not reach the server. Check the URL and your connection.', $text);
        self::assertStringNotContainsString('Could not add server', $text, 'context prefix must not survive dedup');
        self::assertInternalsAbsent($text);
    }

    public function testComposedKeepsExactlyOneWireReason(): void
    {
        self::assertSame(
            'Failed to join room: Invalid group password.',
            ExceptionSentences::composed('Failed to join room', new ApiError('Invalid group password.', 403)),
        );
    }

    public function testComposedLocalThrowRendersContextSentenceAlone(): void
    {
        $text = ExceptionSentences::composed('Could not start playback', new \RuntimeException(self::HOSTILE));

        self::assertSame('Could not start playback.', $text);
        self::assertInternalsAbsent($text);
    }

    public function testContextOnlyNeverAppendsAnything(): void
    {
        $text = ExceptionSentences::contextOnly(
            'Could not save server URL',
            new \RuntimeException(self::HOSTILE),
            'save server URL to /home/u/.phlix/config.json',
        );

        self::assertSame('Could not save server URL.', $text);
        self::assertInternalsAbsent($text);
    }

    public function testContextOnlyKeepsFallbackBearingContextIntact(): void
    {
        // Boot-path shape: the context clause already states the outcome
        // ("…using local config"); a NetworkError must not replace it.
        $error = new NetworkError(ExceptionSentences::network(), 0, null, new \RuntimeException(self::HOSTILE));

        self::assertSame(
            'Could not fetch server settings, using local config.',
            ExceptionSentences::contextOnly('Could not fetch server settings, using local config', $error),
        );
    }

    // ---- source boundary: ApiClient mints clean NetworkErrors ------------

    public function testNetworkErrorMessageIsTheCuratedSentence(): void
    {
        // The construction sites (ApiClient exchange arms) must mint the
        // house sentence as the message — this pins what they pass; the
        // end-to-end proof against raw text is ApiClientTest + AppTest.
        self::assertSame(
            'Could not reach the server. Check the URL and your connection.',
            ExceptionSentences::network(),
        );
    }

    // ---- render surface: captured frame carries no internals -------------

    public function testHostileInternalsNeverReachTheCapturedDisplayFrame(): void
    {
        $sentences = [
            ExceptionSentences::sentence(new \RuntimeException(self::HOSTILE), 'login'),
            ExceptionSentences::composed('Failed to load rooms', new \RuntimeException(self::HOSTILE)),
            ExceptionSentences::contextOnly('Could not save settings', new \RuntimeException(self::HOSTILE)),
        ];

        foreach ($sentences as $sentence) {
            $frame = Chrome::frame('Sign in to Phlix.', $sentence, 'Esc  back', 80, 10);

            foreach (self::hostileTokens() as $token) {
                self::assertStringNotContainsString($token, $frame, "token {$token} leaked into the rendered frame");
            }
        }
    }

    private static function assertInternalsAbsent(string $text): void
    {
        foreach (self::hostileTokens() as $token) {
            self::assertStringNotContainsString($token, $text, "internal detail {$token} leaked into user-facing text");
        }
    }
}
