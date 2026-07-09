<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Admin\AdminDashboard;
use Phlix\Console\Msg\AdminDashboardFailedMsg;
use Phlix\Console\Msg\AdminDashboardLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Ui\Chrome;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;

/**
 * The admin Dashboard: read-only panels rendered from one fan-out fetch
 * ({@see AdminClient::dashboard()} → {@see AdminDashboard}) — Now Playing,
 * Storage (humanized bytes per type + total), Top Users, Top Media, and Recent
 * Activity. `r` refetches; Esc/q go back. A fetch failure shows a line plus a
 * retry hint; an auth failure surfaces a session expiry.
 *
 * The client is injected (built locally by the App from its shared ApiClient, so
 * the App holds no AdminClient field). Stable collaborators are readonly; the
 * loaded data + flags are private mutable view state set via clone-mutate (the
 * established screen idiom). The panel mirrors {@see StatsScreen}'s read-only
 * style.
 */
final class AdminDashboardScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LOAD_FAILED = 'Could not load the dashboard.';
    private const HINT = 'r  refresh      Esc  back';

    /** How many leaderboard / activity rows each panel shows. */
    private const PANEL_ROWS = 5;

    private ?AdminDashboard $dashboard = null;
    private bool $loaded = false;
    private ?string $error = null;
    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private readonly AdminClient $admin,
        private int $cols = 80,
        private int $rows = 24,
    ) {
    }

    public function init(): \Closure
    {
        return $this->fetchCmd();
    }

    private function fetchCmd(): \Closure
    {
        return Cmd::promise(fn () => $this->admin->dashboard()->then(
            static fn (AdminDashboard $dashboard): Msg => new AdminDashboardLoadedMsg($dashboard),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminDashboardFailedMsg(self::LOAD_FAILED),
        ));
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
        if ($msg instanceof AdminDashboardLoadedMsg) {
            return [$this->withDashboard($msg->dashboard), null];
        }
        if ($msg instanceof AdminDashboardFailedMsg) {
            return [$this->withError($msg->message), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame('Admin · Dashboard', $this->body(), self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
    }

    // ---- input ---------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'r') {
            return [$this->reloading(), $this->fetchCmd()];
        }

        return [$this, null];
    }

    // ---- rendering -----------------------------------------------------

    private function body(): string
    {
        if ($this->error !== null) {
            return "\n  {$this->error}\n\n  Press r to retry.";
        }
        // A loaded screen always carries a dashboard (the loaded flag and the DTO
        // are set together); the null check keeps the "still loading" copy and the
        // initial state on the same branch.
        $dashboard = $this->dashboard;
        if (!$this->loaded || $dashboard === null) {
            return "\n  Loading dashboard…";
        }

        $sections = [
            $this->nowPlayingPanel($dashboard),
            $this->storagePanel($dashboard),
            $this->topUsersPanel($dashboard),
            $this->topMediaPanel($dashboard),
            $this->activityPanel($dashboard),
        ];

        return "\n" . implode("\n\n", $sections);
    }

    private function nowPlayingPanel(AdminDashboard $dashboard): string
    {
        if ($dashboard->nowPlaying === []) {
            return self::panel('Now Playing', ['Nobody is watching.']);
        }

        $lines = [];
        foreach ($dashboard->nowPlaying as $session) {
            $pct = (int) round($session->progressPercent);
            $lines[] = $session->watcherLabel() . ' — ' . $session->titleLabel() . ' (' . $pct . '%)';
        }

        return self::panel('Now Playing', $lines);
    }

    private function storagePanel(AdminDashboard $dashboard): string
    {
        $storage = $dashboard->storage;
        $lines = [
            'Movies          ' . self::humanBytes($storage->movieBytes),
            'Series          ' . self::humanBytes($storage->seriesBytes),
            'Music           ' . self::humanBytes($storage->musicBytes),
            'Photos          ' . self::humanBytes($storage->photoBytes),
            'Transcode cache ' . self::humanBytes($storage->transcodeCacheBytes),
            'Total           ' . self::humanBytes($storage->totalBytes()),
        ];

        return self::panel('Storage', $lines);
    }

    private function topUsersPanel(AdminDashboard $dashboard): string
    {
        if ($dashboard->topUsers === []) {
            return self::panel('Top Users', ['No watch activity yet.']);
        }

        $lines = [];
        foreach (array_slice($dashboard->topUsers, 0, self::PANEL_ROWS) as $user) {
            $plays = $user->playCount === 1 ? 'play' : 'plays';
            $lines[] = $user->label() . ' — ' . $user->playCount . ' ' . $plays;
        }

        return self::panel('Top Users', $lines);
    }

    private function topMediaPanel(AdminDashboard $dashboard): string
    {
        if ($dashboard->topMedia === []) {
            return self::panel('Top Media', ['No play history yet.']);
        }

        $lines = [];
        foreach (array_slice($dashboard->topMedia, 0, self::PANEL_ROWS) as $item) {
            $plays = $item->playCount === 1 ? 'play' : 'plays';
            $lines[] = $item->label() . ' — ' . $item->playCount . ' ' . $plays;
        }

        return self::panel('Top Media', $lines);
    }

    private function activityPanel(AdminDashboard $dashboard): string
    {
        if ($dashboard->activity === []) {
            return self::panel('Recent Activity', ['No recent activity.']);
        }

        $lines = [];
        foreach (array_slice($dashboard->activity, 0, self::PANEL_ROWS) as $entry) {
            $lines[] = $entry->occurredAt . '  ' . $entry->actorLabel() . '  ' . $entry->eventType;
        }

        return self::panel('Recent Activity', $lines);
    }

    /**
     * A titled panel: the heading then its lines, each indented two spaces.
     *
     * @param list<string> $lines
     */
    private static function panel(string $title, array $lines): string
    {
        $out = '  ' . $title;
        foreach ($lines as $line) {
            $out .= "\n    " . $line;
        }

        return $out;
    }

    /**
     * Humanize a byte count to a KiB/MiB/GiB/… string (binary 1024 steps),
     * rounded to one decimal above bytes. A negative count clamps to "0 B".
     */
    private static function humanBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        $size = (float) $bytes;
        $unit = 0;
        while ($size >= 1024.0 && $unit < count($units) - 1) {
            $size /= 1024.0;
            ++$unit;
        }

        return $unit === 0
            ? $bytes . ' B'
            : number_format($size, 1) . ' ' . $units[$unit];
    }

    // ---- immutable copies (clone-mutate) -------------------------------

    private function withDashboard(AdminDashboard $dashboard): self
    {
        $next = clone $this;
        $next->dashboard = $dashboard;
        $next->loaded = true;
        $next->error = null;

        return $next;
    }

    private function withError(string $error): self
    {
        $next = clone $this;
        $next->error = $error;
        $next->loaded = true;

        return $next;
    }

    /** A copy back in the loading state (a manual `r` refetch). */
    private function reloading(): self
    {
        $next = clone $this;
        $next->loaded = false;
        $next->error = null;

        return $next;
    }

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
        return 'Dashboard';
    }

    public function withCrumbs(array $trail): static
    {
        $next = clone $this;
        $next->crumbs = $trail;

        return $next;
    }

    // ---- accessors (for tests) ----------------------------------------

    public function isLoaded(): bool
    {
        return $this->loaded;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function dashboard(): ?AdminDashboard
    {
        return $this->dashboard;
    }
}
