<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Admin\AdminUser;
use Phlix\Console\Msg\AdminUserActionDoneMsg;
use Phlix\Console\Msg\AdminUserActionFailedMsg;
use Phlix\Console\Msg\AdminUsersFailedMsg;
use Phlix\Console\Msg\AdminUsersLoadedMsg;
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

/**
 * The admin Users surface: a windowed {@see Table} of every server user (Username
 * · Email · Role · Status · Last login), cycled through a status filter and
 * driven by per-row actions.
 *
 * `f` cycles the status filter (All → Pending → Active → Disabled → All),
 * refetching on change; the header shows the active filter and the row count.
 * Row actions operate on the selected user: `a` approve, `d` disable, `x` delete,
 * `j` reject, `m` toggle admin, `p` reset password, `r` refresh. Destructive
 * actions (delete / reject / disable / toggle-admin) arm an inline confirm shown
 * on the status line ("Delete user 'bob'? (y/n)") — `y` performs it, `n`/Esc
 * cancel. A password reset reveals the once-shown new password prominently (a
 * toast plus the status line). On success the server message is toasted and the
 * list refetched; on failure the server `error` is toasted and the list is left
 * unchanged; an auth failure surfaces a session expiry.
 *
 * The client is injected (built locally by the App from its shared ApiClient, so
 * the App holds no AdminClient field). Stable collaborators are readonly; the
 * loaded data, selection, filter, busy flag, the pending confirm, and the status
 * note are private mutable view state set via clone-mutate (the established
 * screen idiom).
 *
 * SCOPE: list + filter + the per-row actions only. Create-user / edit-user forms
 * and profiles / parental-controls are deferred to a later PR.
 */
final class AdminUsersScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LOAD_FAILED = 'Could not load the users.';
    private const HINT = 'f filter   a approve  d disable  x delete  j reject  m admin  p reset-pw   r refresh   Esc back';

    /** The status filter cycle: index → server status (null = all). */
    private const FILTERS = [null, 'pending', 'active', 'disabled'];
    private const FILTER_LABELS = ['All', 'Pending', 'Active', 'Disabled'];

    /** Destructive actions that arm an inline (y/n) confirm before firing. */
    private const ACTION_DISABLE = 'disable';
    private const ACTION_DELETE = 'delete';
    private const ACTION_REJECT = 'reject';
    private const ACTION_SET_ADMIN = 'set-admin';

    /** @var list<AdminUser> */
    private array $users = [];
    private bool $loaded = false;
    private ?string $error = null;

    private int $selected = 0;
    private int $filter = 0;

    /** A fetch / action is in flight (input that mutates is ignored while busy). */
    private bool $busy = false;

    /** An armed destructive confirmation, or null when none is pending. */
    private ?PendingAction $pending = null;

    /** A transient status-line note (e.g. the revealed password), or null. */
    private ?string $note = null;

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
        $status = self::FILTERS[$this->filter];

        return Cmd::promise(fn () => $this->admin->users($status)->then(
            /** @param list<AdminUser> $users */
            static fn (array $users): Msg => new AdminUsersLoadedMsg($users),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminUsersFailedMsg(self::LOAD_FAILED),
        ));
    }

    /**
     * Build the command for a fired action: the action's promise mapped to a
     * done/failed Msg. A reset-password resolves the new password (revealed); the
     * others resolve the server message.
     */
    private function actionCmd(string $action, AdminUser $user): \Closure
    {
        $promise = $this->actionPromise($action, $user);
        $name = $user->label();
        $isReset = $action === 'reset-password';

        return Cmd::promise(static fn () => $promise->then(
            static fn (string $result): Msg => $isReset
                ? new AdminUserActionDoneMsg("New password for {$name}: {$result}", $result)
                : new AdminUserActionDoneMsg($result),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminUserActionFailedMsg($e->getMessage()),
        ));
    }

    /** @return PromiseInterface<string> */
    private function actionPromise(string $action, AdminUser $user): PromiseInterface
    {
        return match ($action) {
            'approve' => $this->admin->approveUser($user->id),
            self::ACTION_DISABLE => $this->admin->disableUser($user->id),
            self::ACTION_DELETE => $this->admin->deleteUser($user->id),
            self::ACTION_REJECT => $this->admin->rejectUser($user->id),
            self::ACTION_SET_ADMIN => $this->admin->setUserAdmin($user->id, !$user->isAdmin),
            default => $this->admin->resetUserPassword($user->id),
        };
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
        if ($msg instanceof AdminUsersLoadedMsg) {
            return [$this->withUsers($msg->users), null];
        }
        if ($msg instanceof AdminUsersFailedMsg) {
            return [$this->withError($msg->message), null];
        }
        if ($msg instanceof AdminUserActionDoneMsg) {
            return $this->onActionDone($msg);
        }
        if ($msg instanceof AdminUserActionFailedMsg) {
            return [$this->idle(), Cmd::send(ShowToastMsg::error($msg->message))];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame('Admin · Users', $this->body(), self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
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
        if ($rune === 'f') {
            return $this->cycleFilter();
        }
        if ($rune === 'r') {
            return $this->refresh();
        }

        // The remaining keys are per-row actions; they need a selected user and
        // an idle screen.
        if ($this->busy) {
            return [$this, null];
        }
        $user = $this->selectedUser();
        if ($user === null) {
            return [$this, null];
        }

        return match ($rune) {
            'a' => $this->fire('approve', $user),
            'p' => $this->fire('reset-password', $user),
            'd' => [$this->arm(self::ACTION_DISABLE, $user), null],
            'x' => [$this->arm(self::ACTION_DELETE, $user), null],
            'j' => [$this->arm(self::ACTION_REJECT, $user), null],
            'm' => [$this->arm(self::ACTION_SET_ADMIN, $user), null],
            default => [$this, null],
        };
    }

    /** @return array{self, ?\Closure} */
    private function handleConfirmKey(KeyMsg $msg, PendingAction $pending): array
    {
        if ($msg->type === KeyType::Char && $msg->rune === 'y') {
            return $this->fire($pending->action, $pending->user);
        }
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'n')) {
            return [$this->cancelPending(), null];
        }

        return [$this, null];
    }

    // ---- actions -------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function fire(string $action, AdminUser $user): array
    {
        return [$this->working(), $this->actionCmd($action, $user)];
    }

    /** @return array{self, ?\Closure} */
    private function onActionDone(AdminUserActionDoneMsg $msg): array
    {
        // Reveal a reset password on the status line; refetch the list so the
        // change (status / role / removal) is reflected.
        $next = $this->working();
        $next->note = $msg->revealedPassword !== null ? $msg->message : null;

        $toast = $msg->revealedPassword !== null
            ? ShowToastMsg::success($msg->message)
            : ShowToastMsg::success($msg->message === '' ? 'Done.' : $msg->message);

        return [$next, Cmd::batch(Cmd::send($toast), $this->fetchCmd())];
    }

    /** @return array{self, ?\Closure} */
    private function cycleFilter(): array
    {
        $next = clone $this;
        $next->filter = ($this->filter + 1) % count(self::FILTERS);
        $next->selected = 0;
        $next->loaded = false;
        $next->error = null;
        $next->note = null;

        return [$next, $next->fetchCmd()];
    }

    /** @return array{self, ?\Closure} */
    private function refresh(): array
    {
        $next = clone $this;
        $next->loaded = false;
        $next->error = null;
        $next->pending = null;
        $next->note = null;

        return [$next, $next->fetchCmd()];
    }

    // ---- clone-mutate copies -------------------------------------------

    /** @param list<AdminUser> $users */
    private function withUsers(array $users): self
    {
        $next = clone $this;
        $next->users = $users;
        $next->loaded = true;
        $next->busy = false;
        $next->error = null;
        $next->pending = null;
        $next->selected = $users === [] ? 0 : min($this->selected, count($users) - 1);

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

    private function arm(string $action, AdminUser $user): self
    {
        $next = clone $this;
        $next->pending = new PendingAction($action, $user);
        $next->note = null;

        return $next;
    }

    private function cancelPending(): self
    {
        $next = clone $this;
        $next->pending = null;

        return $next;
    }

    private function moveSelection(int $delta): self
    {
        $count = count($this->users);
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
            return "\n  Loading users…";
        }
        if ($this->error !== null) {
            return "\n  {$this->error}\n\n  Press r to retry.";
        }
        if ($this->users === []) {
            return "\n  No " . strtolower(self::FILTER_LABELS[$this->filter]) . " users.\n\n" . $this->statusLine();
        }

        $rows = [];
        foreach ($this->users as $user) {
            $rows[] = [
                $user->label(),
                $user->email,
                $user->roleLabel(),
                ucfirst($user->status),
                $user->lastLoginAt ?? '—',
            ];
        }

        $table = Table::render([
            ['title' => 'Username', 'width' => 0],
            ['title' => 'Email', 'width' => 30],
            ['title' => 'Role', 'width' => 8],
            ['title' => 'Status', 'width' => 10],
            ['title' => 'Last login', 'width' => 20],
        ], $rows, $this->selected, $this->cols - 4, $this->viewportRows());

        return "\n" . $this->headerLine() . "\n" . $table . "\n\n" . $this->statusLine();
    }

    /** The header: the active filter and the row count. */
    private function headerLine(): string
    {
        $filter = self::FILTER_LABELS[$this->filter];

        return "  Filter: {$filter}   ({$this->countLabel()})";
    }

    private function countLabel(): string
    {
        $count = count($this->users);

        return $count === 1 ? '1 user' : "{$count} users";
    }

    /**
     * The status line under the table: the armed confirm prompt when one is
     * pending, else a revealed password (after a reset), else a hint.
     */
    private function statusLine(): string
    {
        $pending = $this->pending;
        if ($pending !== null) {
            return '  ' . $pending->prompt();
        }
        if ($this->note !== null) {
            return '  ' . $this->note;
        }
        if ($this->busy) {
            return '  Working…';
        }

        return '  f cycles the status filter · select a user and press an action key.';
    }

    private function viewportRows(): int
    {
        // The frame body holds the header line, then the table (header + rule = 2
        // extra rows), then a blank line + the status line. Window the data rows to
        // the body height less those chrome rows so the selected row is never clipped.
        return max(1, Chrome::bodyHeight($this->rows) - 5);
    }

    private function selectedUser(): ?AdminUser
    {
        return $this->users[$this->selected] ?? null;
    }

    // ---- breadcrumb ----------------------------------------------------

    public function crumbLabel(): string
    {
        return 'Users';
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

    /** @return list<AdminUser> */
    public function userList(): array
    {
        return $this->users;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function selectedIndex(): int
    {
        return $this->selected;
    }

    public function filterLabel(): string
    {
        return self::FILTER_LABELS[$this->filter];
    }

    public function isBusy(): bool
    {
        return $this->busy;
    }

    public function pendingAction(): ?PendingAction
    {
        return $this->pending;
    }

    public function note(): ?string
    {
        return $this->note;
    }
}
