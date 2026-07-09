<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Admin\Backup;
use Phlix\Console\Api\Dto\Admin\BackupSchedule;
use Phlix\Console\Msg\AdminBackupActionDoneMsg;
use Phlix\Console\Msg\AdminBackupActionFailedMsg;
use Phlix\Console\Msg\AdminBackupFailedMsg;
use Phlix\Console\Msg\AdminBackupScheduleUpdatedMsg;
use Phlix\Console\Msg\AdminBackupsLoadedMsg;
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
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Form;

/**
 * The admin Backup surface: a windowed {@see Table} of every backup archive
 * (Label/ID · Created · Size · S3) plus a schedule line (auto-interval,
 * retention, next run) driven by lifecycle actions.
 *
 * Actions operate on the selected backup or the schedule: `c` create (with an
 * optional inline label via a candy-forms input — empty allowed → null), `x`
 * delete (a y/n confirm), `R` restore (a STRONG confirm whose prompt warns it
 * OVERWRITES current data — only `y` performs it), `s` upload to S3 (a y/n
 * confirm), `e` edit the schedule (a small two-field numeric candy-forms form,
 * each validated > 0), `r` refresh. On success the server `message` is toasted
 * and the list (and schedule, when changed) refetched; on failure the server
 * `error` is toasted and the list left unchanged; an auth failure surfaces a
 * session expiry.
 *
 * The client is injected (built locally by the App from its shared ApiClient, so
 * the App holds no AdminClient field). Stable collaborators are readonly; the
 * loaded data, selection, busy flag, the pending confirm, and the embedded
 * label / schedule forms are private mutable view state set via clone-mutate
 * (the established screen idiom).
 *
 * SCOPE: list + schedule + create / delete / restore / S3-upload / edit-schedule
 * only. S3 download and the config-file editor are deferred.
 */
final class AdminBackupScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LOAD_FAILED = 'Could not load the backups.';
    private const HINT = 'c create   x delete   R restore   s upload-S3   e schedule   r refresh   Esc back';
    private const LABEL_HINT = 'Enter  create      Esc  cancel';
    private const SCHEDULE_HINT = 'Enter  save      Esc  cancel';

    /** Confirmable actions armed before firing. */
    private const ACTION_DELETE = 'delete';
    private const ACTION_RESTORE = 'restore';
    private const ACTION_UPLOAD_S3 = 'upload-s3';

    /** @var list<Backup> */
    private array $backups = [];
    private ?BackupSchedule $schedule = null;
    private bool $loaded = false;
    private ?string $error = null;

    private int $selected = 0;

    /** A fetch / action is in flight (input that mutates is ignored while busy). */
    private bool $busy = false;

    /** An armed (action, backup) confirmation, or null when none is pending. */
    private ?BackupPendingAction $pending = null;

    /** The embedded create-label form while the label input is open, else null. */
    private ?Form $labelForm = null;

    /** The embedded schedule-edit form while open, else null. */
    private ?Form $scheduleForm = null;

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

    /** Fetch the list AND the schedule together; surface both via one Msg. */
    private function fetchCmd(): \Closure
    {
        $admin = $this->admin;

        return Cmd::promise(static fn () => $admin->backups()->then(
            /**
             * @param list<Backup> $backups
             * @return PromiseInterface<Msg>
             */
            static fn (array $backups): PromiseInterface => $admin->backupSchedule()->then(
                static fn (BackupSchedule $schedule): Msg => new AdminBackupsLoadedMsg($backups, $schedule),
            ),
        )->then(
            static fn (Msg $msg): Msg => $msg,
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminBackupFailedMsg(self::LOAD_FAILED),
        ));
    }

    /**
     * Build the command for a fired action: the action's promise mapped to a
     * done/failed Msg with the given success message (the server's own message).
     *
     * @param PromiseInterface<string> $promise
     */
    private function actionCmd(PromiseInterface $promise, string $fallback): \Closure
    {
        return Cmd::promise(static fn () => $promise->then(
            static fn (string $message): Msg => new AdminBackupActionDoneMsg($message === '' ? $fallback : $message),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminBackupActionFailedMsg($e->getMessage()),
        ));
    }

    // ---- update --------------------------------------------------------

    /** @return array{self, ?\Closure} */
    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [$this->resizedTo($msg->cols, $msg->rows), null];
        }
        // An open embedded form captures all keys.
        if ($this->labelForm !== null) {
            return $this->updateLabel($msg, $this->labelForm);
        }
        if ($this->scheduleForm !== null) {
            return $this->updateSchedule($msg, $this->scheduleForm);
        }
        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }
        if ($msg instanceof AdminBackupsLoadedMsg) {
            return [$this->withBackups($msg->backups, $msg->schedule), null];
        }
        if ($msg instanceof AdminBackupFailedMsg) {
            return [$this->withError($msg->message), null];
        }
        if ($msg instanceof AdminBackupActionDoneMsg) {
            return $this->onActionDone($msg);
        }
        if ($msg instanceof AdminBackupActionFailedMsg) {
            return [$this->idle(), Cmd::send(ShowToastMsg::error($msg->message))];
        }
        if ($msg instanceof AdminBackupScheduleUpdatedMsg) {
            return $this->onScheduleUpdated($msg);
        }

        return [$this, null];
    }

    public function view(): string
    {
        if ($this->labelForm !== null) {
            return Chrome::frame('Admin · Backup · Create', $this->labelBody($this->labelForm), self::LABEL_HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
        }
        if ($this->scheduleForm !== null) {
            return Chrome::frame('Admin · Backup · Schedule', $this->scheduleBody($this->scheduleForm), self::SCHEDULE_HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
        }

        return Chrome::frame('Admin · Backup', $this->body(), self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
    }

    // ---- input ---------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        // An armed confirm captures y/n/Esc before anything else.
        if ($this->pending !== null) {
            return $this->handleConfirmKey($msg, $this->pending);
        }

        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Up) {
            return [$this->moveSelection(-1), null];
        }
        if ($msg->type === KeyType::Down) {
            return [$this->moveSelection(1), null];
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
        if ($this->busy) {
            return [$this, null];
        }
        if ($rune === 'c') {
            return [$this->openLabel(), null];
        }
        if ($rune === 'e') {
            return [$this->openSchedule(), null];
        }

        // The remaining keys are per-row actions; they need a selected backup.
        $backup = $this->selectedBackup();
        if ($backup === null) {
            return [$this, null];
        }

        return match ($rune) {
            'x' => [$this->arm(self::ACTION_DELETE, $backup), null],
            'R' => [$this->arm(self::ACTION_RESTORE, $backup), null],
            's' => [$this->arm(self::ACTION_UPLOAD_S3, $backup), null],
            default => [$this, null],
        };
    }

    /** @return array{self, ?\Closure} */
    private function handleConfirmKey(KeyMsg $msg, BackupPendingAction $pending): array
    {
        if ($msg->type === KeyType::Char && $msg->rune === 'y') {
            return $this->fire($pending);
        }
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'n')) {
            return [$this->cancelPending(), null];
        }

        return [$this, null];
    }

    // ---- create-label input (embedded candy-forms) ---------------------

    /**
     * Drive the embedded create-label form. candy-forms' Form returns
     * Cmd::quit() on submit/abort; we intercept that — a submit (with an
     * optional, possibly-empty label) creates the backup, an abort cancels.
     *
     * @return array{self, ?\Closure}
     */
    private function updateLabel(Msg $msg, Form $form): array
    {
        /** @var array{0: Form, 1: ?\Closure} $result candy-forms' Form::update returns Model's loose `:array`; narrow it. */
        $result = $form->update($msg);
        [$next, $cmd] = $result;

        if ($next->isAborted()) {
            return [$this->closeLabel(), null];
        }

        if ($next->isSubmitted()) {
            $label = trim($next->getString('label'));

            return [$this->closeLabel()->working(), $this->actionCmd(
                $this->admin->createBackup($label === '' ? null : $label),
                'Backup created',
            )];
        }

        return [$this->withLabelForm($next), $cmd];
    }

    private static function buildLabelForm(): Form
    {
        return Form::new(
            Input::new('label')
                ->withTitle('Backup label (optional)')
                ->withPlaceholder('e.g. pre-upgrade'),
        );
    }

    // ---- schedule-edit form (embedded candy-forms) ---------------------

    /**
     * Drive the embedded schedule-edit form. On submit both numeric fields
     * (each validated > 0 by the form) are pushed to the server and the
     * refreshed schedule is swapped in; an abort cancels.
     *
     * @return array{self, ?\Closure}
     */
    private function updateSchedule(Msg $msg, Form $form): array
    {
        /** @var array{0: Form, 1: ?\Closure} $result candy-forms' Form::update returns Model's loose `:array`; narrow it. */
        $result = $form->update($msg);
        [$next, $cmd] = $result;

        if ($next->isAborted()) {
            return [$this->closeSchedule(), null];
        }

        if ($next->isSubmitted()) {
            // candy-forms gates submit on field validation, so both fields are
            // guaranteed whole numbers > 0 here (see their isPositiveInt
            // validators): an invalid value keeps the form open with an inline
            // error and never reaches this branch.
            $interval = $next->getInt('interval');
            $retention = $next->getInt('retention');

            return [$this->closeSchedule()->working(), Cmd::promise(fn () => $this->admin->updateBackupSchedule($interval, $retention)->then(
                static fn (BackupSchedule $schedule): Msg => new AdminBackupScheduleUpdatedMsg($schedule),
                static fn (\Throwable $e): Msg => $e instanceof AuthError
                    ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                    : new AdminBackupActionFailedMsg($e->getMessage()),
            ))];
        }

        return [$this->withScheduleForm($next), $cmd];
    }

    /** A two-field numeric form pre-filled from the current schedule. */
    private function buildScheduleForm(): Form
    {
        $schedule = $this->schedule;
        $interval = $schedule !== null ? (string) $schedule->autoBackupIntervalDays : '';
        $retention = $schedule !== null ? (string) $schedule->retentionCount : '';

        return Form::new(
            Input::new('interval')
                ->withTitle('Auto-backup interval (days)')
                ->withPlaceholder('7')
                ->withValue($interval)
                ->validation(static fn (string $v): bool => self::isPositiveInt($v), 'Enter a whole number greater than 0.'),
            Input::new('retention')
                ->withTitle('Backups to retain')
                ->withPlaceholder('5')
                ->withValue($retention)
                ->validation(static fn (string $v): bool => self::isPositiveInt($v), 'Enter a whole number greater than 0.'),
        );
    }

    /** A value is a positive whole number (the schedule fields' rule). */
    private static function isPositiveInt(string $value): bool
    {
        return ctype_digit($value) && (int) $value > 0;
    }

    // ---- actions -------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function fire(BackupPendingAction $pending): array
    {
        $id = $pending->backup->id;
        [$promise, $fallback] = match ($pending->action) {
            self::ACTION_DELETE => [$this->admin->deleteBackup($id), 'Backup deleted'],
            self::ACTION_RESTORE => [$this->admin->restoreBackup($id), 'Backup restored'],
            default => [$this->admin->uploadBackupToS3($id), 'Backup uploaded to S3'],
        };

        return [$this->working(), $this->actionCmd($promise, $fallback)];
    }

    /** @return array{self, ?\Closure} */
    private function onActionDone(AdminBackupActionDoneMsg $msg): array
    {
        // Refetch the list (and schedule) so the change shows.
        return [$this->working(), Cmd::batch(Cmd::send(ShowToastMsg::success($msg->message)), $this->fetchCmd())];
    }

    /** @return array{self, ?\Closure} */
    private function onScheduleUpdated(AdminBackupScheduleUpdatedMsg $msg): array
    {
        $next = $this->idle();
        $next->schedule = $msg->schedule;

        return [$next, Cmd::send(ShowToastMsg::success('Schedule updated'))];
    }

    /** @return array{self, ?\Closure} */
    private function refresh(): array
    {
        $next = clone $this;
        $next->loaded = false;
        $next->error = null;
        $next->pending = null;

        return [$next, $next->fetchCmd()];
    }

    // ---- clone-mutate copies -------------------------------------------

    /** @param list<Backup> $backups */
    private function withBackups(array $backups, BackupSchedule $schedule): self
    {
        $next = clone $this;
        $next->backups = $backups;
        $next->schedule = $schedule;
        $next->loaded = true;
        $next->busy = false;
        $next->error = null;
        $next->pending = null;
        $next->selected = $backups === [] ? 0 : min($this->selected, count($backups) - 1);

        return $next;
    }

    private function withError(string $error): self
    {
        $next = clone $this;
        $next->error = $error;
        $next->loaded = false;
        $next->busy = false;
        $next->pending = null;

        return $next;
    }

    /** Enter the busy (in-flight) state, clearing any armed confirm. */
    private function working(): self
    {
        $next = clone $this;
        $next->busy = true;
        $next->pending = null;

        return $next;
    }

    /** Leave the busy state (after a failed action) without touching the list. */
    private function idle(): self
    {
        $next = clone $this;
        $next->busy = false;
        $next->pending = null;

        return $next;
    }

    private function arm(string $action, Backup $backup): self
    {
        $next = clone $this;
        $next->pending = new BackupPendingAction($action, $backup);

        return $next;
    }

    private function cancelPending(): self
    {
        $next = clone $this;
        $next->pending = null;

        return $next;
    }

    private function openLabel(): self
    {
        return $this->withLabelForm(self::buildLabelForm());
    }

    private function closeLabel(): self
    {
        return $this->withLabelForm(null);
    }

    private function withLabelForm(?Form $form): self
    {
        $next = clone $this;
        $next->labelForm = $form;
        $next->pending = null;

        return $next;
    }

    private function openSchedule(): self
    {
        return $this->withScheduleForm($this->buildScheduleForm());
    }

    private function closeSchedule(): self
    {
        return $this->withScheduleForm(null);
    }

    private function withScheduleForm(?Form $form): self
    {
        $next = clone $this;
        $next->scheduleForm = $form;
        $next->pending = null;

        return $next;
    }

    private function moveSelection(int $delta): self
    {
        $count = count($this->backups);
        if ($count === 0) {
            return $this;
        }
        $selected = max(0, min($count - 1, $this->selected + $delta));
        if ($selected === $this->selected) {
            return $this;
        }
        $next = clone $this;
        $next->selected = $selected;

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
            return "\n" . $this->scheduleLine() . "\n\n  Loading backups…";
        }
        if ($this->error !== null) {
            return "\n  {$this->error}\n\n  Press r to retry.";
        }
        if ($this->backups === []) {
            return "\n" . $this->scheduleLine() . "\n\n  No backups yet.\n\n" . $this->statusLine();
        }

        $rows = [];
        foreach ($this->backups as $backup) {
            $rows[] = [
                $backup->displayLabel(),
                $backup->createdAt ?? '—',
                self::humanBytes($backup->sizeBytes),
                $backup->isS3 ? '✓' : '–',
            ];
        }

        $table = Table::render([
            ['title' => 'Label / ID', 'width' => 0],
            ['title' => 'Created', 'width' => 20],
            ['title' => 'Size', 'width' => 12, 'align' => 'right'],
            ['title' => 'S3', 'width' => 4, 'align' => 'right'],
        ], $rows, $this->selected, $this->cols - 4, $this->viewportRows());

        return "\n" . $this->scheduleLine() . "\n" . $table . "\n\n" . $this->statusLine();
    }

    /** The schedule summary line: interval, retention, and next run. */
    private function scheduleLine(): string
    {
        $schedule = $this->schedule;
        if ($schedule === null) {
            return '  Schedule: —';
        }

        $next = $schedule->nextScheduledBackup ?? 'not scheduled';

        return "  Auto-backup every {$schedule->autoBackupIntervalDays} days, keep {$schedule->retentionCount}  ·  next: {$next}";
    }

    /**
     * The status line under the table: the armed confirm prompt when one is
     * pending, else the busy note, else a hint.
     */
    private function statusLine(): string
    {
        $pending = $this->pending;
        if ($pending !== null) {
            return '  ' . $pending->prompt();
        }
        if ($this->busy) {
            return '  Working…';
        }

        return '  select a backup and press an action key, or c to create one.';
    }

    private function labelBody(Form $form): string
    {
        $lines = ['Create a backup. A label is optional.', ''];

        return implode("\n", $lines) . $form->view();
    }

    private function scheduleBody(Form $form): string
    {
        $lines = ['Edit the automatic-backup schedule. Both values must be greater than 0.', ''];

        return implode("\n", $lines) . $form->view();
    }

    private function viewportRows(): int
    {
        // The frame body holds the schedule line, then the table (header + rule =
        // 2 extra rows), then a blank line + the status line. Window the data rows
        // to the body height less those chrome rows so the selected row is never
        // clipped.
        return max(1, Chrome::bodyHeight($this->rows) - 5);
    }

    /**
     * Humanize a byte count to a KiB/MiB/GiB/… string (binary 1024 steps),
     * rounded to one decimal above bytes. A non-positive count clamps to "0 B".
     * (Mirrors the AdminDashboardScreen helper — a small local copy avoids a
     * cross-screen dependency.)
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

    private function selectedBackup(): ?Backup
    {
        return $this->backups[$this->selected] ?? null;
    }

    // ---- breadcrumb ----------------------------------------------------

    public function crumbLabel(): string
    {
        return 'Backup';
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

    /** @return list<Backup> */
    public function backupList(): array
    {
        return $this->backups;
    }

    public function schedule(): ?BackupSchedule
    {
        return $this->schedule;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function selectedIndex(): int
    {
        return $this->selected;
    }

    public function isBusy(): bool
    {
        return $this->busy;
    }

    public function pendingAction(): ?BackupPendingAction
    {
        return $this->pending;
    }

    public function isCreating(): bool
    {
        return $this->labelForm !== null;
    }

    public function isEditingSchedule(): bool
    {
        return $this->scheduleForm !== null;
    }
}
