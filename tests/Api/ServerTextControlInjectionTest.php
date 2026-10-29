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
use Phlix\Console\Api\Dto\Admin\Parental\AccessSchedule;
use Phlix\Console\Api\Dto\Admin\PluginSettingField;
use Phlix\Console\Api\Dto\Admin\ServerSetting;
use Phlix\Console\Api\Dto\Coerce;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Api\Dto\MusicArtist;
use Phlix\Console\Api\Dto\SyncPlayGroup;
use Phlix\Console\Api\Dto\SyncPlayUser;
use Phlix\Console\Api\Dto\Trickplay;
use Phlix\Console\Api\SyncPlay\HubRelayConsumer;
use Phlix\Console\Api\SyncPlay\SyncPlayService;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\I18n\SyncPlayErrors;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\SyncPlayOverlay;
use PHPUnit\Framework\TestCase;
use React\Http\Message\Response;
use ReflectionMethod;
use ReflectionProperty;
use SugarCraft\Forms\Field\Input;

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

    // ---- Remaining WS group-state string fields → service state (M-CONSOLE-33) ----

    /**
     * Read the private service property the GROUP_STATE ingest stores — the
     * REAL sink of the fix: hostile wire bytes must be neutralized at the
     * moment they enter service state, not at each downstream comparison.
     */
    private function groupStateProperty(SyncPlayService $service, string $name): string
    {
        $ref = new ReflectionProperty($service, $name);
        $ref->setAccessible(true);

        $value = $ref->getValue($service);
        self::assertIsString($value);

        return $value;
    }

    public function testGroupStateFrameStoresMediaIdAndPlaybackStateTerminalInert(): void
    {
        $service = new SyncPlayService(new ApiClient('https://srv', new FakeTransport()));

        $handle = new ReflectionMethod($service, 'handleMessage');
        $handle->setAccessible(true);
        $handle->invoke($service, json_encode([
            'type' => 'syncplay_group_state',
            'group' => [
                'group_id' => 'g1',
                'current_media_id' => self::HOSTILE,
                'playback_state' => "\x1b[31mplaying",
                'playback_position' => 42,
                'members' => [],
            ],
        ], JSON_THROW_ON_ERROR));

        // Both WS-sourced string fields land sanitized: the terminal-executable
        // payload is gone while the visible words survive (non-vacuity — the
        // frame really reached the ingest branch and really carried the text).
        $mediaId = $this->groupStateProperty($service, 'currentMediaId');
        self::assertTerminalInert($mediaId, 'GROUP_STATE current_media_id');
        self::assertStringContainsString('Red', $mediaId);

        $state = $this->groupStateProperty($service, 'playbackState');
        self::assertTerminalInert($state, 'GROUP_STATE playback_state');
        // The SGR prefix is stripped, leaving the honest comparison value —
        // the state machine can never be steered by an ANSI-decorated enum.
        self::assertSame('playing', $state);
    }

    // ---- Trickplay URL parse boundary → DTO (M-CONSOLE-33 census) ----

    /**
     * The trickplay response URLs are server strings and now pass through the
     * Coerce DTO law at parse ({@see Trickplay::fromArray()}). The only reader
     * today null-checks them, but the values also round-trip the on-disk
     * TrickplayCache blob — hostile bytes must not persist anywhere.
     */
    public function testTrickplayUrlsAreNeutralisedAtTheParseBoundary(): void
    {
        $hostileSprite = 'https://srv/sprites/' . self::HOSTILE . '.png';
        $transport = (new FakeTransport())->json(200, [
            'sprite_url' => $hostileSprite,
            'timeline_url' => "\x1b[2Jhttps://srv/t.json",
        ]);
        $client = new ApiClient('https://srv.example', $transport);
        $client->setToken(new TokenBundle('tok-abc', 'ref', 'Bearer', null));

        $captured = null;
        $client->trickplay('m1')->then(static function (Trickplay $tp) use (&$captured): void {
            $captured = $tp;
        });

        self::assertInstanceOf(
            Trickplay::class,
            $captured,
            'resolved value must reach synchronously through the fake transport',
        );
        self::assertTerminalInert((string) $captured->spriteUrl, 'trickplay sprite_url');
        self::assertTerminalInert((string) $captured->timelineUrl, 'trickplay timeline_url');
        // Non-vacuity: the honest path and words survive the sanitizer.
        self::assertStringStartsWith('https://srv/sprites/', (string) $captured->spriteUrl);
        self::assertStringContainsString('Red', (string) $captured->spriteUrl);
        self::assertSame('https://srv/t.json', $captured->timelineUrl);

        // Empty noise folds to null — "unavailable", matching the null contract.
        $noise = Trickplay::fromArray(['sprite_url' => "\x1b[31m\x07\x7f", 'timeline_url' => '']);
        self::assertNull($noise->spriteUrl);
        self::assertNull($noise->timelineUrl);
    }

    // ---- Admin DTO parse boundaries (M-CONSOLE-33 r2 census) ----

    /**
     * r2 review issue 1: AccessSchedule kept raw `is_string`-gated wire days.
     * The LIVE chain is ParentalControlsScreen:498→513 —
     * `Input::withValue(implode(',', $daysOfWeek))` — and sugar-forms has NO
     * render-boundary sanitizer, so the DTO parse is the only door: the days
     * must be inert in DTO state AND in the real Input::view() echo.
     */
    public function testAccessScheduleDaysAreInertInStateAndInputEcho(): void
    {
        $schedule = AccessSchedule::fromArray([
            'id' => 1,
            'profile_id' => 'p1',
            'name' => 'Evenings',
            'start_time' => '18:00:00',
            'end_time' => '22:00:00',
            'days_of_week' => ["mon\x1b[31mTUE\x07wed", 'thu', "\x9b[2Jsat", 7, ''],
            'is_active' => true,
        ]);

        // Inert list state with the honest text surviving — non-vacuity: the
        // payload really reached the parse. Measured Coerce::stringList
        // semantics: int 7 is scalar, so it survives as the string '7'; the
        // raw-C1 entry "\x9b[2Jsat" neutralises to inert residue '2Jsat'
        // (Sanitize consumes the C1 introducer form, digits stay as text);
        // the empty entry folds away.
        self::assertSame(['monTUEwed', 'thu', '2Jsat', '7'], $schedule->daysOfWeek);
        self::assertSame($schedule->daysOfWeek, $schedule->toArray()['days_of_week']);

        // The screen's real echo chain, reviewer-proven to emit values
        // verbatim (Input::view() has no sanitizer of its own).
        $echo = Input::new('daysOfWeek')
            ->withValue(implode(',', $schedule->daysOfWeek))
            ->view();
        self::assertTerminalInert($echo, 'ParentalControlsScreen days Input::view()');
        self::assertStringContainsString('monTUEwed', $echo);
    }

    /**
     * r2 review issue 2: ServerSetting::render() stringified the effective
     * value raw → AdminSettingsScreen buildEditForm `Input::withValue` arm
     * (its Table arm is framework-sanitized, the Input arm is not).
     */
    public function testServerSettingDisplayValueIsInertInStateAndEditFormEcho(): void
    {
        $setting = ServerSetting::fromParts('api.note', "\x1b]0;pwned\x07real-value", 'string', false);

        self::assertTerminalInert($setting->displayValue, 'ServerSetting displayValue');
        self::assertStringContainsString('real-value', $setting->displayValue);

        $json = ServerSetting::fromParts('meta.map', ['k' => self::HOSTILE], 'json', true);
        self::assertTerminalInert($json->displayValue, 'ServerSetting json displayValue');
        self::assertStringContainsString('Red', $json->displayValue);

        // The AdminSettingsScreen openEdit chain verbatim.
        $echo = Input::new('value')->withValue($setting->displayValue)->view();
        self::assertTerminalInert($echo, 'AdminSettingsScreen buildEditForm Input::view()');
        self::assertStringContainsString('real-value', $echo);
    }

    /**
     * r2 review issue 3: PluginSettingField carries the identical raw
     * `render()` — AdminPluginDetailScreen:406 prefill → buildEditForm:321-325
     * `Input::withValue($value)` → same live echo chain.
     */
    public function testPluginSettingFieldValueIsInertInStateAndEditFormEcho(): void
    {
        $field = PluginSettingField::fromParts(
            key: 'trakt.token_note',
            type: 'string',
            label: 'Note',
            description: '',
            required: false,
            secret: false,
            value: "keep\x1b[2Jthis\x07",
        );

        self::assertTerminalInert($field->value, 'PluginSettingField value');
        self::assertStringContainsString('keepthis', $field->value);

        $json = PluginSettingField::fromParts(
            key: 'meta.map',
            type: 'json',
            label: 'Map',
            description: '',
            required: false,
            secret: false,
            value: ['k' => self::HOSTILE_C1],
        );
        self::assertTerminalInert($json->value, 'PluginSettingField json value');
        self::assertStringContainsString('hi', $json->value);

        // The AdminPluginDetailScreen prefill chain for a NON-secret field.
        $prefill = $field->secret ? '' : $field->value;
        $echo = Input::new('value')->withTitle('New value')->withValue($prefill)->view();
        self::assertTerminalInert($echo, 'AdminPluginDetailScreen buildEditForm Input::view()');
        self::assertStringContainsString('keepthis', $echo);
    }

    /**
     * r2 review issue 4: MusicArtist stored `Coerce::map`-guarded rows raw
     * (Coerce::map is an is_array gate, not a sanitizer). No reader today —
     * pinned DTO-state-inert so the ingest law holds when a reader arrives.
     */
    public function testMusicArtistAlbumsAreInertAtTheParseBoundary(): void
    {
        $artist = MusicArtist::fromArray([
            'name' => 'Hostile Artist',
            'albums' => ["Best\x1b[2JOf", "\x9b[31mNeon\x07", 7, ''],
        ]);

        // Inert state, honest titles surviving — measured Coerce::stringList
        // semantics: scalar 7 survives as the string '7'; the raw-C1 entry
        // "\x9b[31mNeon\x07" neutralises to inert residue '31mNeon'; the
        // empty entry folds away (non-vacuity: the payload reached the parse).
        self::assertSame(['BestOf', '31mNeon', '7'], $artist->albums);
        foreach ($artist->albums as $album) {
            self::assertTerminalInert($album, 'MusicArtist album title');
        }
    }

    /**
     * Coerce::sanitizedTree (new ingest helper): every string key and leaf is
     * neutralised while non-string leaves keep their JSON type — so the json-
     * typed admin values render as inert display text that is STILL valid JSON
     * for the editor round-trip (raw `\x9b` would otherwise make json_encode
     * fail and fold the whole display to '').
     */
    public function testCoerceSanitizedTreeNeutralisesStringsAndPreservesJsonTypes(): void
    {
        $tree = Coerce::sanitizedTree([
            "map\x07key" => [
                'count' => 7,
                'label' => "\x1b[31mRed\x1b[0m",
                'flag' => true,
                'none' => null,
            ],
            "nested\x1b]0;x\x07" => ['deep' => self::HOSTILE_C1],
        ]);

        self::assertSame(
            [
                'mapkey' => ['count' => 7, 'label' => 'Red', 'flag' => true, 'none' => null],
                'nested' => ['deep' => '2Jhi2Jho'],
            ],
            $tree,
        );

        $json = json_encode($tree);
        self::assertIsString($json, 'pre-encode sanitize keeps json_encode from failing on malformed UTF-8');
        self::assertSame(
            '{"mapkey":{"count":7,"label":"Red","flag":true,"none":null},"nested":{"deep":"2Jhi2Jho"}}',
            $json,
            'inert text AND type-faithful JSON (7 stays a number, true stays a bool)',
        );
    }

    /**
     * Family guard for the M-CONSOLE-33 render-boundary finding: the Dto/
     * parse layer's raw-`is_string` pass-through shape is registry-pinned per
     * file (site counts included). The four bypassers the r1 reviewer proved
     * (AccessSchedule days, ServerSetting/PluginSettingField render arms,
     * MusicArtist albums) are Coerce-routed and assert-present here; a NEW
     * raw `is_string` gate in the parse layer fails this test until it is
     * either Coerce-routed or registered with the sink-proof that clears it
     * (stale registry entries fail too — the list is re-blessed from truth).
     *
     * Why ingest is the choke: sugar-FORMS `Input::view()` has no render-time
     * sanitizer (r1 reproduced ESC+BEL landing verbatim), so every value that
     * can reach an Input prefill must carry neutralised bytes from birth
     * (C22 "neutralize at ingest" law, estate precedent: the same rule the
     * C22 sweep established for WS-ingest strings).
     */
    public function testDtoParseLayerHasNoUnregisteredRawIsStringPassThroughs(): void
    {
        // file => [measured is_string token count, why the site is cleared]
        $registry = [
            'Admin/TranscodingAccelerators.php' => [
                2,
                'table-only fields; app Ui\Table::render → sugar-table Column::renderCell'
                    . ' → Sanitize::value neutralises at render (r1 re-probe)',
            ],
            'Admin/AcceleratorInfo.php' => [
                2,
                'table-only fields; sugar-table render boundary (r1 re-probe)',
            ],
            'Admin/ToneMappingSettings.php' => [
                1,
                'table-only field; sugar-table render boundary (r1 re-probe)',
            ],
            'MediaItem.php' => [
                1,
                'numeric decode guard (!is_int && !is_float && !is_string), not a string pass-through',
            ],
            'RecentlyWatchedItem.php' => [
                1,
                'json_decode input guard, not a string pass-through',
            ],
        ];

        // The cured classes: no is_string left in the parse path, and Coerce
        // must be positively present (guards against "fix by deletion").
        $cured = [
            'Admin/Parental/AccessSchedule.php',
            'Admin/ServerSetting.php',
            'Admin/PluginSettingField.php',
            'MusicArtist.php',
        ];

        $root = dirname(__DIR__, 2) . '/src/Api/Dto';
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        self::assertDirectoryExists($root);
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if ($relative === 'Coerce.php') {
                continue; // the sanitizer SSOT itself legitimately uses is_string
            }
            $code = (string) file_get_contents($file->getPathname());
            foreach (token_get_all($code) as $token) {
                if (is_array($token) && $token[0] === T_STRING && strtolower($token[1]) === 'is_string') {
                    $found[$relative] = ($found[$relative] ?? 0) + 1;
                }
            }
        }

        foreach ($found as $file => $count) {
            self::assertArrayHasKey(
                $file,
                $registry,
                "src/Api/Dto/$file carries a raw is_string gate — route it through Coerce"
                    . " (C22 ingest law) or register an exemption with the render-boundary proof that clears it",
            );
            self::assertSame(
                $registry[$file][0],
                $count,
                "is_string site count moved in src/Api/Dto/$file — re-bless the registry from measured truth",
            );
        }
        foreach ($registry as $file => [$count, $reason]) {
            self::assertArrayHasKey(
                $file,
                $found,
                "stale exemption src/Api/Dto/$file (no is_string sites left) — trim the registry, reason was: $reason",
            );
        }
        foreach ($cured as $file) {
            $path = $root . '/' . $file;
            self::assertFileExists($path);
            $code = (string) file_get_contents($path);
            self::assertStringContainsString('Coerce::', $code, "cured parse boundary $file must stay Coerce-routed");
            self::assertStringNotContainsString(
                'is_string',
                $code,
                "cured parse boundary $file must not regain a raw is_string gate",
            );
        }
    }
}
