<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Msg\SubmitLoginMsg;
use Phlix\Console\Screen\CapturesSlash;
use Phlix\Console\Screen\LoginScreen;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;

final class LoginScreenTest extends TestCase
{
    private function type(Model $model, string $text): Model
    {
        foreach (mb_str_split($text) as $ch) {
            [$model] = $model->update(new KeyMsg(KeyType::Char, $ch));
        }

        return $model;
    }

    private function fillAndSubmit(): array
    {
        $screen = $this->type(LoginScreen::create(), 'joe');
        [$screen] = $screen->update(new KeyMsg(KeyType::Tab));   // → password
        $screen = $this->type($screen, 'secret');

        return $screen->update(new KeyMsg(KeyType::Enter));      // submit
    }

    public function testCapturesSlashSoTheAppNeverHijacksColonOrSlash(): void
    {
        // Belt-and-suspenders to the auth gate: `:`/`/` are typed into the
        // credentials, never stolen by the palette / global search.
        self::assertInstanceOf(CapturesSlash::class, LoginScreen::create());
    }

    public function testTypingColonAndSlashIntoTheUsernameReachesTheInput(): void
    {
        $screen = $this->type(LoginScreen::create(), 'a:b/c');
        [$screen] = $screen->update(new KeyMsg(KeyType::Tab));   // → password
        $screen = $this->type($screen, 'secret');

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        self::assertInstanceOf(\Closure::class, $cmd);
        $msg = $cmd();
        self::assertInstanceOf(SubmitLoginMsg::class, $msg);
        self::assertSame('a:b/c', $msg->username, 'the `:` and `/` typed into the form');
    }

    public function testSubmitEmitsLoginMsgAndEntersSubmittingState(): void
    {
        [$screen, $cmd] = $this->fillAndSubmit();

        self::assertInstanceOf(\Closure::class, $cmd);
        $msg = $cmd();
        self::assertInstanceOf(SubmitLoginMsg::class, $msg);
        self::assertSame('joe', $msg->username);
        self::assertSame('secret', $msg->password);

        self::assertInstanceOf(LoginScreen::class, $screen);
        self::assertTrue($screen->submitting);
    }

    public function testSubmittingStateIgnoresFurtherInput(): void
    {
        [$screen] = $this->fillAndSubmit();

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'x'));

        self::assertSame($screen, $next, 'input is frozen while signing in');
        self::assertNull($cmd);
    }

    public function testEscQuits(): void
    {
        [, $cmd] = LoginScreen::create()->update(new KeyMsg(KeyType::Escape));

        self::assertInstanceOf(QuitMsg::class, $cmd());
    }

    public function testResizeUpdatesDimensions(): void
    {
        [$next] = LoginScreen::create()->update(new WindowSizeMsg(100, 30));

        self::assertSame(100, $next->cols);
        self::assertSame(30, $next->rows);
    }

    public function testSubmittingViewShowsProgress(): void
    {
        [$screen] = $this->fillAndSubmit();

        self::assertTrue($screen->submitting);
        self::assertStringContainsString('Signing in', $screen->view());
    }

    public function testViewRendersErrorWhenPresent(): void
    {
        $view = LoginScreen::create('Invalid username or password.')->view();

        self::assertStringContainsString('Login', $view);
        self::assertStringContainsString('Invalid username or password.', $view);
    }
}
