<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Api\Dto\SyncPlayUser;
use Phlix\Console\Api\SyncPlay\SyncPlayService;
use Phlix\Console\Msg\SyncPlayDisconnectedMsg;
use Phlix\Console\Msg\SyncPlayGroupStateMsg;
use Phlix\Console\Msg\SyncPlayHostChangedMsg;
use Phlix\Console\Msg\SyncPlayMemberJoinedMsg;
use Phlix\Console\Msg\SyncPlayPlaybackCommandMsg;
use Phlix\Console\Screen\PlayerScreen;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\WindowSizeMsg;

/**
 * Tests that SyncPlay callbacks registered in PlayerScreen produce the expected messages.
 */
final class PlayerScreenSyncPlayTest extends TestCase
{
    private function item(): MediaItem
    {
        return MediaItem::fromArray([
            'id' => 'm1',
            'name' => 'Test Movie',
            'type' => 'movie',
            'stream_url' => 'https://srv/media/m1/stream?exp=1&sig=abc',
        ]);
    }

    private function createScreen(): PlayerScreen
    {
        $item = $this->item();
        $api = new ApiClient('https://srv', new FakeTransport());

        // Use a stub player factory - the factory is not called in these tests
        // since we never call init() on the screen
        $playerFactory = static fn (string $url, int $cols, int $rows): \SugarCraft\Reel\Player => throw new \RuntimeException('Not used in this test');

        $syncPlayService = new SyncPlayService($api);

        return new PlayerScreen(
            $item,
            'https://srv',
            $api,
            $playerFactory,
            $syncPlayService,
        );
    }

    public function testOnMemberJoinedCallbackProducesSyncPlayMemberJoinedMsg(): void
    {
        $screen = $this->createScreen();

        // Get the SyncPlayService from the screen via reflection
        $screenReflection = new \ReflectionClass($screen);
        $serviceProperty = $screenReflection->getProperty('syncPlayService');
        $serviceProperty->setAccessible(true);
        /** @var SyncPlayService $syncPlayService */
        $syncPlayService = $serviceProperty->getValue($screen);

        // Trigger the onMemberJoined callback on the SyncPlayService
        $user = new SyncPlayUser('member-abc', 'Alice', false);
        $serviceReflection = new \ReflectionClass($syncPlayService);
        $onMemberJoined = $serviceReflection->getProperty('onMemberJoined');
        $onMemberJoined->setAccessible(true);
        /** @var \Closure $callback */
        $callback = $onMemberJoined->getValue($syncPlayService);
        $callback($user);

        // Verify the message was queued in pendingSyncPlayEvents on the screen
        $pendingProperty = $screenReflection->getProperty('pendingSyncPlayEvents');
        $pendingProperty->setAccessible(true);
        /** @var list<SyncPlayMemberJoinedMsg|SyncPlayPlaybackCommandMsg|SyncPlayDisconnectedMsg|SyncPlayHostChangedMsg|Msg> $pendingEvents */
        $pendingEvents = $pendingProperty->getValue($screen);

        $this->assertCount(1, $pendingEvents);
        $this->assertInstanceOf(SyncPlayMemberJoinedMsg::class, $pendingEvents[0]);
        /** @var SyncPlayMemberJoinedMsg $msg */
        $msg = $pendingEvents[0];
        $this->assertSame('member-abc', $msg->member->sessionId);
        $this->assertSame('Alice', $msg->member->displayName);
    }

    public function testOnPlaybackCommandCallbackProducesSyncPlayPlaybackCommandMsg(): void
    {
        $screen = $this->createScreen();

        // Get the SyncPlayService from the screen via reflection
        $screenReflection = new \ReflectionClass($screen);
        $serviceProperty = $screenReflection->getProperty('syncPlayService');
        $serviceProperty->setAccessible(true);
        /** @var SyncPlayService $syncPlayService */
        $syncPlayService = $serviceProperty->getValue($screen);

        // Trigger the onPlaybackCommand callback on the SyncPlayService
        $command = \Phlix\Console\Api\Dto\SyncPlayPlaybackCommand::fromArray([
            'type' => 'play',
            'position' => 10000,
            'server_time' => time() * 1000,
        ]);
        $serviceReflection = new \ReflectionClass($syncPlayService);
        $onPlaybackCommand = $serviceReflection->getProperty('onPlaybackCommand');
        $onPlaybackCommand->setAccessible(true);
        /** @var \Closure $callback */
        $callback = $onPlaybackCommand->getValue($syncPlayService);
        $callback($command);

        // Verify the message was queued in pendingSyncPlayEvents on the screen
        $pendingProperty = $screenReflection->getProperty('pendingSyncPlayEvents');
        $pendingProperty->setAccessible(true);
        /** @var list<SyncPlayMemberJoinedMsg|SyncPlayPlaybackCommandMsg|SyncPlayDisconnectedMsg|SyncPlayHostChangedMsg|Msg> $pendingEvents */
        $pendingEvents = $pendingProperty->getValue($screen);

        $this->assertCount(1, $pendingEvents);
        $this->assertInstanceOf(SyncPlayPlaybackCommandMsg::class, $pendingEvents[0]);
    }

    public function testOnGroupStateCallbackProducesSyncPlayGroupStateMsg(): void
    {
        $screen = $this->createScreen();

        // Get the SyncPlayService from the screen via reflection
        $screenReflection = new \ReflectionClass($screen);
        $serviceProperty = $screenReflection->getProperty('syncPlayService');
        $serviceProperty->setAccessible(true);
        /** @var SyncPlayService $syncPlayService */
        $syncPlayService = $serviceProperty->getValue($screen);

        // Trigger the onGroupState callback on the SyncPlayService
        $serviceReflection = new \ReflectionClass($syncPlayService);
        $onGroupState = $serviceReflection->getProperty('onGroupState');
        $onGroupState->setAccessible(true);
        /** @var \Closure $callback */
        $callback = $onGroupState->getValue($syncPlayService);
        $callback();

        // Verify the message was queued in pendingSyncPlayEvents on the screen
        $pendingProperty = $screenReflection->getProperty('pendingSyncPlayEvents');
        $pendingProperty->setAccessible(true);
        /** @var list<SyncPlayGroupStateMsg|SyncPlayMemberJoinedMsg|SyncPlayPlaybackCommandMsg|SyncPlayDisconnectedMsg|SyncPlayHostChangedMsg|Msg> $pendingEvents */
        $pendingEvents = $pendingProperty->getValue($screen);

        $this->assertCount(1, $pendingEvents);
        $this->assertInstanceOf(SyncPlayGroupStateMsg::class, $pendingEvents[0]);
    }

    public function testPendingSyncPlayEventsAreProcessedInUpdate(): void
    {
        $screen = $this->createScreen();

        $screenReflection = new \ReflectionClass($screen);

        // Manually queue a SyncPlayMemberJoinedMsg
        $pendingProperty = $screenReflection->getProperty('pendingSyncPlayEvents');
        $pendingProperty->setAccessible(true);
        $user = new SyncPlayUser('member-xyz', 'Bob', false);
        $pendingProperty->setValue($screen, [new SyncPlayMemberJoinedMsg($user)]);

        // Call update with WindowSizeMsg to trigger event processing
        $msg = new WindowSizeMsg(80, 24);
        [$nextScreen] = $screen->update($msg);

        // Verify pending events were processed and queue is now empty
        $pendingProperty = $screenReflection->getProperty('pendingSyncPlayEvents');
        $pendingProperty->setAccessible(true);
        $this->assertEmpty($pendingProperty->getValue($nextScreen));

        // Verify the syncPlayStatus was updated (it calls getSyncStatus on the service)
        $statusProperty = $screenReflection->getProperty('syncPlayStatus');
        $statusProperty->setAccessible(true);
        $status = $statusProperty->getValue($nextScreen);
        // Since we haven't joined a room, status should be 'Not in room'
        $this->assertIsString($status);
    }
}
