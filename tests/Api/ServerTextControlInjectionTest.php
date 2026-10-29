<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Api;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\ApiError;
use Phlix\Console\Api\Dto\Coerce;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Api\Dto\SyncPlayGroup;
use Phlix\Console\Api\Dto\SyncPlayUser;
use Phlix\Console\Api\SyncPlay\HubRelayConsumer;
use Phlix\Console\Api\SyncPlay\SyncPlayService;
use Phlix\Console\I18n\SyncPlayErrors;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\SyncPlayOverlay;
use PHPUnit\Framework\TestCase;
use React\Http\Message\Response;
use ReflectionMethod;

/**
 * Regression tests for review console__tuispec [critical]: server-/hub-controlled
 * strings must never carry terminal control sequences (ESC-CSI, ESC-OSC, raw or
 * UTF-8-encoded C1, DEL, BEL, NUL) into the render surface or the `watch`
 * command's stdout — a hostile media title, room name, `success:false` error
 * text, or relay `play_media` frame could otherwise erase/re-paint regions,
 * set the window title, or desync the frame-diff renderer.
 *
 * The policy is the framework's own boundary sanitizer
 * ({@see \SugarCraft\Core\Util\Sanitize::untrustedForDisplay()}) applied where
 * server text is PARSED: DTO coercion ({@see Coerce}), API error-envelope
 * extraction, and the hub-relay frame parser. Once past those boundaries the
 * text is trusted, so the UI renderers (Chrome, SyncPlayOverlay,
 * NowPlayingBar, toasts) emit only the app's OWN intentional escapes.
 */
final class ServerTextControlInjectionTest extends TestCase
{
    /**
     * One payload exercising every injected sequence class named in the
     * review: SGR (ESC-[…m), OSC window-title (ESC-] … BEL), a full-screen
     * erase (ESC [ 2 J), DEL, and NUL — plus the two visible words that MUST
     * survive so neutralisation never silently eats the payload.
     */
    private const HOSTILE = "\x1b[31mRed\x1b[0m \x1b]0;stolen-title\x07Title\x1b[2J\x07\x7f\x00";

    /**
     * 8-bit spellings too: raw C1 CSI (0x9b) and its well-formed UTF-8
     * encoding (U+009B), which xterm-class terminals decode and execute.
     */
    private const HOSTILE_C1 = "\x9b[2Jhi\xC2\x9B2Jho";

    /**
     * The WELL-FORMED UTF-8 C1 spelling alone (U+009B as \xC2\x9B). Unlike a
     * raw 0x9b byte this survives json_encode, so a response body built from
     * it genuinely round-trips the decode boundary — r1 review issue 3:
     * mixing the raw byte into a JSON-fed payload made json_encode fail,
     * emptied the body, and routed the test to the generic fallback branch
     * where it passed without the sanitizer.
     */
    private const HOSTILE_C1_UTF8 = "\xC2\x9B[2Jho";

    /**
     * Assert a string carries no byte that a terminal could execute or that
     * could garble the frame model: no ESC/CR-range controls, no DEL, no raw
     * C1 lead bytes, no UTF-8 C1 codepoints, no BEL.
     */
    private static function assertTerminalInert(string $text, string $needle): void
    {
        self::assertSame(
            [],
            array_filter(
                ["\x1b", "\x07", "\x7f", "\x00", "\x9b", "\x9d", "\xC2\x9b", "\xC2\x9d"],
                static fn (string $byte): bool => str_contains($text, $byte),
            ),
            "sanitized {$needle} still carries terminal-control bytes",
        );
    }

    // ---- Coerce boundary: every DTO display field ----

    public function testCoerceStrNeutralisesInjectedControlSequences(): void
    {
        $clean = Coerce::str(self::HOSTILE);

        self::assertTerminalInert($clean, 'Coerce::str output');
        self::assertStringContainsString('Red', $clean);
        self::assertStringContainsString('Title', $clean);
    }

    public function testCoerceStrNeutralisesEightBitC1Forms(): void
    {
        $clean = Coerce::str(self::HOSTILE_C1);

        self::assertTerminalInert($clean, 'Coerce::str output');
        self::assertStringContainsString('hi', $clean);
        self::assertStringContainsString('ho', $clean);
    }

    public function testCoerceStrLeavesLegitimateTextByteIdentical(): void
    {
        // The sanitizer must be a no-op on honest payloads, including
        // multibyte text and emoji that legitimately contain ZWJ.
        self::assertSame('Normal Title', Coerce::str('Normal Title'));
        self::assertSame('Café ☕ → 😀 👩‍💻', Coerce::str('Café ☕ → 😀 👩‍💻'));
    }

    public function testCoerceNstrNeutralisesAndFoldsPureEscapeNoiseToNull(): void
    {
        self::assertSame('owned', Coerce::nstr("\x1b[2Jowned"));
        // A value that was only control noise sanitizes to '' → absent.
        self::assertNull(Coerce::nstr("\x1b[31m\x07\x7f"));
    }

    public function testCoerceStringListNeutralisesEveryElement(): void
    {
        $genres = Coerce::stringList([self::HOSTILE, 'Drama']);

        self::assertCount(2, $genres);
        self::assertTerminalInert($genres[0], 'list element');
        self::assertSame('Drama', $genres[1]);
    }

    // ---- DTO construction → render surface (header/overlay paths) ----

    public function testHostileMediaNameReachesChromeHeaderInert(): void
    {
        $item = MediaItem::fromArray(['id' => 'm1', 'name' => self::HOSTILE]);

        self::assertTerminalInert($item->name, 'MediaItem name');
        self::assertTerminalInert($item->sortTitle, 'MediaItem sortTitle');

        $frame = Chrome::frame($item->name, 'body', 'Esc back', 60, 8);

        // The injected sequences must not exist as live sequences in the frame;
        // the app's own styling is untouched by this boundary.
        self::assertStringNotContainsString("\x1b[2J", $frame);
        self::assertStringNotContainsString("\x1b]0;", $frame);
        self::assertStringNotContainsString("\x7f", $frame);
        self::assertStringNotContainsString("\x00", $frame);
        self::assertStringContainsString('Red', $frame);
    }

    public function testHostileRoomNameReachesSyncPlayOverlayInert(): void
    {
        $group = SyncPlayGroup::fromArray(['id' => 'g1', 'name' => self::HOSTILE]);

        self::assertTerminalInert($group->name, 'SyncPlayGroup name');

        $box = SyncPlayOverlay::render('', $group->name, 2, 'Synced', 40, 6);

        self::assertStringNotContainsString("\x1b[2J", $box);
        self::assertStringNotContainsString("\x1b]0;", $box);
        self::assertStringNotContainsString("\x7f", $box);
        self::assertStringContainsString('Red', $box);
    }

    // ---- API error-envelope extraction → toast path ----

    /**
     * Invoke the private static ApiClient::decode() (same harness as
     * EnvelopeTest) for one body. JSON_THROW_ON_ERROR makes any payload
     * that cannot survive the wire a loud failure instead of a silently
     * empty body (the r1 review's vacuity trap).
     *
     * @param array<string,mixed> $body
     */
    private function decode(int $status, array $body): ApiError
    {
        $response = new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR),
        );

        $ref = new ReflectionMethod(ApiClient::class, 'decode');
        $ref->setAccessible(true);

        try {
            $ref->invoke(null, $response);
        } catch (ApiError $error) {
            return $error;
        }

        self::fail('expected ApiError from error envelope');
    }

    public function testDecodeSanitisesSuccessFalseMessageText(): void
    {
        $error = $this->decode(200, ['success' => false, 'message' => self::HOSTILE]);

        self::assertTerminalInert($error->getMessage(), 'ApiError message');
        self::assertStringContainsString('Red', $error->getMessage());
    }

    public function testDecodeSanitisesBareErrorKeyText(): void
    {
        $error = $this->decode(500, ['error' => self::HOSTILE]);

        self::assertTerminalInert($error->getMessage(), 'ApiError message');
    }

    public function testDecodeSanitisesEightBitC1ErrorText(): void
    {
        // Non-vacuity proof (r1 review issue 3): the encoded body must really
        // transport the U+009B codepoint, so the inertness asserted below is
        // the sanitizer's doing — never the generic fallback branch a failed
        // json_encode used to reach with a raw-0x9b payload.
        $json = json_encode(['error' => self::HOSTILE_C1_UTF8], JSON_THROW_ON_ERROR);
        self::assertStringContainsString(
            '\\u009b',
            strtolower($json),
            'test payload must survive JSON encoding for the boundary claim to mean anything',
        );

        $error = $this->decode(500, ['error' => self::HOSTILE_C1_UTF8]);

        self::assertTerminalInert($error->getMessage(), 'C1 ApiError message');
        self::assertStringContainsString(
            'ho',
            $error->getMessage(),
            'visible payload must reach (and pass through) the sanitizer',
        );
    }

    public function testAdminClientReThrowFriendlySanitisesBodyMessage(): void
    {
        $original = new ApiError('Request failed (HTTP 200)', 200, ['message' => self::HOSTILE]);

        $ref = new ReflectionMethod(AdminClient::class, 'reThrowFriendly');
        $ref->setAccessible(true);

        try {
            $ref->invoke(null, $original);
        } catch (ApiError $error) {
            self::assertTerminalInert($error->getMessage(), 're-thrown ApiError message');
            self::assertStringContainsString('Red', $error->getMessage());

            return;
        }

        self::fail('expected re-thrown ApiError');
    }

    // ---- Hub-relay frame parser → TUI render AND bin/phlix watch stdout ----

    public function testRelayFrameTitleAndSourceAreInertForEverySink(): void
    {
        $raw = json_encode([
            'type' => 'pending_command',
            'command' => 'play_media',
            'server_id' => 'srv-1',
            'media_id' => 'media-1',
            'title' => self::HOSTILE,
            'source' => "\x1b]0;evil\x07alexa",
            'issued_at' => 1700000000,
        ], JSON_THROW_ON_ERROR);

        $command = HubRelayConsumer::parsePendingCommandFrame($raw);

        self::assertNotNull($command);
        // The watch command fwrites exactly these fields to STDOUT; the TUI
        // renders the same title — one boundary now feeds both sinks inert.
        self::assertTerminalInert($command->title, 'relay frame title');
        self::assertTerminalInert($command->source, 'relay frame source');
        self::assertStringContainsString('Red', $command->title);
        self::assertSame('alexa', $command->source);
    }

    // ---- SyncPlay WebSocket ingest → onError toast AND member DTO state ----

    /**
     * Drive a server-shaped WS frame through the REAL parse path:
     * {@see SyncPlayService::handleMessage()} → error handler → onError
     * capture (same reflection harness as SyncPlayErrorWireTest).
     *
     * @param array<string,mixed> $frame
     * @return array{0: string, 1: string} Code and message onError received.
     */
    private function dispatchErrorFrame(array $frame): array
    {
        $service = new SyncPlayService(new ApiClient('https://srv', new FakeTransport()));

        $received = null;
        $service->onError(function (string $code, string $message) use (&$received): void {
            $received = [$code, $message];
        });

        $handle = new ReflectionMethod($service, 'handleMessage');
        $handle->setAccessible(true);
        $handle->invoke($service, json_encode($frame, JSON_THROW_ON_ERROR));

        self::assertNotNull($received, 'onError must fire for an error frame');

        return $received;
    }

    /**
     * r1 review issue 1: `syncplay_error` frame text bypassed the sanitize
     * law. PlayerScreen queues `'SyncPlay: ' . SyncPlayErrors::localize(...)`
     * as a toast, and the unknown-code fallback passes server text through
     * verbatim — so the message must already be terminal-inert when the
     * frame is PARSED.
     */
    public function testSyncPlayErrorFrameMessageIsInertAtTheOnErrorBoundary(): void
    {
        [$code, $message] = $this->dispatchErrorFrame([
            'type' => 'syncplay_error',
            'protocol_version' => 1,
            'error_code' => 'some_unknown_code',
            'message' => self::HOSTILE,
            'timestamp' => 1771000000,
        ]);

        self::assertSame('some_unknown_code', $code);
        self::assertTerminalInert($message, 'WS error-frame message');
        self::assertStringContainsString('Red', $message);
        self::assertStringContainsString('Title', $message);

        // The verbatim-passthrough fallback is where hostile text reaches
        // the terminal; with the payload inert at the parse boundary the
        // exact toast line PlayerScreen builds is inert too.
        self::assertTerminalInert(
            'SyncPlay: ' . SyncPlayErrors::localize($code, $message),
            'localized unknown-code toast line',
        );
    }

    /** The deprecated `{type:'error', data:{message}}` envelope shares the sink. */
    public function testLegacyErrorEnvelopeMessageIsInert(): void
    {
        [$code, $message] = $this->dispatchErrorFrame([
            'type' => 'error',
            'protocol_version' => 1,
            'code' => 42,
            'data' => ['message' => self::HOSTILE],
        ]);

        self::assertSame('42', $code);
        self::assertTerminalInert($message, 'legacy error-envelope message');
        self::assertStringContainsString('Title', $message);
    }

    /**
     * r1 review issue 2: the WS INFO join path built its DTO through the raw
     * constructor, bypassing the {@see SyncPlayUser::fromArray()} Coerce
     * boundary every other member construction uses. A WS-built DTO must
     * match an HTTP-built one's sanitization exactly.
     */
    public function testWsInfoFrameMemberMatchesHttpBuiltDtoSanitization(): void
    {
        $service = new SyncPlayService(new ApiClient('https://srv', new FakeTransport()));

        $joined = null;
        $service->onMemberJoined(function (SyncPlayUser $user) use (&$joined): void {
            $joined = $user;
        });

        $handle = new ReflectionMethod($service, 'handleMessage');
        $handle->setAccessible(true);
        $handle->invoke($service, json_encode([
            'type' => 'syncplay_info',
            'protocol_version' => 1,
            'member_id' => self::HOSTILE,
            'member_name' => self::HOSTILE,
        ], JSON_THROW_ON_ERROR));

        self::assertInstanceOf(SyncPlayUser::class, $joined);
        self::assertTerminalInert($joined->sessionId, 'WS-built sessionId');
        self::assertTerminalInert($joined->displayName, 'WS-built displayName');

        // Parity law: identical hostile text through the HTTP GROUP_STATE
        // key shape must land in an identically neutralized DTO.
        $httpBuilt = SyncPlayUser::fromArray(['id' => self::HOSTILE, 'name' => self::HOSTILE]);
        self::assertSame($httpBuilt->sessionId, $joined->sessionId);
        self::assertSame($httpBuilt->displayName, $joined->displayName);
        self::assertSame([$joined], $service->getMembers(), 'member list carries the neutralized DTO');
    }
}
