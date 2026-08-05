<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenDetailMsg;
use Phlix\Console\Msg\RecommendationsLoadedMsg;
use Phlix\Console\Screen\RecommendationsScreen;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

final class RecommendationsScreenTest extends TestCase
{
    public function testEnterOpensSelectedAndNavigatesBack(): void
    {
        $screen = $this->screenWith();

        // Load recommendations into the screen.
        [$loaded] = $screen->update(new RecommendationsLoadedMsg([
            ['id' => 'rec-1', 'title' => 'Movie One', 'year' => 2024, 'score' => 0.95],
            ['id' => 'rec-2', 'title' => 'Movie Two', 'year' => 2023, 'score' => 0.88],
        ]));

        // Press Enter on the first (already-selected) recommendation.
        [, $cmd] = $loaded->update(new KeyMsg(KeyType::Enter));

        self::assertNotNull($cmd, 'Enter should produce a command');

        $result = $cmd();
        self::assertInstanceOf(BatchMsg::class, $result, 'Enter should send a batch of messages');

        $batch = $result;
        self::assertCount(2, $batch->cmds, 'the batch should contain exactly two commands');

        // The first command should send NavigateBackMsg (to close the player).
        $navBackCmd = $batch->cmds[0]();
        self::assertInstanceOf(NavigateBackMsg::class, $navBackCmd);

        // The second command should send OpenDetailMsg for the selected item.
        $openDetailCmd = $batch->cmds[1]();
        self::assertInstanceOf(OpenDetailMsg::class, $openDetailCmd);
        self::assertSame('rec-1', $openDetailCmd->id);
        self::assertSame('Movie One', $openDetailCmd->name);
    }

    public function testEnterOnSecondItemOpensCorrectDetail(): void
    {
        $screen = $this->screenWith();

        [$loaded] = $screen->update(new RecommendationsLoadedMsg([
            ['id' => 'rec-1', 'title' => 'Movie One', 'year' => 2024, 'score' => 0.95],
            ['id' => 'rec-2', 'title' => 'Movie Two', 'year' => 2023, 'score' => 0.88],
        ]));

        // Move selection to the second item (Down key).
        [$afterFirstDown] = $loaded->update(new KeyMsg(KeyType::Down));

        /** @var RecommendationsScreen $selected */
        [$selected] = $afterFirstDown->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $selected->selectedIndex(), 'second item should be selected');

        [, $cmd] = $selected->update(new KeyMsg(KeyType::Enter));

        $result = $cmd();
        self::assertInstanceOf(BatchMsg::class, $result);

        $openDetailCmd = $result->cmds[1]();
        self::assertInstanceOf(OpenDetailMsg::class, $openDetailCmd);
        self::assertSame('rec-2', $openDetailCmd->id);
        self::assertSame('Movie Two', $openDetailCmd->name);
    }

    public function testEnterWithNoItemsIsANoOp(): void
    {
        $screen = $this->screenWith();

        // Load empty recommendations.
        [$loaded] = $screen->update(new RecommendationsLoadedMsg([]));

        [, $cmd] = $loaded->update(new KeyMsg(KeyType::Enter));

        self::assertNull($cmd, 'Enter with no items should produce no command');
    }

    public function testEscapeNavigatesBack(): void
    {
        $screen = $this->screenWith();

        [$loaded] = $screen->update(new RecommendationsLoadedMsg([
            ['id' => 'rec-1', 'title' => 'Movie One', 'year' => 2024, 'score' => 0.95],
        ]));

        [, $cmd] = $loaded->update(new KeyMsg(KeyType::Escape));

        self::assertNotNull($cmd);
        $msg = $cmd();
        self::assertInstanceOf(NavigateBackMsg::class, $msg);
    }

    public function testQKeyNavigatesBack(): void
    {
        $screen = $this->screenWith();

        [$loaded] = $screen->update(new RecommendationsLoadedMsg([
            ['id' => 'rec-1', 'title' => 'Movie One', 'year' => 2024, 'score' => 0.95],
        ]));

        [, $cmd] = $loaded->update(new KeyMsg(KeyType::Char, 'q'));

        self::assertNotNull($cmd);
        $msg = $cmd();
        self::assertInstanceOf(NavigateBackMsg::class, $msg);
    }

    public function testUpKeySelectsPreviousItem(): void
    {
        $screen = $this->screenWith();

        [$loaded] = $screen->update(new RecommendationsLoadedMsg([
            ['id' => 'rec-1', 'title' => 'Movie One', 'year' => 2024, 'score' => 0.95],
            ['id' => 'rec-2', 'title' => 'Movie Two', 'year' => 2023, 'score' => 0.88],
        ]));

        // Move down then up.
        [$afterDown, ] = $loaded->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $afterDown->selectedIndex());

        [$afterUp, ] = $afterDown->update(new KeyMsg(KeyType::Up));
        self::assertSame(0, $afterUp->selectedIndex());
    }

    public function testDownKeySelectsNextItem(): void
    {
        $screen = $this->screenWith();

        [$loaded] = $screen->update(new RecommendationsLoadedMsg([
            ['id' => 'rec-1', 'title' => 'Movie One', 'year' => 2024, 'score' => 0.95],
            ['id' => 'rec-2', 'title' => 'Movie Two', 'year' => 2023, 'score' => 0.88],
        ]));

        self::assertSame(0, $loaded->selectedIndex());

        [$afterDown, ] = $loaded->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $afterDown->selectedIndex());
    }

    public function testDownKeyAtEndStaysAtEnd(): void
    {
        $screen = $this->screenWith();

        [$loaded] = $screen->update(new RecommendationsLoadedMsg([
            ['id' => 'rec-1', 'title' => 'Movie One', 'year' => 2024, 'score' => 0.95],
        ]));

        // Trying to go down when already at the only item should be a no-op.
        [$same, ] = $loaded->update(new KeyMsg(KeyType::Down));

        self::assertSame(0, $same->selectedIndex());
    }

    public function testUpKeyAtStartStaysAtStart(): void
    {
        $screen = $this->screenWith();

        [$loaded] = $screen->update(new RecommendationsLoadedMsg([
            ['id' => 'rec-1', 'title' => 'Movie One', 'year' => 2024, 'score' => 0.95],
        ]));

        // Trying to go up when already at the first item should be a no-op.
        [$same, ] = $loaded->update(new KeyMsg(KeyType::Up));

        self::assertSame(0, $same->selectedIndex());
    }

    public function testCrumbLabel(): void
    {
        $screen = $this->screenWith();

        self::assertSame('For You', $screen->crumbLabel());
    }

    public function testViewShowsLoadingStateBeforeInit(): void
    {
        $screen = $this->screenWith();

        self::assertStringContainsString('Loading', $screen->view());
    }

    public function testViewShowsRecommendationsAfterLoad(): void
    {
        $screen = $this->screenWith();

        [$loaded] = $screen->update(new RecommendationsLoadedMsg([
            ['id' => 'rec-1', 'title' => 'Movie One', 'year' => 2024, 'score' => 0.95],
        ]));

        $view = $loaded->view();

        self::assertStringContainsString('Movie One', $view);
        self::assertStringContainsString('Because You Watched', $view);
        self::assertStringContainsString('95% match', $view);
    }

    public function testViewShowsEmptyStateWhenNoRecommendations(): void
    {
        $screen = $this->screenWith();

        [$loaded] = $screen->update(new RecommendationsLoadedMsg([]));

        $view = $loaded->view();

        self::assertStringContainsString('No recommendations', $view);
    }

    public function testSelectedItemIsMarkedInView(): void
    {
        $screen = $this->screenWith();

        [$loaded] = $screen->update(new RecommendationsLoadedMsg([
            ['id' => 'rec-1', 'title' => 'Movie One', 'year' => 2024, 'score' => 0.95],
            ['id' => 'rec-2', 'title' => 'Movie Two', 'year' => 2023, 'score' => 0.88],
        ]));

        $view = $loaded->view();

        // The selected item (first) should be marked with ▶
        self::assertStringContainsString('▶', $view);
    }

    // ---- helpers -------------------------------------------------------

    private function screenWith(): RecommendationsScreen
    {
        $transport = new FakeTransport();
        $api = new ApiClient('https://srv', $transport);

        return new RecommendationsScreen($api, 80, 24);
    }
}
