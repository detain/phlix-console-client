<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\Dto\AuthUser;
use Phlix\Console\Screen\BrowseScreen;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;

final class BrowseScreenTest extends TestCase
{
    private function user(): AuthUser
    {
        return AuthUser::fromArray(['id' => 'u1', 'username' => 'joe', 'display_name' => 'Joe Huss']);
    }

    public function testQAndEscQuit(): void
    {
        [, $q] = (new BrowseScreen($this->user()))->update(new KeyMsg(KeyType::Char, 'q'));
        [, $esc] = (new BrowseScreen($this->user()))->update(new KeyMsg(KeyType::Escape));

        self::assertInstanceOf(QuitMsg::class, $q());
        self::assertInstanceOf(QuitMsg::class, $esc());
    }

    public function testOtherKeyIsANoOp(): void
    {
        [$next, $cmd] = (new BrowseScreen($this->user()))->update(new KeyMsg(KeyType::Char, 'z'));

        self::assertNull($cmd);
        self::assertInstanceOf(BrowseScreen::class, $next);
    }

    public function testViewShowsDisplayName(): void
    {
        self::assertStringContainsString('Joe Huss', (new BrowseScreen($this->user()))->view());
    }

    public function testResizeUpdatesDimensions(): void
    {
        [$next] = (new BrowseScreen($this->user()))->update(new WindowSizeMsg(140, 50));

        self::assertSame(140, $next->cols);
        self::assertSame(50, $next->rows);
    }
}
