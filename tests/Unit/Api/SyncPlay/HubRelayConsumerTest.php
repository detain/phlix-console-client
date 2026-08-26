<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit\Api\SyncPlay;

use Phlix\Console\Api\SyncPlay\HubRelayConsumer;
use Phlix\Console\Api\SyncPlay\PendingPlayMediaCommand;
use PHPUnit\Framework\TestCase;

/**
 * Tests the hub-relay pending-command consumer (S298 — console half).
 *
 * Covers the parse boundary, the relay URL builder, and the dispatch point
 * (a delivered `pending_command` frame reaches the registered consumer
 * callback). The live-socket handshake is proven in the sandbox against the
 * real hub; these tests pin the pure logic without a socket.
 */
final class HubRelayConsumerTest extends TestCase
{
    // ---- parsePendingCommandFrame -----------------------------------------

    public function testParsesValidPendingPlayMediaFrame(): void
    {
        $raw = json_encode([
            'type' => 'pending_command',
            'command' => 'play_media',
            'server_id' => 'srv-123',
            'media_id' => 'media-456',
            'title' => 'Inception',
            'issued_at' => 1750000000,
            'source' => 'alexa',
        ], JSON_THROW_ON_ERROR);

        $command = HubRelayConsumer::parsePendingCommandFrame($raw);

        $this->assertInstanceOf(PendingPlayMediaCommand::class, $command);
        $this->assertSame('srv-123', $command->serverId);
        $this->assertSame('media-456', $command->mediaId);
        $this->assertSame('Inception', $command->title);
        $this->assertSame(1750000000, $command->issuedAt);
        $this->assertSame('alexa', $command->source);
    }

    public function testParseRejectsInvalidJson(): void
    {
        $this->assertNull(HubRelayConsumer::parsePendingCommandFrame('{not json'));
    }

    public function testParseRejectsNonObjectJson(): void
    {
        $this->assertNull(HubRelayConsumer::parsePendingCommandFrame('"just a string"'));
        $this->assertNull(HubRelayConsumer::parsePendingCommandFrame('[1,2,3]'));
    }

    public function testParseRejectsUnknownFrameType(): void
    {
        $raw = json_encode([
            'type' => 'room_state',
            'command' => 'play_media',
            'server_id' => 'srv',
            'media_id' => 'm',
            'title' => 'T',
        ], JSON_THROW_ON_ERROR);

        $this->assertNull(HubRelayConsumer::parsePendingCommandFrame($raw));
    }

    public function testParseRejectsNonPlayMediaCommand(): void
    {
        $raw = json_encode([
            'type' => 'pending_command',
            'command' => 'resume',
            'server_id' => 'srv',
            'media_id' => 'm',
            'title' => 'T',
        ], JSON_THROW_ON_ERROR);

        $this->assertNull(HubRelayConsumer::parsePendingCommandFrame($raw));
    }

    public function testParseRejectsMissingOrEmptyRequiredFields(): void
    {
        $base = [
            'type' => 'pending_command',
            'command' => 'play_media',
            'server_id' => 'srv',
            'media_id' => 'm',
            'title' => 'T',
        ];

        foreach (['server_id', 'media_id', 'title'] as $field) {
            $without = $base;
            unset($without[$field]);
            $this->assertNull(
                HubRelayConsumer::parsePendingCommandFrame(json_encode($without, JSON_THROW_ON_ERROR)),
                "frame without {$field} must be rejected",
            );

            $empty = $base;
            $empty[$field] = '';
            $this->assertNull(
                HubRelayConsumer::parsePendingCommandFrame(json_encode($empty, JSON_THROW_ON_ERROR)),
                "frame with empty {$field} must be rejected",
            );
        }
    }

    public function testParseRejectsMissingOrNonIntIssuedAt(): void
    {
        $base = [
            'type' => 'pending_command',
            'command' => 'play_media',
            'server_id' => 'srv',
            'media_id' => 'm',
            'title' => 'T',
        ];

        $without = [
            'type' => 'pending_command',
            'command' => 'play_media',
            'server_id' => 'srv',
            'media_id' => 'm',
            'title' => 'T',
        ];
        $this->assertNull(
            HubRelayConsumer::parsePendingCommandFrame(json_encode($without, JSON_THROW_ON_ERROR)),
            'frame without issued_at must be rejected',
        );

        $nonInt = $base;
        $nonInt['issued_at'] = 'yesterday';
        $this->assertNull(
            HubRelayConsumer::parsePendingCommandFrame(json_encode($nonInt, JSON_THROW_ON_ERROR)),
            'frame with non-int issued_at must be rejected',
        );
    }

    public function testParseDefaultsMissingSourceToUnknown(): void
    {
        $raw = json_encode([
            'type' => 'pending_command',
            'command' => 'play_media',
            'server_id' => 'srv',
            'media_id' => 'm',
            'title' => 'T',
            'issued_at' => 1750000000,
        ], JSON_THROW_ON_ERROR);

        $command = HubRelayConsumer::parsePendingCommandFrame($raw);

        $this->assertInstanceOf(PendingPlayMediaCommand::class, $command);
        $this->assertSame('unknown', $command->source);
    }

    // ---- buildRelayUrl ----------------------------------------------------

    public function testBuildRelayUrlUsesWsAndRelayPort(): void
    {
        $this->assertSame(
            'ws://hub.example.com:8804/syncplay/srv-123',
            HubRelayConsumer::buildRelayUrl('http://hub.example.com:8800', 'srv-123'),
        );
    }

    public function testBuildRelayUrlUsesWssForHttpsOrigin(): void
    {
        $this->assertSame(
            'wss://hub.example.com:8804/syncplay/srv-123',
            HubRelayConsumer::buildRelayUrl('https://hub.example.com', 'srv-123'),
        );
    }

    public function testBuildRelayUrlEncodesServerId(): void
    {
        $this->assertSame(
            'ws://hub.example.com:8804/syncplay/srv%20a%2Fb',
            HubRelayConsumer::buildRelayUrl('http://hub.example.com', 'srv a/b'),
        );
    }

    public function testBuildRelayUrlFallsBackToLocalhost(): void
    {
        $this->assertSame(
            'ws://localhost:8804/syncplay/srv-123',
            HubRelayConsumer::buildRelayUrl('not a url', 'srv-123'),
        );
    }

    // ---- dispatch point ---------------------------------------------------

    public function testHandleFrameDispatchesParsedCommandToConsumer(): void
    {
        $received = null;
        $consumer = new HubRelayConsumer(
            hubBaseUrl: 'http://hub.example.com',
            serverId: 'srv-123',
            tokenProvider: static fn (): string => 'relay-token',
            onPendingCommand: static function (PendingPlayMediaCommand $command) use (&$received): void {
                $received = $command;
            },
        );

        $raw = json_encode([
            'type' => 'pending_command',
            'command' => 'play_media',
            'server_id' => 'srv-123',
            'media_id' => 'media-456',
            'title' => 'Dune',
            'issued_at' => 1750000000,
            'source' => 'alexa',
        ], JSON_THROW_ON_ERROR);

        $this->invokeHandleFrame($consumer, $raw);

        $this->assertInstanceOf(PendingPlayMediaCommand::class, $received);
        $this->assertSame('media-456', $received->mediaId);
        $this->assertSame('Dune', $received->title);
    }

    public function testHandleFrameDropsUnknownFrames(): void
    {
        $received = null;
        $consumer = new HubRelayConsumer(
            hubBaseUrl: 'http://hub.example.com',
            serverId: 'srv-123',
            tokenProvider: static fn (): string => 'relay-token',
            onPendingCommand: static function (PendingPlayMediaCommand $command) use (&$received): void {
                $received = $command;
            },
        );

        $this->invokeHandleFrame($consumer, json_encode([
            'type' => 'room_state',
            'room' => 'x',
        ], JSON_THROW_ON_ERROR));
        $this->invokeHandleFrame($consumer, 'garbage');

        $this->assertNull($received);
    }

    public function testConsumerErrorDoesNotEscapeHandleFrame(): void
    {
        $consumer = new HubRelayConsumer(
            hubBaseUrl: 'http://hub.example.com',
            serverId: 'srv-123',
            tokenProvider: static fn (): string => 'relay-token',
            onPendingCommand: static function (): void {
                throw new \RuntimeException('consumer exploded');
            },
        );

        $raw = json_encode([
            'type' => 'pending_command',
            'command' => 'play_media',
            'server_id' => 'srv-123',
            'media_id' => 'm',
            'title' => 'T',
            'issued_at' => 1750000000,
        ], JSON_THROW_ON_ERROR);

        // Must not throw — the socket's message handler survives consumer errors.
        $this->invokeHandleFrame($consumer, $raw);
        $this->addToAssertionCount(1);
    }

    public function testNotOpenWithoutSocket(): void
    {
        $consumer = new HubRelayConsumer(
            hubBaseUrl: 'http://hub.example.com',
            serverId: 'srv-123',
            tokenProvider: static fn (): string => 'relay-token',
            onPendingCommand: static function (): void {
            },
        );

        $this->assertFalse($consumer->isOpen());
    }

    /**
     * Invoke the private handleFrame() handler (same convention as
     * SyncPlayServiceTest's reflection access).
     */
    private function invokeHandleFrame(HubRelayConsumer $consumer, string $raw): void
    {
        $reflection = new \ReflectionClass($consumer);
        $method = $reflection->getMethod('handleFrame');
        $method->setAccessible(true);
        $method->invoke($consumer, $raw);
    }
}
