<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenAdminSectionMsg;
use Phlix\Console\Route;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\Table;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;

/**
 * The admin area's section index: a borderless {@see Table} listing every admin
 * surface (Dashboard, Users, Server Settings, …, Live TV) so the whole admin
 * structure is visible up front. EVERY surface is now wired and available (Live TV
 * was the last). Cast is NOT an admin section — it ships as a DetailScreen `C`
 * action. ↑/↓ move the selection; Enter on a section emits an
 * {@see OpenAdminSectionMsg} (the App pushes that section's screen); Esc/q go back.
 *
 * Stable collaborators are readonly; the selection is private mutable view state
 * copied via clone-mutate (the established screen idiom). The screen is the
 * scaffolding all later admin surfaces hang off — adding a surface is one
 * {@see SECTIONS} row plus its {@see Route} + screen.
 */
final class AdminMenuScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const HINT = '↑↓  select      ⏎  open      Esc  back';
    private const STATUS_WIDTH = 16;

    /**
     * The full admin section set, in display order. Each entry is the section's
     * label, the {@see Route} to push when selected, and whether it is available
     * (every surface is now wired, so all are available — the keys are kept for a
     * stable section schema and the App's routing).
     *
     * @var list<array{label: string, route: ?Route, available: bool}>
     */
    private const SECTIONS = [
        ['label' => 'Dashboard', 'route' => Route::AdminDashboard, 'available' => true],
        ['label' => 'Users', 'route' => Route::AdminUsers, 'available' => true],
        ['label' => 'Server Settings', 'route' => Route::AdminSettings, 'available' => true],
        ['label' => 'Plugins', 'route' => Route::AdminPlugins, 'available' => true],
        ['label' => 'Libraries', 'route' => Route::AdminLibraries, 'available' => true],
        ['label' => 'Logs', 'route' => Route::AdminLogs, 'available' => true],
        ['label' => 'Backup', 'route' => Route::AdminBackup, 'available' => true],
        ['label' => 'DLNA', 'route' => Route::AdminDlna, 'available' => true],
        ['label' => 'Remote Access', 'route' => Route::AdminRemote, 'available' => true],
        ['label' => 'Live TV', 'route' => Route::AdminLiveTv, 'available' => true],
    ];

    private int $selected = 0;
    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private int $cols = 80,
        private int $rows = 24,
    ) {
    }

    public function init(): ?\Closure
    {
        return null;
    }

    /** @return array{self, ?\Closure} */
    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [$this->resizedTo($msg->cols, $msg->rows), null];
        }
        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame('Admin', $this->body(), self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
    }

    // ---- input ---------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Enter) {
            return $this->openSelected();
        }
        if ($msg->type === KeyType::Up) {
            return [$this->moveSelection(-1), null];
        }
        if ($msg->type === KeyType::Down) {
            return [$this->moveSelection(1), null];
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function openSelected(): array
    {
        // Every section is wired now; Enter opens the selected one. The `?? Admin`
        // satisfies the nullable schema type — no live row has a null route.
        $route = self::SECTIONS[$this->selected]['route'] ?? Route::Admin;

        return [$this, Cmd::send(new OpenAdminSectionMsg($route))];
    }

    private function moveSelection(int $delta): self
    {
        $count = count(self::SECTIONS);
        $selected = max(0, min($count - 1, $this->selected + $delta));
        if ($selected === $this->selected) {
            return $this;
        }
        $next = clone $this;
        $next->selected = $selected;

        return $next;
    }

    // ---- rendering -----------------------------------------------------

    private function body(): string
    {
        $rows = [];
        foreach (self::SECTIONS as $section) {
            $rows[] = [$section['label'], 'Available'];
        }

        return Table::render([
            ['title' => 'Section', 'width' => 0],
            ['title' => 'Status', 'width' => self::STATUS_WIDTH, 'align' => 'right'],
        ], $rows, $this->selected, $this->cols - 4, $this->viewportRows());
    }

    private function viewportRows(): int
    {
        // The content panel fills the frame; window the section rows to the body
        // height less the table's header + separator (2) so the selected row is
        // never clipped.
        return max(1, Chrome::bodyHeight($this->rows) - 2);
    }

    // ---- immutable copies (clone-mutate) -------------------------------

    private function resizedTo(int $cols, int $rows): self
    {
        $next = clone $this;
        $next->cols = $cols;
        $next->rows = $rows;

        return $next;
    }

    // ---- breadcrumb ----------------------------------------------------

    public function crumbLabel(): string
    {
        return 'Admin';
    }

    public function withCrumbs(array $trail): static
    {
        $next = clone $this;
        $next->crumbs = $trail;

        return $next;
    }

    // ---- accessors (for tests) ----------------------------------------

    public function selectedIndex(): int
    {
        return $this->selected;
    }

    /** The selected section's label (e.g. "Dashboard"). */
    public function selectedLabel(): string
    {
        return self::SECTIONS[$this->selected]['label'];
    }

    /**
     * The full planned section set (label / route / availability), in display order.
     *
     * @return list<array{label: string, route: ?Route, available: bool}>
     */
    public function sections(): array
    {
        return self::SECTIONS;
    }
}
