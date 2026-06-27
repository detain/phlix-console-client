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
use Phlix\Console\Msg\OpenAdminUserProfilesMsg;
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
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Form;

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
 * toast plus the status line). `c` opens an embedded candy-forms create form
 * (username · email · password · is-admin); `E` opens a pre-filled edit form for
 * the selected user (a blank password leaves it unchanged, and only changed
 * fields are sent). Both forms client-validate before any request (so a
 * guaranteed-400 never round-trips) and intercept candy-forms' own quit so they
 * never exit the app. On success the server message is toasted and the list
 * refetched; on failure the server `error` is toasted and the list is left
 * unchanged; an auth failure surfaces a session expiry.
 *
 * The client is injected (built locally by the App from its shared ApiClient, so
 * the App holds no AdminClient field). Stable collaborators are readonly; the
 * loaded data, selection, filter, busy flag, the pending confirm, the status
 * note, and the embedded create / edit form are private mutable view state set
 * via clone-mutate (the established screen idiom).
 *
 * `P` on the selected user opens that user's viewer-profiles management (the
 * AdminUserProfilesScreen, pushed at App level via OpenAdminUserProfilesMsg).
 *
 * SCOPE: list + filter + per-row actions + create / edit forms + the profiles
 * jump-off. Parental-controls beyond profiles are deferred to a later PR.
 */
final class AdminUsersScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LOAD_FAILED = 'Could not load the users.';
    private const HINT = 'c create  E edit  P profiles  f filter  a approve  d disable  x delete  j reject  m admin  p reset-pw  r refresh  Esc back';
    private const FORM_HINT = 'Tab/↑↓  field      Enter  save      Esc  cancel';

    /** The username rule mirrors the server: 3–50 of [A-Za-z0-9_]. */
    private const USERNAME_PATTERN = '/^[A-Za-z0-9_]{3,50}$/';
    private const MIN_PASSWORD = 8;

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

    /** The embedded create / edit form while open, else null. */
    private ?Form $form = null;

    /** The user being edited (null while creating or with no form open). */
    private ?AdminUser $editing = null;

    /** A client-side validation note shown above an open form, or null. */
    private ?string $formError = null;

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

    // ---- create / edit form (embedded candy-forms) ---------------------

    /**
     * Drive the embedded create / edit form. candy-forms' Form returns
     * Cmd::quit() on submit/abort; we intercept that — an abort closes the form,
     * a submit client-validates (keeping the form open with an error note on a
     * failure, so a guaranteed-400 never round-trips) then fires the create / edit
     * request.
     *
     * @return array{self, ?\Closure}
     */
    private function updateForm(Msg $msg, Form $form): array
    {
        /** @var array{0: Form, 1: ?\Closure} $result candy-forms' Form::update returns Model's loose `:array`; narrow it. */
        $result = $form->update($msg);
        [$next, $cmd] = $result;

        if ($next->isAborted()) {
            return [$this->closeForm(), null];
        }
        if ($next->isSubmitted()) {
            return $this->editing !== null
                ? $this->submitEdit($next, $this->editing)
                : $this->submitCreate($next);
        }

        return [$this->withForm($next, $this->editing), $cmd];
    }

    /**
     * Validate + fire a create. An invalid field keeps the form open with an
     * error note and issues NO request; a valid set posts and (on the shared
     * done/failed flow) toasts + refetches.
     *
     * @return array{self, ?\Closure}
     */
    private function submitCreate(Form $form): array
    {
        $username = trim($form->getString('username'));
        $email = trim($form->getString('email'));
        $password = $form->getString('password');
        $isAdmin = $form->getString('is_admin') === 'Yes';

        $error = self::validateUsername($username)
            ?? self::validateEmail($email)
            ?? self::validatePassword($password);
        if ($error !== null) {
            return [$this->reopenCreate($username, $email, $isAdmin, $error), null];
        }

        return [$this->closeForm()->working(), $this->mutationCmd(
            $this->admin->createUser($username, $email, $password, $isAdmin),
            'User created',
        )];
    }

    /**
     * Validate + fire an edit. Only CHANGED fields are sent: an unchanged
     * username / email is omitted (null), and a blank password is omitted
     * (= leave unchanged). A changed field that fails validation keeps the form
     * open with an error note and issues NO request.
     *
     * @return array{self, ?\Closure}
     */
    private function submitEdit(Form $form, AdminUser $user): array
    {
        $username = trim($form->getString('username'));
        $email = trim($form->getString('email'));
        $password = $form->getString('password');

        $newUsername = $username === $user->username ? null : $username;
        $newEmail = $email === $user->email ? null : $email;
        $newPassword = $password === '' ? null : $password;

        $error = ($newUsername !== null ? self::validateUsername($newUsername) : null)
            ?? ($newEmail !== null ? self::validateEmail($newEmail) : null)
            ?? ($newPassword !== null ? self::validatePassword($newPassword) : null);
        if ($error !== null) {
            return [$this->reopenEdit($user, $username, $email, $error), null];
        }

        return [$this->closeForm()->working(), $this->mutationCmd(
            $this->admin->updateUser($user->id, $newUsername, $newEmail, $newPassword),
            'User updated',
        )];
    }

    /**
     * Map a create / edit promise to the shared done / failed flow (toast +
     * refetch on success; the server `error` toasted on failure; an auth failure
     * to a session expiry).
     *
     * @param PromiseInterface<string> $promise
     */
    private function mutationCmd(PromiseInterface $promise, string $fallback): \Closure
    {
        return Cmd::promise(static fn () => $promise->then(
            static fn (string $message): Msg => new AdminUserActionDoneMsg($message === '' ? $fallback : $message),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminUserActionFailedMsg($e->getMessage()),
        ));
    }

    /**
     * The create form: username · email · password · is-admin. The is-admin
     * Select is create-only (the update endpoint does NOT change it — admin is
     * toggled via `m` on the list).
     */
    private static function buildCreateForm(): Form
    {
        return Form::new(
            Input::new('username')
                ->withTitle('Username')
                ->withPlaceholder('alice_99'),
            Input::new('email')
                ->withTitle('Email')
                ->withPlaceholder('alice@example.com'),
            Input::new('password')
                ->withTitle('Password')
                ->withPassword(),
            Select::new('is_admin')
                ->withTitle('Administrator')
                ->withOptions('No', 'Yes'),
        );
    }

    /**
     * The edit form: username · email · password, pre-filled from the user (a
     * blank password leaves it unchanged). No is-admin field — the update
     * endpoint does not touch the admin flag.
     */
    private static function buildEditForm(AdminUser $user): Form
    {
        return Form::new(
            Input::new('username')
                ->withTitle('Username')
                ->withValue($user->username),
            Input::new('email')
                ->withTitle('Email')
                ->withValue($user->email),
            Input::new('password')
                ->withTitle('New password (blank = unchanged)')
                ->withPassword(),
        );
    }

    /** Validate a username (3–50 of [A-Za-z0-9_]); null when valid. */
    private static function validateUsername(string $username): ?string
    {
        return preg_match(self::USERNAME_PATTERN, $username) === 1
            ? null
            : 'Username must be 3–50 letters, digits, or underscores.';
    }

    /** Validate an email (basic format); null when valid. */
    private static function validateEmail(string $email): ?string
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            ? null
            : 'Enter a valid email address.';
    }

    /** Validate a password (≥ 8 chars); null when valid. */
    private static function validatePassword(string $password): ?string
    {
        return strlen($password) >= self::MIN_PASSWORD
            ? null
            : 'Password must be at least ' . self::MIN_PASSWORD . ' characters.';
    }

    // ---- update --------------------------------------------------------

    /** @return array{self, ?\Closure} */
    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [$this->resizedTo($msg->cols, $msg->rows), null];
        }
        // An open embedded form captures every other message.
        if ($this->form !== null) {
            return $this->updateForm($msg, $this->form);
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
        if ($this->form !== null) {
            $title = $this->editing !== null ? 'Admin · Users · Edit' : 'Admin · Users · Create';

            return Chrome::frame($title, $this->formBody($this->form), self::FORM_HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
        }

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

        // The remaining keys mutate; they need an idle screen.
        if ($this->busy) {
            return [$this, null];
        }
        // Create needs no selected user; open it before the selection guard.
        if ($rune === 'c') {
            return [$this->openCreate(), null];
        }

        $user = $this->selectedUser();
        if ($user === null) {
            return [$this, null];
        }

        return match ($rune) {
            'E' => [$this->openEdit($user), null],
            'P' => [$this, Cmd::send(new OpenAdminUserProfilesMsg($user->id, $user->label()))],
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

    // ---- form open / close / reopen ------------------------------------

    private function openCreate(): self
    {
        return $this->withForm(self::buildCreateForm(), null);
    }

    private function openEdit(AdminUser $user): self
    {
        return $this->withForm(self::buildEditForm($user), $user);
    }

    /** Reopen a fresh create form pre-filled with the entered values + an error. */
    private function reopenCreate(string $username, string $email, bool $isAdmin, string $error): self
    {
        $form = Form::new(
            Input::new('username')->withTitle('Username')->withValue($username),
            Input::new('email')->withTitle('Email')->withValue($email),
            Input::new('password')->withTitle('Password')->withPassword(),
            Select::new('is_admin')->withTitle('Administrator')->withOptions('No', 'Yes')->withSelected($isAdmin ? 'Yes' : 'No'),
        );
        $next = $this->withForm($form, null);
        $next->formError = $error;

        return $next;
    }

    /** Reopen a fresh edit form pre-filled with the entered values + an error. */
    private function reopenEdit(AdminUser $user, string $username, string $email, string $error): self
    {
        $form = Form::new(
            Input::new('username')->withTitle('Username')->withValue($username),
            Input::new('email')->withTitle('Email')->withValue($email),
            Input::new('password')->withTitle('New password (blank = unchanged)')->withPassword(),
        );
        $next = $this->withForm($form, $user);
        $next->formError = $error;

        return $next;
    }

    private function closeForm(): self
    {
        return $this->withForm(null, null);
    }

    private function withForm(?Form $form, ?AdminUser $editing): self
    {
        $next = clone $this;
        $next->form = $form;
        $next->editing = $form === null ? null : $editing;
        $next->formError = null;
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

    private function formBody(Form $form): string
    {
        $intro = $this->editing !== null
            ? "Edit '{$this->editing->label()}'. A blank password is left unchanged."
            : 'Create a user. All fields are required.';
        $lines = [$intro];
        if ($this->formError !== null) {
            $lines[] = '! ' . $this->formError;
        }
        $lines[] = '';

        return implode("\n", $lines) . $form->view();
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

    public function isCreating(): bool
    {
        return $this->form !== null && $this->editing === null;
    }

    public function isEditing(): bool
    {
        return $this->form !== null && $this->editing !== null;
    }

    public function formError(): ?string
    {
        return $this->formError;
    }
}
