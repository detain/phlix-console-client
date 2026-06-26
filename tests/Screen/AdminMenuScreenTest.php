<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenAdminSectionMsg;
use Phlix\Console\Route;
use Phlix\Console\Screen\AdminMenuScreen;
use Phlix\Console\Ui\Theme;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;

final class AdminMenuScreenTest extends TestCase
{
    private function screen(): AdminMenuScreen
    {
        return new AdminMenuScreen(cols: 120, rows: 40);
    }

    private function runCmd(?\Closure $cmd): ?Msg
    {
        if ($cmd === null) {
            return null;
        }
        $result = $cmd();

        return $result instanceof Msg ? $result : null;
    }

    public function testInitHasNoFetch(): void
    {
        self::assertNull($this->screen()->init());
    }

    public function testListsTheFullPlannedSectionSetWithWiredSurfacesAvailable(): void
    {
        $sections = $this->screen()->sections();

        $labels = array_map(static fn (array $s): string => $s['label'], $sections);
        self::assertSame([
            'Dashboard', 'Users', 'Server Settings', 'Plugins', 'Libraries', 'Logs',
            'Backup', 'DLNA', 'Remote Access', 'Live TV',
        ], $labels, 'Cast is no longer an admin section — it ships as a DetailScreen action');
        self::assertNotContains('Cast', $labels, 'the stale Cast section row is removed');

        // EVERY section is now wired and available — Live TV was the last.
        $available = array_column(
            array_values(array_filter($sections, static fn (array $s): bool => $s['available'])),
            'label',
        );
        self::assertSame($labels, $available, 'every admin surface is now available — no unavailable rows remain');
        self::assertNotContains(false, array_column($sections, 'available'), 'no section is unavailable');

        $byLabel = array_column($sections, null, 'label');
        self::assertSame(Route::AdminDashboard, $byLabel['Dashboard']['route']);
        self::assertSame(Route::AdminUsers, $byLabel['Users']['route']);
        self::assertSame(Route::AdminSettings, $byLabel['Server Settings']['route']);
        self::assertSame(Route::AdminPlugins, $byLabel['Plugins']['route']);
        self::assertSame(Route::AdminLibraries, $byLabel['Libraries']['route']);
        self::assertSame(Route::AdminLogs, $byLabel['Logs']['route']);
        self::assertSame(Route::AdminBackup, $byLabel['Backup']['route']);
        self::assertSame(Route::AdminDlna, $byLabel['DLNA']['route']);
        self::assertSame(Route::AdminRemote, $byLabel['Remote Access']['route']);
        self::assertSame(Route::AdminLiveTv, $byLabel['Live TV']['route'], 'Live TV is now wired');
        self::assertTrue($byLabel['Live TV']['available']);
    }

    public function testRendersEverySectionAsAvailable(): void
    {
        $view = $this->screen()->view();

        self::assertStringContainsString('Dashboard', $view);
        self::assertStringContainsString('DLNA', $view);
        self::assertStringContainsString('Live TV', $view);
        self::assertStringNotContainsString('coming soon', $view, 'no section is unavailable anymore');
        self::assertStringContainsString('Available', $view);
    }

    public function testDownAndUpMoveTheSelectionAndClamp(): void
    {
        $screen = $this->screen();
        self::assertSame(0, $screen->selectedIndex());

        [$down] = $screen->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $down->selectedIndex());
        self::assertSame('Users', $down->selectedLabel());

        [$up] = $down->update(new KeyMsg(KeyType::Up));
        self::assertSame(0, $up->selectedIndex());

        // Up at the top is a no-op (clamped, same instance).
        [$stillTop] = $up->update(new KeyMsg(KeyType::Up));
        self::assertSame(0, $stillTop->selectedIndex());
    }

    public function testEnterOnDashboardEmitsOpenAdminSectionForTheDashboard(): void
    {
        [, $cmd] = $this->screen()->update(new KeyMsg(KeyType::Enter));

        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(OpenAdminSectionMsg::class, $msg);
        self::assertSame(Route::AdminDashboard, $msg->section);
    }

    public function testEnterOnUsersEmitsOpenAdminSectionForUsers(): void
    {
        // Move to "Users" (index 1), now a wired surface.
        [$onUsers] = $this->screen()->update(new KeyMsg(KeyType::Down));

        [, $cmd] = $onUsers->update(new KeyMsg(KeyType::Enter));

        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(OpenAdminSectionMsg::class, $msg);
        self::assertSame(Route::AdminUsers, $msg->section);
    }

    public function testEnterOnPluginsEmitsOpenAdminSectionForPlugins(): void
    {
        // Move to "Plugins" (index 3), now a wired surface.
        $screen = $this->screen();
        for ($i = 0; $i < 3; $i++) {
            [$screen] = $screen->update(new KeyMsg(KeyType::Down));
        }
        self::assertSame('Plugins', $screen->selectedLabel());

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(OpenAdminSectionMsg::class, $msg);
        self::assertSame(Route::AdminPlugins, $msg->section);
    }

    public function testEnterOnServerSettingsEmitsOpenAdminSectionForSettings(): void
    {
        // Move to "Server Settings" (index 2), now a wired surface.
        $screen = $this->screen();
        for ($i = 0; $i < 2; $i++) {
            [$screen] = $screen->update(new KeyMsg(KeyType::Down));
        }
        self::assertSame('Server Settings', $screen->selectedLabel());

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(OpenAdminSectionMsg::class, $msg);
        self::assertSame(Route::AdminSettings, $msg->section);
    }

    public function testEnterOnLibrariesEmitsOpenAdminSectionForLibraries(): void
    {
        // Move to "Libraries" (index 4), now a wired surface.
        $screen = $this->screen();
        for ($i = 0; $i < 4; $i++) {
            [$screen] = $screen->update(new KeyMsg(KeyType::Down));
        }
        self::assertSame('Libraries', $screen->selectedLabel());

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(OpenAdminSectionMsg::class, $msg);
        self::assertSame(Route::AdminLibraries, $msg->section);
    }

    public function testEnterOnDlnaEmitsOpenAdminSectionForDlna(): void
    {
        // Move to "DLNA" (index 7), now a wired surface.
        $screen = $this->screen();
        for ($i = 0; $i < 7; $i++) {
            [$screen] = $screen->update(new KeyMsg(KeyType::Down));
        }
        self::assertSame('DLNA', $screen->selectedLabel());

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(OpenAdminSectionMsg::class, $msg);
        self::assertSame(Route::AdminDlna, $msg->section);
    }

    public function testEnterOnRemoteAccessEmitsOpenAdminSectionForRemote(): void
    {
        // Move to "Remote Access" (index 8), now a wired surface.
        $screen = $this->screen();
        for ($i = 0; $i < 8; $i++) {
            [$screen] = $screen->update(new KeyMsg(KeyType::Down));
        }
        self::assertSame('Remote Access', $screen->selectedLabel());

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(OpenAdminSectionMsg::class, $msg);
        self::assertSame(Route::AdminRemote, $msg->section);
    }

    public function testEnterOnLiveTvEmitsOpenAdminSectionForLiveTv(): void
    {
        // Move to "Live TV" (index 9), now the last wired surface.
        $screen = $this->screen();
        for ($i = 0; $i < 9; $i++) {
            [$screen] = $screen->update(new KeyMsg(KeyType::Down));
        }
        self::assertSame('Live TV', $screen->selectedLabel());

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(OpenAdminSectionMsg::class, $msg);
        self::assertSame(Route::AdminLiveTv, $msg->section);
    }

    public function testEscapeAndQGoBack(): void
    {
        [, $escCmd] = $this->screen()->update(new KeyMsg(KeyType::Escape));
        self::assertInstanceOf(NavigateBackMsg::class, $this->runCmd($escCmd));

        [, $qCmd] = $this->screen()->update(new KeyMsg(KeyType::Char, 'q'));
        self::assertInstanceOf(NavigateBackMsg::class, $this->runCmd($qCmd));
    }

    public function testAnUnhandledKeyIsANoOp(): void
    {
        $screen = $this->screen();

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'x'));

        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    public function testResizeReflowsTheScreen(): void
    {
        [$resized, $cmd] = $this->screen()->update(new WindowSizeMsg(60, 20));

        self::assertNull($cmd);
        self::assertStringContainsString('Dashboard', $resized->view());
    }

    public function testCrumbLabelAndWithCrumbsAreImmutable(): void
    {
        $screen = $this->screen();
        self::assertSame('Admin', $screen->crumbLabel());

        $crumbed = $screen->withCrumbs(['Admin']);
        self::assertNotSame($screen, $crumbed);
        self::assertStringContainsString('Admin', $crumbed->view());
    }

    public function testWithThemeIsImmutableAndRenders(): void
    {
        $screen = $this->screen();
        $themed = $screen->withTheme(Theme::midnight());

        self::assertNotSame($screen, $themed);
        self::assertNotSame('', $themed->view());
    }

    public function testAnUnhandledMessageIsANoOp(): void
    {
        $screen = $this->screen();

        [$next, $cmd] = $screen->update(new class implements Msg {});

        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }
}
