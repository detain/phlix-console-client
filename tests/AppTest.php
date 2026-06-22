<?php

declare(strict_types=1);

namespace Phlix\Console\Tests;

use Phlix\Console\App;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;

final class AppTest extends TestCase
{
    public function testViewRendersShell(): void
    {
        $view = (new App(80, 24))->view();

        self::assertIsString($view);
        self::assertStringContainsString('Phlix', $view);
        self::assertStringContainsString('quit', $view);
    }

    public function testResizeGrowsTheRenderedHeight(): void
    {
        $small = (new App(80, 24))->view();
        [$resized, $cmd] = (new App(80, 24))->update(new WindowSizeMsg(120, 40));

        self::assertNull($cmd);
        $large = $resized->view();
        self::assertGreaterThan(substr_count($small, "\n"), substr_count($large, "\n"));
    }

    public function testQuitKeysReturnAQuitCommand(): void
    {
        [, $qCmd] = (new App())->update(new KeyMsg(KeyType::Char, 'q'));
        [, $escCmd] = (new App())->update(new KeyMsg(KeyType::Escape));
        [, $ctrlCCmd] = (new App())->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));

        self::assertInstanceOf(\Closure::class, $qCmd);
        self::assertInstanceOf(\Closure::class, $escCmd);
        self::assertInstanceOf(\Closure::class, $ctrlCCmd);
    }

    public function testNonQuitKeyIsANoOp(): void
    {
        [$next, $cmd] = (new App())->update(new KeyMsg(KeyType::Char, 'x'));

        self::assertNull($cmd);
        self::assertInstanceOf(App::class, $next);
    }
}
