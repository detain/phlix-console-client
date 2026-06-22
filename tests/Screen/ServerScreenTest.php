<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Msg\SubmitServerMsg;
use Phlix\Console\Screen\ServerScreen;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;

final class ServerScreenTest extends TestCase
{
    private function type(Model $model, string $text): Model
    {
        foreach (mb_str_split($text) as $ch) {
            [$model] = $model->update(new KeyMsg(KeyType::Char, $ch));
        }

        return $model;
    }

    public function testSubmitEmitsServerMsgWithNormalisedUrl(): void
    {
        $screen = $this->type(ServerScreen::create(), 'host.tld:8096');

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        self::assertInstanceOf(\Closure::class, $cmd);
        $msg = $cmd();
        self::assertInstanceOf(SubmitServerMsg::class, $msg);
        self::assertSame('https://host.tld:8096', $msg->url);
    }

    public function testEmptySubmitStaysWithAnError(): void
    {
        $screen = ServerScreen::create();

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        self::assertInstanceOf(ServerScreen::class, $next);
        self::assertNotNull($next->error);
        if ($cmd !== null) {
            self::assertNotInstanceOf(SubmitServerMsg::class, $cmd());
        }
        self::assertStringContainsString('server URL', $next->view());
    }

    public function testEscQuits(): void
    {
        [, $cmd] = ServerScreen::create()->update(new KeyMsg(KeyType::Escape));

        self::assertInstanceOf(\Closure::class, $cmd);
        self::assertInstanceOf(QuitMsg::class, $cmd());
    }

    public function testResizeUpdatesDimensions(): void
    {
        [$next] = ServerScreen::create()->update(new WindowSizeMsg(120, 40));

        self::assertInstanceOf(ServerScreen::class, $next);
        self::assertSame(120, $next->cols);
        self::assertSame(40, $next->rows);
    }

    public function testViewRendersSetupTitle(): void
    {
        self::assertStringContainsString('Setup', ServerScreen::create()->view());
    }
}
