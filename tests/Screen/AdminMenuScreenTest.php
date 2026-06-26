<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenAdminSectionMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Route;
use Phlix\Console\Screen\AdminMenuScreen;
use Phlix\Console\Ui\Theme;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Toast\ToastType;

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
            'Backup', 'Live TV', 'Remote Access', 'DLNA', 'Cast',
        ], $labels);

        $available = array_column(
            array_values(array_filter($sections, static fn (array $s): bool => $s['available'])),
            'label',
        );
        self::assertSame(['Dashboard', 'Users', 'Plugins', 'Logs', 'Backup'], $available, 'Dashboard, Users, Plugins, Logs and Backup are wired');

        $byLabel = array_column($sections, null, 'label');
        self::assertSame(Route::AdminDashboard, $byLabel['Dashboard']['route']);
        self::assertSame(Route::AdminUsers, $byLabel['Users']['route']);
        self::assertSame(Route::AdminPlugins, $byLabel['Plugins']['route']);
        self::assertSame(Route::AdminLogs, $byLabel['Logs']['route']);
        self::assertSame(Route::AdminBackup, $byLabel['Backup']['route']);
    }

    public function testRendersEverySectionAndTheComingSoonMarker(): void
    {
        $view = $this->screen()->view();

        self::assertStringContainsString('Dashboard', $view);
        self::assertStringContainsString('Cast', $view);
        self::assertStringContainsString('coming soon', $view, 'unavailable sections are marked');
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

    public function testEnterOnAnUnavailableSectionEmitsAComingSoonToast(): void
    {
        // Move to "Server Settings" (index 2), which is not available.
        [$step1] = $this->screen()->update(new KeyMsg(KeyType::Down));
        [$onSettings] = $step1->update(new KeyMsg(KeyType::Down));

        [, $cmd] = $onSettings->update(new KeyMsg(KeyType::Enter));

        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(ShowToastMsg::class, $msg);
        self::assertSame(ToastType::Info, $msg->type);
        self::assertStringContainsString('Server Settings', $msg->message);
        self::assertStringContainsString('coming soon', $msg->message);
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
