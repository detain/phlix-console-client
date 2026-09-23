<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit\Api\SyncPlay;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\SyncPlay\Framing;
use Phlix\Console\Api\SyncPlay\Messages;
use Phlix\Console\Api\SyncPlay\SyncPlayService;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract regressions for syncplay_error frames.
 *
 * Bug 1: this fork typed error frames 'error' while the server emits
 * 'syncplay_error' (phlix-server src/Session/SyncPlay/Messages.php:149),
 * so every server error frame was silently dropped.
 * Bug 2: handleError read `code` before `error_code`; SPEC.md read-order
 * doctrine is `error_code` first, `code` as legacy fallback.
 *
 * Frames here are built with literal wire strings, not constants, so the
 * test fails if a constant drifts from the contract again.
 */
final class SyncPlayErrorWireTest extends TestCase
{
    /**
     * @param array<string, mixed> $frame
     * @return array{0: string, 1: string}|null Code and message the callback received.
     */
    private function dispatch(SyncPlayService $service, array $frame): ?array
    {
        $received = null;
        $service->onError(function (string $code, string $message) use (&$received): void {
            $received = [$code, $message];
        });

        $handle = new \ReflectionMethod($service, 'handleMessage');
        $handle->setAccessible(true);
        $handle->invoke($service, json_encode($frame, JSON_THROW_ON_ERROR));

        return $received;
    }

    private function service(): SyncPlayService
    {
        return new SyncPlayService(new ApiClient('https://srv', new FakeTransport()));
    }

    public function testServerErrorFrameIsNotDropped(): void
    {
        $received = $this->dispatch($this->service(), [
            'type' => 'syncplay_error',
            'protocol_version' => 1,
            'error_code' => 'NOT_IN_GROUP',
            'message' => 'Player is not in a group',
            'timestamp' => 1771000000,
        ]);

        self::assertNotNull($received);
        self::assertSame('NOT_IN_GROUP', $received[0]);
        self::assertSame('Player is not in a group', $received[1]);
    }

    public function testConstantsMirrorServerWireStrings(): void
    {
        self::assertSame('syncplay_error', Messages::TYPE_ERROR);
        self::assertSame('syncplay_info', Messages::TYPE_INFO);
        self::assertSame('syncplay_group_state', Messages::TYPE_GROUP_STATE);
        self::assertSame('syncplay_host_elect', Messages::TYPE_HOST_ELECT);
        self::assertSame('syncplay_time_sync', Messages::TYPE_TIME_SYNC);
        self::assertSame(1, Messages::PROTOCOL_VERSION);
    }

    public function testLegacyErrorTypeIsDispatchOnly(): void
    {
        self::assertSame('error', Messages::LEGACY_TYPE_ERROR);
        self::assertTrue(Messages::isValid('syncplay_error'));
        self::assertFalse(Messages::isValid('error'));
    }

    public function testErrorCodeKeyWinsOverCodeKey(): void
    {
        $received = $this->dispatch($this->service(), [
            'type' => 'syncplay_error',
            'protocol_version' => 1,
            'error_code' => 'NOT_HOST',
            'code' => 'STALE_LEGACY_CODE',
            'message' => 'Only the host may do that',
            'timestamp' => 1771000000,
        ]);

        self::assertNotNull($received);
        self::assertSame('NOT_HOST', $received[0]);
    }

    public function testLegacyCodeKeyIsUsedWhenErrorCodeAbsent(): void
    {
        $received = $this->dispatch($this->service(), [
            'type' => 'syncplay_error',
            'protocol_version' => 1,
            'code' => 'JOIN_FAILED',
            'message' => 'Group is closed',
            'timestamp' => 1771000000,
        ]);

        self::assertNotNull($received);
        self::assertSame('JOIN_FAILED', $received[0]);
    }

    public function testMissingCodesFallBackToUnknown(): void
    {
        $received = $this->dispatch($this->service(), [
            'type' => 'syncplay_error',
            'protocol_version' => 1,
            'message' => 'Odd frame',
            'timestamp' => 1771000000,
        ]);

        self::assertNotNull($received);
        self::assertSame('unknown', $received[0]);
        self::assertSame('Odd frame', $received[1]);
    }

    public function testMissingMessageIsPassedEmptyForUiLocalization(): void
    {
        $received = $this->dispatch($this->service(), [
            'type' => 'syncplay_error',
            'protocol_version' => 1,
            'error_code' => 'CREATE_FAILED',
            'timestamp' => 1771000000,
        ]);

        self::assertNotNull($received);
        self::assertSame('CREATE_FAILED', $received[0]);
        self::assertSame('', $received[1]);
    }

    public function testDeprecatedErrorEnvelopeStillReachesCallback(): void
    {
        // Connection::sendMessage() shape from the server's JSON-parse
        // failure and handler-error catch paths: {type:'error', data:{message}, timestamp}.
        $received = $this->dispatch($this->service(), [
            'type' => 'error',
            'data' => ['message' => 'Websocket authentication failed'],
            'timestamp' => 1771000000,
        ]);

        self::assertNotNull($received);
        self::assertSame('unknown', $received[0]);
        self::assertSame('Websocket authentication failed', $received[1]);
    }

    public function testFramingAcceptsServerErrorEnvelope(): void
    {
        self::assertTrue(Framing::validateEnvelope([
            'type' => 'syncplay_error',
            'protocol_version' => 1,
            'error_code' => 'NOT_AUTHENTICATED',
            'message' => 'Authentication required',
            'timestamp' => 1771000000,
        ]));
    }

    public function testOutboundFramesStampIntegerProtocolVersion(): void
    {
        $decoded = Framing::decode(Framing::frame(Messages::TYPE_TIME_PING, ['client_time' => 1]));

        self::assertSame(Messages::TYPE_TIME_PING, $decoded['type']);
        self::assertSame(1, $decoded['protocol_version']);
    }
}
