<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit\Api\SyncPlay;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\SyncPlayPlaybackCommand;
use Phlix\Console\Api\Dto\SyncPlayUser;
use Phlix\Console\Api\SyncPlay\SyncPlayService;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * Tests the SyncPlayService callback registration and invocation.
 */
final class SyncPlayServiceTest extends TestCase
{
    public function testOnPlaybackCommandCallbackIsInvoked(): void
    {
        $api = new ApiClient('https://srv', new FakeTransport());
        $service = new SyncPlayService($api);

        $receivedCommand = null;
        $service->onPlaybackCommand(function (SyncPlayPlaybackCommand $cmd) use (&$receivedCommand): void {
            $receivedCommand = $cmd;
        });

        // Simulate the callback being invoked with a playback command
        $command = SyncPlayPlaybackCommand::fromArray([
            'type' => 'play',
            'position' => 5000,
            'server_time' => time() * 1000,
        ]);

        // Access the private onPlaybackCommand property and invoke it
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('onPlaybackCommand');
        $property->setAccessible(true);
        /** @var \Closure $callback */
        $callback = $property->getValue($service);
        $callback($command);

        $this->assertInstanceOf(SyncPlayPlaybackCommand::class, $receivedCommand);
        $this->assertSame('play', $receivedCommand->type);
        $this->assertSame(5000, $receivedCommand->position);
    }

    public function testOnMemberJoinedCallbackIsInvoked(): void
    {
        $api = new ApiClient('https://srv', new FakeTransport());
        $service = new SyncPlayService($api);

        $receivedUser = null;
        $service->onMemberJoined(function (SyncPlayUser $user) use (&$receivedUser): void {
            $receivedUser = $user;
        });

        $user = new SyncPlayUser('member-123', 'Alice', false);

        // Access the private onMemberJoined property and invoke it
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('onMemberJoined');
        $property->setAccessible(true);
        /** @var \Closure $callback */
        $callback = $property->getValue($service);
        $callback($user);

        $this->assertInstanceOf(SyncPlayUser::class, $receivedUser);
        $this->assertSame('member-123', $receivedUser->sessionId);
        $this->assertSame('Alice', $receivedUser->displayName);
    }

    public function testOnMemberLeftCallbackIsInvoked(): void
    {
        $api = new ApiClient('https://srv', new FakeTransport());
        $service = new SyncPlayService($api);

        $receivedMemberId = null;
        $service->onMemberLeft(function (string $memberId) use (&$receivedMemberId): void {
            $receivedMemberId = $memberId;
        });

        // Access the private _onMemberLeft property and invoke it
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('_onMemberLeft');
        $property->setAccessible(true);
        /** @var \Closure $callback */
        $callback = $property->getValue($service);
        $callback('member-456');

        $this->assertSame('member-456', $receivedMemberId);
    }

    public function testOnHostChangedCallbackIsInvoked(): void
    {
        $api = new ApiClient('https://srv', new FakeTransport());
        $service = new SyncPlayService($api);

        $receivedHostId = null;
        $service->onHostChanged(function (string $newHostId) use (&$receivedHostId): void {
            $receivedHostId = $newHostId;
        });

        // Access the private onHostChanged property and invoke it
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('onHostChanged');
        $property->setAccessible(true);
        /** @var \Closure $callback */
        $callback = $property->getValue($service);
        $callback('new-host-789');

        $this->assertSame('new-host-789', $receivedHostId);
    }

    public function testOnDisconnectCallbackIsInvoked(): void
    {
        $api = new ApiClient('https://srv', new FakeTransport());
        $service = new SyncPlayService($api);

        $receivedWasIntentional = null;
        $service->onDisconnect(function (bool $wasIntentional) use (&$receivedWasIntentional): void {
            $receivedWasIntentional = $wasIntentional;
        });

        // Access the private onDisconnect property and invoke it
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('onDisconnect');
        $property->setAccessible(true);
        /** @var \Closure $callback */
        $callback = $property->getValue($service);
        $callback(true);

        $this->assertTrue($receivedWasIntentional);
    }

    public function testOnErrorCallbackIsInvoked(): void
    {
        $api = new ApiClient('https://srv', new FakeTransport());
        $service = new SyncPlayService($api);

        $receivedCode = null;
        $receivedMessage = null;
        $service->onError(function (string $code, string $message) use (&$receivedCode, &$receivedMessage): void {
            $receivedCode = $code;
            $receivedMessage = $message;
        });

        // Access the private onError property and invoke it
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('onError');
        $property->setAccessible(true);
        /** @var \Closure $callback */
        $callback = $property->getValue($service);
        $callback('test_error', 'Something went wrong');

        $this->assertSame('test_error', $receivedCode);
        $this->assertSame('Something went wrong', $receivedMessage);
    }
}
