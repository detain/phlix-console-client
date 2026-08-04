<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Admin\Parental\AccessSchedule;
use Phlix\Console\Api\Dto\Admin\Parental\ProfileStreamLimit;
use Phlix\Console\Api\Dto\Admin\Parental\ProfileTag;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Screen\ParentalControlsScreen;
use Phlix\Console\Tests\Api\FakeTransport;
use Phlix\Console\Ui\Theme;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Toast\ToastType;

final class ParentalControlsScreenTest extends TestCase
{
    private const PROFILE_ID = '42';
    private const PROFILE_NAME = 'Kids';

    private function screenWith(FakeTransport $transport): ParentalControlsScreen
    {
        $api = new ApiClient('https://srv', $transport);
        $api->setToken(new TokenBundle('access-1', 'refresh-1', 'Bearer', time() + 3600));

        return new ParentalControlsScreen(new AdminClient($api), self::PROFILE_ID, self::PROFILE_NAME, cols: 120, rows: 40);
    }

    private function runCmd(?\Closure $cmd): ?Msg
    {
        if ($cmd === null) {
            return null;
        }
        $result = $cmd();
        if ($result instanceof BatchMsg) {
            foreach ($result->cmds as $child) {
                $msg = $this->runCmd($child);
                if ($msg !== null) {
                    return $msg;
                }
            }

            return null;
        }
        if ($result instanceof AsyncCmd) {
            $msg = $this->await($result->promise);

            return $msg instanceof Msg ? $msg : null;
        }

        return $result instanceof Msg ? $result : null;
    }

    private function await(PromiseInterface $promise, float $timeout = 2.0): mixed
    {
        $state = ['done' => false, 'value' => null, 'error' => null];
        $promise->then(
            function ($value) use (&$state): void {
                $state['value'] = $value;
                $state['done'] = true;
                Loop::stop();
            },
            function ($error) use (&$state): void {
                $state['error'] = $error;
                $state['done'] = true;
                Loop::stop();
            },
        );

        if (!$state['done']) {
            $timer = Loop::addTimer($timeout, static fn () => Loop::stop());
            Loop::run();
            Loop::cancelTimer($timer);
        }

        if ($state['error'] !== null) {
            throw $state['error'];
        }

        return $state['value'];
    }

    // ---- construction & rendering -----------------------------------------

    public function testConstructsWithDependencies(): void
    {
        $screen = $this->screenWith(new FakeTransport());
        self::assertInstanceOf(ParentalControlsScreen::class, $screen);
    }

    public function testRendersWithoutThrowing(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, ['schedules' => []]));
        $view = $screen->view();
        self::assertIsString($view);
        self::assertStringContainsString('Parental Controls', $view);
        self::assertStringContainsString(self::PROFILE_NAME, $view);
    }

    public function testInitFetchesSchedulesSectionByDefault(): void
    {
        $transport = (new FakeTransport())->json(200, [
            'schedules' => [
                ['id' => 1, 'profile_id' => 42, 'name' => 'Weekday Evenings', 'start_time' => '18:00:00', 'end_time' => '22:00:00', 'days_of_week' => ['mon', 'tue', 'wed', 'thu', 'fri'], 'is_active' => true],
            ],
        ]);
        $screen = $this->screenWith($transport);
        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(\Phlix\Console\Screen\ParentalSchedulesLoadedMsg::class, $msg);
        self::assertCount(1, $msg->schedules);
        self::assertInstanceOf(AccessSchedule::class, $msg->schedules[0]);
        self::assertStringContainsString('/api/v1/profiles/42/schedules', $transport->requestAt(0)['url']);
    }

    public function testCrumbAndThemeAreImmutable(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, ['schedules' => []]));
        self::assertSame('Parental Controls', $screen->crumbLabel());

        $withCrumbs = $screen->withCrumbs(['Admin', 'Users', 'Profiles', 'Parental Controls']);
        self::assertNotSame($screen, $withCrumbs);

        $themed = $screen->withTheme(Theme::midnight());
        self::assertNotSame($screen, $themed);
    }

    public function testResizeReflowsTheView(): void
    {
        $transport = (new FakeTransport())->json(200, [
            'schedules' => [
                ['id' => 1, 'profile_id' => 42, 'name' => 'Weekday Evenings', 'start_time' => '18:00:00', 'end_time' => '22:00:00', 'days_of_week' => ['mon', 'tue', 'wed', 'thu', 'fri'], 'is_active' => true],
            ],
        ]);
        $screen = $this->screenWith($transport);
        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(\Phlix\Console\Screen\ParentalSchedulesLoadedMsg::class, $msg);
        $loaded = $screen->update($msg)[0];
        self::assertStringContainsString('Weekday Evenings', $loaded->view());
        $resized = $loaded->update(new WindowSizeMsg(60, 20))[0];
        self::assertStringContainsString('Weekday Evenings', $resized->view());
    }

    public function testEscAndQNavigateBack(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, ['schedules' => []]));
        [, $escCmd] = $screen->update(new KeyMsg(KeyType::Escape));
        self::assertInstanceOf(NavigateBackMsg::class, $this->runCmd($escCmd));

        [, $qCmd] = $screen->update(new KeyMsg(KeyType::Char, 'q'));
        self::assertInstanceOf(NavigateBackMsg::class, $this->runCmd($qCmd));
    }

    public function testAnUnhandledKeyIsANoOp(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, ['schedules' => []]));
        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'z'));
        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    public function testLoadingStateBeforeSchedules(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, [
            'schedules' => [
                ['id' => 1, 'profile_id' => 42, 'name' => 'Weekday Evenings', 'start_time' => '18:00:00', 'end_time' => '22:00:00', 'days_of_week' => ['mon', 'tue', 'wed', 'thu', 'fri'], 'is_active' => true],
            ],
        ]));
        self::assertStringContainsString('Loading schedules', $screen->view());
    }

    public function testTagsTabFetchesTags(): void
    {
        $transport = (new FakeTransport())
            ->json(200, ['schedules' => []])  // initial schedules fetch
            ->json(200, ['tags' => []]);      // tags fetch on section switch
        $screen = $this->screenWith($transport);

        // Init fetches schedules
        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(\Phlix\Console\Screen\ParentalSchedulesLoadedMsg::class, $msg);

        // Move to tags section
        $withTags = $screen->update($msg)[0]->update(new KeyMsg(KeyType::Right))[0];
        $loadedTags = $withTags->update(new KeyMsg(KeyType::Char, 'r'))[0];
        self::assertStringContainsString('/api/v1/profiles/42/tags', $transport->requestAt(1)['url']);
    }

    public function testStreamLimitsTabFetchesStreamLimits(): void
    {
        $transport = (new FakeTransport())
            ->json(200, ['schedules' => []])
            ->json(200, ['tags' => []])
            ->json(200, ['stream_limits' => ['max_concurrent_streams' => 3, 'max_total_bandwidth_kbps' => 1000]]);
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(\Phlix\Console\Screen\ParentalSchedulesLoadedMsg::class, $msg);

        // Navigate to stream limits
        $s1 = $screen->update($msg)[0]->update(new KeyMsg(KeyType::Right))[0];
        $s2 = $s1->update(new KeyMsg(KeyType::Right))[0];
        $s3 = $s2->update(new KeyMsg(KeyType::Char, 'r'))[0];
        self::assertStringContainsString('/api/v1/profiles/42/stream-limits', $transport->requestAt(2)['url']);
    }
}
