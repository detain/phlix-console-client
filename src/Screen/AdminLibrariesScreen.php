<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Admin\ScanJob;
use Phlix\Console\Api\Dto\Library;
use Phlix\Console\Msg\AdminLibrariesFailedMsg;
use Phlix\Console\Msg\AdminLibrariesLoadedMsg;
use Phlix\Console\Msg\AdminLibraryActionDoneMsg;
use Phlix\Console\Msg\AdminLibraryActionFailedMsg;
use Phlix\Console\Msg\AdminScanStatusLoadedMsg;
use Phlix\Console\Msg\AdminScanStatusTickMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\Table;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\Util\Width;

/**
 * The admin Libraries surface: a windowed {@see Table} of every media library
 * (Name · Type · Items) beside a status panel for the SELECTED library showing
 * its latest {@see ScanJob} readout.
 *
 * Row actions operate on the selected library: `s` scan (add new items), `R`
 * rescan (an inline y/n confirm — purge + rescan, so it is gated), `m`
 * match-metadata, `r` refresh the list. On a queued action the server `message`
 * is toasted and the selected library's scan-status is fetched immediately
 * (starting the live poll). ↑/↓ move the selection, re-fetching the new
 * selection's status and resetting the poll epoch.
 *
 * LIVE POLL: while the selected library's job {@see ScanJob::isActive()}, an
 * epoch-guarded {@see Cmd::tick} re-fetches scan-status for the CURRENT selection
 * and re-arms only while still active. The epoch is bumped on a selection change
 * and on leaving (Esc/q), so any in-flight tick / late status is dropped. A
 * status that resolves for a NON-CURRENT library id is dropped too (the
 * owner-tagged-async pattern).
 *
 * HONEST PROGRESS: the scan-job row carries no total / denominator, so the panel
 * shows a status badge + found/added/updated/removed counters + a truncated
 * current path + an error when failed — NOT a fake percentage bar.
 *
 * The client is injected (built locally by the App from its shared ApiClient, so
 * the App holds no AdminClient field). Stable collaborators are readonly; the
 * loaded data, selection, the selected library's status, the poll epoch, the busy
 * flag, and the armed rescan confirm are private mutable view state set via
 * clone-mutate (the established screen idiom).
 *
 * SCOPE: list + scan/rescan/match + live status only. Scan-history is deferred to
 * a follow-up.
 */
final class AdminLibrariesScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    /** Seconds between scan-status polls while the selected job is active. */
    private const STATUS_INTERVAL = 2.0;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LOAD_FAILED = 'Could not load the libraries.';
    private const HINT = 's scan   R rescan   m match   r refresh   Esc back';

    /** @var list<Library> */
    private array $libraries = [];
    private bool $loaded = false;
    private ?string $error = null;

    private int $selected = 0;

    /** The selected library's latest scan-status, or null (no job / not yet loaded). */
    private ?ScanJob $status = null;

    /** The status-poll generation; an armed status tick / fetch carries this. */
    private int $pollEpoch = 0;

    /** A fetch / action is in flight (input that mutates is ignored while busy). */
    private bool $busy = false;

    /** An armed rescan awaiting a y/n confirm, or null when none is pending. */
    private ?Library $pendingRescan = null;

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

    // ---- fetch ---------------------------------------------------------

    private function fetchCmd(): \Closure
    {
        return Cmd::promise(fn () => $this->admin->libraries()->then(
            /** @param list<Library> $libraries */
            static fn (array $libraries): Msg => new AdminLibrariesLoadedMsg($libraries),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminLibrariesFailedMsg(self::LOAD_FAILED),
        ));
    }

    /**
     * Fetch the given library's scan-status, tagging the resolved Msg with the
     * library id (so a status for a since-changed selection is dropped) and the
     * poll epoch (so a stale generation's status is dropped).
     */
    private function statusFetchCmd(string $libraryId): \Closure
    {
        return Cmd::promise(fn (): PromiseInterface => $this->admin->libraryScanStatus($libraryId)->then(
            static fn (?ScanJob $job): Msg => new AdminScanStatusLoadedMsg($libraryId, $job),
            // A failed status poll is best-effort — keep the last-known readout,
            // never crash; surface only an auth failure.
            static fn (\Throwable $e): ?Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : null,
        ));
    }

    private function statusTickCmd(int $epoch): \Closure
    {
        return Cmd::tick(self::STATUS_INTERVAL, static fn (): Msg => new AdminScanStatusTickMsg($epoch));
    }

    /**
     * Build the command for a fired action: the action's promise mapped to a
     * done/failed Msg with the given success message.
     *
     * @param PromiseInterface<string> $promise
     */
    private function actionCmd(PromiseInterface $promise): \Closure
    {
        return Cmd::promise(static fn () => $promise->then(
            static fn (string $message): Msg => new AdminLibraryActionDoneMsg($message),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminLibraryActionFailedMsg($e->getMessage()),
        ));
    }

    // ---- update --------------------------------------------------------

    /** @return array{self, ?\Closure} */
    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [$this->resizedTo($msg->cols, $msg->rows), null];
        }
        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }
        if ($msg instanceof AdminLibrariesLoadedMsg) {
            return $this->onLibrariesLoaded($msg->libraries);
        }
        if ($msg instanceof AdminLibrariesFailedMsg) {
            return [$this->withError($msg->message), null];
        }
        if ($msg instanceof AdminScanStatusLoadedMsg) {
            return $this->onStatusLoaded($msg->libraryId, $msg->job);
        }
        if ($msg instanceof AdminScanStatusTickMsg) {
            return $this->onStatusTick($msg->epoch);
        }
        if ($msg instanceof AdminLibraryActionDoneMsg) {
            return $this->onActionDone($msg);
        }
        if ($msg instanceof AdminLibraryActionFailedMsg) {
            return [$this->idle(), Cmd::send(ShowToastMsg::error($msg->message))];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame('Admin · Libraries', $this->body(), self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
    }

    // ---- input ---------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        // An armed rescan captures y/n/Esc before anything else.
        if ($this->pendingRescan !== null) {
            return $this->handleConfirmKey($msg, $this->pendingRescan);
        }

        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            // Leaving: bump the epoch so any in-flight tick / late status is dropped.
            return [$this->leaving(), Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Up) {
            return $this->moveSelection(-1);
        }
        if ($msg->type === KeyType::Down) {
            return $this->moveSelection(1);
        }
        if ($msg->type === KeyType::Char) {
            return $this->handleCharKey($msg->rune);
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function handleCharKey(string $rune): array
    {
        if ($rune === 'r') {
            return $this->refresh();
        }

        // The remaining keys are per-row actions; they need a selected library and
        // an idle screen.
        if ($this->busy) {
            return [$this, null];
        }
        $library = $this->selectedLibrary();
        if ($library === null) {
            return [$this, null];
        }

        return match ($rune) {
            's' => [$this->working(), $this->actionCmd($this->admin->scanLibrary($library->id))],
            'm' => [$this->working(), $this->actionCmd($this->admin->matchLibraryMetadata($library->id))],
            'R' => [$this->arm($library), null],
            default => [$this, null],
        };
    }

    /** @return array{self, ?\Closure} */
    private function handleConfirmKey(KeyMsg $msg, Library $library): array
    {
        if ($msg->type === KeyType::Char && $msg->rune === 'y') {
            return [$this->working(), $this->actionCmd($this->admin->rescanLibrary($library->id))];
        }
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'n')) {
            return [$this->cancelPending(), null];
        }

        return [$this, null];
    }

    // ---- async handlers ------------------------------------------------

    /**
     * The list arrived: store it, clamp the selection, and (when non-empty) fetch
     * the selected library's scan-status under a fresh poll epoch.
     *
     * @param list<Library> $libraries
     * @return array{self, ?\Closure}
     */
    private function onLibrariesLoaded(array $libraries): array
    {
        $next = $this->withLibraries($libraries);
        $library = $next->selectedLibrary();
        if ($library === null) {
            return [$next, null];
        }

        return [$next, $next->statusFetchCmd($library->id)];
    }

    /**
     * A scan-status resolved. Drop it unless it belongs to the CURRENTLY-selected
     * library (the owner tag). Store it; when the job is still active, arm the next
     * poll tick under the current epoch.
     *
     * @return array{self, ?\Closure}
     */
    private function onStatusLoaded(string $libraryId, ?ScanJob $job): array
    {
        $library = $this->selectedLibrary();
        if ($library === null || $library->id !== $libraryId) {
            return [$this, null];
        }

        $next = $this->withStatus($job);
        if ($job !== null && $job->isActive()) {
            return [$next, $next->statusTickCmd($next->pollEpoch)];
        }

        return [$next, null];
    }

    /**
     * A poll tick fired. Drop a stale generation's tick (the selection moved / the
     * screen is leaving). Otherwise re-fetch the current selection's scan-status
     * under the same epoch (the re-arm happens when that status resolves & is still
     * active).
     *
     * @return array{self, ?\Closure}
     */
    private function onStatusTick(int $epoch): array
    {
        $library = $this->selectedLibrary();
        if ($epoch !== $this->pollEpoch || $library === null) {
            return [$this, null];
        }

        return [$this, $this->statusFetchCmd($library->id)];
    }

    /**
     * An action (scan/rescan/match) was queued: toast the server message, leave the
     * busy state, and immediately fetch the selected library's scan-status under a
     * FRESH epoch (which starts the live poll). The epoch bump is essential: an
     * action can be fired while a scan is already running (actions are gated on
     * `busy`, not on an active poll), so a tick may already be armed under the old
     * epoch — bumping strands it so only ONE live poll chain survives.
     *
     * @return array{self, ?\Closure}
     */
    private function onActionDone(AdminLibraryActionDoneMsg $msg): array
    {
        $next = $this->idle();
        $next->pollEpoch = $this->pollEpoch + 1;
        $library = $next->selectedLibrary();
        $toast = Cmd::send(ShowToastMsg::success($msg->message === '' ? 'Library job queued' : $msg->message));
        if ($library === null) {
            return [$next, $toast];
        }

        return [$next, Cmd::batch($toast, $next->statusFetchCmd($library->id))];
    }

    // ---- selection / refresh -------------------------------------------

    /** @return array{self, ?\Closure} */
    private function moveSelection(int $delta): array
    {
        $count = count($this->libraries);
        if ($count === 0) {
            return [$this, null];
        }
        $selected = max(0, min($count - 1, $this->selected + $delta));
        if ($selected === $this->selected) {
            return [$this, null];
        }

        // Moving the selection bumps the poll epoch (stranding the old library's
        // poll) and clears the shown status, then fetches the new selection's
        // status. $selected is clamped into range, so the row is always present.
        $library = $this->libraries[$selected];
        $next = clone $this;
        $next->selected = $selected;
        $next->status = null;
        $next->pollEpoch = $this->pollEpoch + 1;

        return [$next, $next->statusFetchCmd($library->id)];
    }

    /** @return array{self, ?\Closure} */
    private function refresh(): array
    {
        $next = clone $this;
        $next->loaded = false;
        $next->error = null;
        $next->status = null;
        $next->pendingRescan = null;
        $next->pollEpoch = $this->pollEpoch + 1;

        return [$next, $next->fetchCmd()];
    }

    // ---- clone-mutate copies -------------------------------------------

    /** @param list<Library> $libraries */
    private function withLibraries(array $libraries): self
    {
        $next = clone $this;
        $next->libraries = $libraries;
        $next->loaded = true;
        $next->busy = false;
        $next->error = null;
        $next->pendingRescan = null;
        $next->selected = $libraries === [] ? 0 : min($this->selected, count($libraries) - 1);
        // A fresh list starts a fresh poll generation.
        $next->pollEpoch = $this->pollEpoch + 1;

        return $next;
    }

    private function withError(string $error): self
    {
        $next = clone $this;
        $next->error = $error;
        $next->loaded = false;
        $next->busy = false;
        $next->pendingRescan = null;

        return $next;
    }

    private function withStatus(?ScanJob $status): self
    {
        $next = clone $this;
        $next->status = $status;

        return $next;
    }

    /** Enter the busy (in-flight) state, clearing any armed confirm. */
    private function working(): self
    {
        $next = clone $this;
        $next->busy = true;
        $next->pendingRescan = null;

        return $next;
    }

    /** Leave the busy state (after a failed action) without touching the list. */
    private function idle(): self
    {
        $next = clone $this;
        $next->busy = false;
        $next->pendingRescan = null;

        return $next;
    }

    private function arm(Library $library): self
    {
        $next = clone $this;
        $next->pendingRescan = $library;

        return $next;
    }

    private function cancelPending(): self
    {
        $next = clone $this;
        $next->pendingRescan = null;

        return $next;
    }

    /** Bump the poll epoch so any in-flight tick / late status is stranded on exit. */
    private function leaving(): self
    {
        $next = clone $this;
        $next->pollEpoch = $this->pollEpoch + 1;
        $next->pendingRescan = null;

        return $next;
    }

    private function resizedTo(int $cols, int $rows): self
    {
        $next = clone $this;
        $next->cols = $cols;
        $next->rows = $rows;

        return $next;
    }

    // ---- rendering -----------------------------------------------------

    private function body(): string
    {
        if (!$this->loaded && $this->error === null) {
            return "\n  Loading libraries…";
        }
        if ($this->error !== null) {
            return "\n  {$this->error}\n\n  Press r to retry.";
        }
        if ($this->libraries === []) {
            return "\n  No libraries configured.";
        }

        $rows = [];
        foreach ($this->libraries as $library) {
            $rows[] = [
                $library->name === '' ? '(unnamed)' : $library->name,
                $library->type === '' ? '—' : $library->type,
                (string) $library->itemCount,
            ];
        }

        $table = Table::render([
            ['title' => 'Name', 'width' => 0],
            ['title' => 'Type', 'width' => 16],
            ['title' => 'Items', 'width' => 10, 'align' => 'right'],
        ], $rows, $this->selected, $this->cols - 4, $this->viewportRows());

        return "\n" . $this->headerLine() . "\n" . $table . "\n\n" . $this->statusPanel();
    }

    private function headerLine(): string
    {
        $count = count($this->libraries);
        $label = $count === 1 ? '1 library' : "{$count} libraries";

        return '  Libraries: ' . $label;
    }

    /**
     * The status panel for the selected library: the armed rescan prompt when one
     * is pending, else the busy note, else the selected library's scan-job readout.
     */
    private function statusPanel(): string
    {
        $pending = $this->pendingRescan;
        if ($pending !== null) {
            $name = $pending->name === '' ? $pending->id : $pending->name;

            return "  Rescan '{$name}'? This purges then rescans the library. (y/n)";
        }
        if ($this->busy) {
            return '  Working…';
        }

        return $this->scanReadout();
    }

    /**
     * An HONEST scan readout for the selected library: the status badge + counters
     * (found/added/updated/removed) + a truncated current path + an error when
     * failed. There is NO percentage — the job row carries no total.
     */
    private function scanReadout(): string
    {
        $job = $this->status;
        if ($job === null) {
            return '  No scan run yet.   Press s to scan, m to match metadata, R to rescan.';
        }

        $lines = ['  Scan status: ' . $job->summary()];

        if ($job->currentPath !== null) {
            $path = Width::truncate('  Path: ' . $job->currentPath, max(10, $this->cols - 4));
            $lines[] = $path;
        }
        if ($job->error !== null) {
            $lines[] = Width::truncate('  Error: ' . $job->error, max(10, $this->cols - 4));
        }

        return implode("\n", $lines);
    }

    private function viewportRows(): int
    {
        // The frame body holds the header line, then the table (header + rule = 2
        // extra rows), then a blank line + up to 3 status-panel lines (status, path,
        // error). Window the data rows to the body height less that chrome so the
        // selected row is never clipped.
        return max(1, Chrome::bodyHeight($this->rows) - 7);
    }

    private function selectedLibrary(): ?Library
    {
        return $this->libraries[$this->selected] ?? null;
    }

    // ---- breadcrumb ----------------------------------------------------

    public function crumbLabel(): string
    {
        return 'Libraries';
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

    /** @return list<Library> */
    public function libraryList(): array
    {
        return $this->libraries;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function selectedIndex(): int
    {
        return $this->selected;
    }

    public function scanStatus(): ?ScanJob
    {
        return $this->status;
    }

    /** The current scan-status poll generation (an armed tick / fetch carries this). */
    public function pollEpoch(): int
    {
        return $this->pollEpoch;
    }

    public function isBusy(): bool
    {
        return $this->busy;
    }

    public function pendingRescan(): ?Library
    {
        return $this->pendingRescan;
    }
}
