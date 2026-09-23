<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Api\Dto\SyncPlayUser;
use Phlix\Console\Api\SyncPlay\SyncPlayService;
use Phlix\Console\I18n\Lang;
use Phlix\Console\Msg\ShowToastMsg;
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
    protected function setUp(): void
    {
        \SugarCraft\Core\I18n\T::reset();
    }

    protected function tearDown(): void
    {
        \SugarCraft\Core\I18n\T::reset();
    }

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

    /**
     * Drive a server-shaped syncplay_error frame through the screen's real
     * wire path: service handleMessage -> onError closure -> queued toast.
     *
     * @param array<string, mixed> $frame
     */
    private function dispatchErrorFrame(PlayerScreen $screen, array $frame): void
    {
        $screenReflection = new \ReflectionClass($screen);
        $serviceProperty = $screenReflection->getProperty('syncPlayService');
        $serviceProperty->setAccessible(true);
        /** @var SyncPlayService $service */
        $service = $serviceProperty->getValue($screen);

        $handle = new \ReflectionMethod($service, 'handleMessage');
        $handle->setAccessible(true);
        $handle->invoke($service, json_encode($frame, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $frame
     */
    private function errorToast(PlayerScreen $screen, array $frame): ShowToastMsg
    {
        $this->dispatchErrorFrame($screen, $frame);

        $screenReflection = new \ReflectionClass($screen);
        $pendingProperty = $screenReflection->getProperty('pendingSyncPlayEvents');
        $pendingProperty->setAccessible(true);
        /** @var list<Msg> $pendingEvents */
        $pendingEvents = $pendingProperty->getValue($screen);

        $this->assertCount(1, $pendingEvents);
        $this->assertInstanceOf(ShowToastMsg::class, $pendingEvents[0]);
        /** @var ShowToastMsg $toast */
        $toast = $pendingEvents[0];

        return $toast;
    }

    public function testKnownErrorCodeToastIsLocalizedInSpanish(): void
    {
        Lang::t('syncplay.unknown_error'); // registers the real catalog directory
        \SugarCraft\Core\I18n\T::setLocale('es');
        $screen = $this->createScreen();

        $toast = $this->errorToast($screen, [
            'type' => 'syncplay_error',
            'protocol_version' => 1,
            'error_code' => 'NOT_IN_GROUP',
            'message' => 'Player is not in a group',
            'timestamp' => 1771000000,
        ]);

        $this->assertSame('SyncPlay: No estás en un grupo de visionado.', $toast->message);
    }

    public function testKnownErrorCodeToastIsLocalizedInJapanese(): void
    {
        Lang::t('syncplay.unknown_error');
        \SugarCraft\Core\I18n\T::setLocale('ja');
        $screen = $this->createScreen();

        $toast = $this->errorToast($screen, [
            'type' => 'syncplay_error',
            'protocol_version' => 1,
            'error_code' => 'JOIN_FAILED',
            'message' => 'Could not join the watch group',
            'timestamp' => 1771000000,
        ]);

        $this->assertSame('SyncPlay: ウォッチグループに参加できませんでした。', $toast->message);
    }

    public function testLocalizedCodeWinsOverServerEnglishText(): void
    {
        Lang::t('syncplay.unknown_error');
        \SugarCraft\Core\I18n\T::setLocale('es');
        $screen = $this->createScreen();

        $toast = $this->errorToast($screen, [
            'type' => 'syncplay_error',
            'protocol_version' => 1,
            'error_code' => 'CREATE_FAILED',
            'message' => 'raw internal db detail',
            'timestamp' => 1771000000,
        ]);

        $this->assertSame('SyncPlay: No se pudo crear el grupo de visionado.', $toast->message);
        $this->assertStringNotContainsString('raw internal db detail', $toast->message);
    }

    public function testUnknownErrorCodeFallsBackToServerText(): void
    {
        Lang::t('syncplay.unknown_error');
        \SugarCraft\Core\I18n\T::setLocale('ja');
        $screen = $this->createScreen();

        $toast = $this->errorToast($screen, [
            'type' => 'syncplay_error',
            'protocol_version' => 1,
            'error_code' => 'SOME_FUTURE_CODE',
            'message' => 'brand new server diagnostic',
            'timestamp' => 1771000000,
        ]);

        $this->assertSame('SyncPlay: brand new server diagnostic', $toast->message);
    }

    public function testUnknownCodeWithoutMessageShowsGenericLocalizedLine(): void
    {
        Lang::t('syncplay.unknown_error');
        $screen = $this->createScreen();

        $toast = $this->errorToast($screen, [
            'type' => 'syncplay_error',
            'protocol_version' => 1,
            'error_code' => 'SOME_FUTURE_CODE',
            'timestamp' => 1771000000,
        ]);

        $this->assertSame('SyncPlay: A sync error occurred.', $toast->message);
    }
}
