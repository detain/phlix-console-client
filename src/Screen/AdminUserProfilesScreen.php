<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Admin\Profile;
use Phlix\Console\Msg\AdminProfileActionDoneMsg;
use Phlix\Console\Msg\AdminProfileActionFailedMsg;
use Phlix\Console\Msg\AdminProfilesFailedMsg;
use Phlix\Console\Msg\AdminProfilesLoadedMsg;
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
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Form;

/**
 * The admin viewer-profiles surface for ONE user, pushed from the AdminUsersScreen
 * (`P` on a selected user): a windowed {@see Table} of that user's profiles
 * (Name · Rating · Active ✓/– · PIN-for-admin ✓/–), driven by per-row actions.
 *
 * `c` opens an embedded candy-forms create form (a name {@see Input} + a rating
 * {@see Select} of the seven content-rating ENUM labels, defaulting to `R`); on
 * submit the name is client-validated (1–50) and the SELECTED rating index is sent
 * as `rating`. `E` opens a pre-filled edit form (the current name + the rating
 * pre-selected from {@see Profile::ratingIndex()}); only CHANGED fields are sent.
 * `x` arms an inline (y/n) delete confirm. `p` opens a PIN input — the value is
 * client-validated to 4 OR 6 digits (a 5-digit / non-digit value re-opens with an
 * error and issues NO request) then POSTed. `k` arms an inline (y/n) clear-PIN
 * confirm (DELETE). `r` refreshes; ↑/↓ select; Esc/q → NavigateBack.
 *
 * Every form intercepts candy-forms' own quit (abort → cancel / submit → validate
 * then act) so it never exits the app. On success the server message is toasted
 * and the list refetched; on failure the server `error` is toasted and the list is
 * left unchanged; an auth failure surfaces a session expiry.
 *
 * The client is injected (built locally by the App from its shared ApiClient, so
 * the App holds no AdminClient field). Stable collaborators are readonly; the
 * loaded data, selection, busy flag, the pending confirm, and the embedded forms
 * are private mutable view state set via clone-mutate (the established idiom).
 */
final class AdminUserProfilesScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LOAD_FAILED = 'Could not load the profiles.';
    private const HINT = 'c create  E edit  x delete  p set-PIN  k clear-PIN  r refresh  Esc back';
    private const FORM_HINT = 'Tab/↑↓  field      Enter  save      Esc  cancel';
    private const PIN_HINT = 'Enter  save      Esc  cancel';

    private const MAX_NAME = 50;

    /** Confirmable actions that arm an inline (y/n) prompt before firing. */
    private const ACTION_DELETE = 'delete';
    private const ACTION_CLEAR_PIN = 'clear-pin';

    /** @var list<Profile> */
    private array $profiles = [];
    private bool $loaded = false;
    private ?string $error = null;

    private int $selected = 0;

    /** A fetch / action is in flight (mutating input is ignored while busy). */
    private bool $busy = false;

    /** An armed confirmation (delete / clear-PIN), or null when none is pending. */
    private ?string $pendingAction = null;
    private ?Profile $pendingProfile = null;

    /** The embedded create / edit form while open, else null. */
    private ?Form $form = null;

    /** The profile being edited (null while creating or with no form open). */
    private ?Profile $editing = null;

    /** The embedded set-PIN input while open, else null. */
    private ?Form $pinForm = null;

    /** The profile whose PIN is being set (null with no PIN form open). */
    private ?Profile $pinProfile = null;

    /** A client-side validation note shown above an open form, or null. */
    private ?string $formError = null;

    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private readonly AdminClient $admin,
        private readonly string $userId,
        private readonly string $userLabel,
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
        return Cmd::promise(fn () => $this->admin->userProfiles($this->userId)->then(
            /** @param list<Profile> $profiles */
            static fn (array $profiles): Msg => new AdminProfilesLoadedMsg($profiles),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminProfilesFailedMsg(self::LOAD_FAILED),
        ));
    }

    /**
     * Map a profile-action promise to the shared done / failed flow (toast +
     * refetch on success; the server `error` toasted on failure; an auth failure
     * to a session expiry).
     *
     * @param PromiseInterface<string> $promise
     */
    private function actionCmd(PromiseInterface $promise, string $fallback): \Closure
    {
        return Cmd::promise(static fn () => $promise->then(
            static fn (string $message): Msg => new AdminProfileActionDoneMsg($message === '' ? $fallback : $message),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminProfileActionFailedMsg($e->getMessage()),
        ));
    }

    // ---- update --------------------------------------------------------

    /** @return array{self, ?\Closure} */
    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [$this->resizedTo($msg->cols, $msg->rows), null];
        }
        // An open embedded form / PIN input captures every other message.
        if ($this->form !== null) {
            return $this->updateForm($msg, $this->form);
        }
        if ($this->pinForm !== null && $this->pinProfile !== null) {
            return $this->updatePin($msg, $this->pinForm, $this->pinProfile);
        }
        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }
        if ($msg instanceof AdminProfilesLoadedMsg) {
            return [$this->withProfiles($msg->profiles), null];
        }
        if ($msg instanceof AdminProfilesFailedMsg) {
            return [$this->withError($msg->message), null];
        }
        if ($msg instanceof AdminProfileActionDoneMsg) {
            return $this->onActionDone($msg);
        }
        if ($msg instanceof AdminProfileActionFailedMsg) {
            return [$this->idle(), Cmd::send(ShowToastMsg::error($msg->message))];
        }

        return [$this, null];
    }

    public function view(): string
    {
        if ($this->form !== null) {
            $title = $this->editing !== null ? 'Admin · Profiles · Edit' : 'Admin · Profiles · Create';

            return Chrome::frame($title, $this->formBody($this->form), self::FORM_HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
        }
        if ($this->pinForm !== null && $this->pinProfile !== null) {
            return Chrome::frame('Admin · Profiles · PIN', $this->pinBody($this->pinForm, $this->pinProfile), self::PIN_HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
        }

        return Chrome::frame('Admin · Profiles', $this->body(), self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
    }

    // ---- input ---------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        // An armed confirm captures y/n/Esc before anything else.
        if ($this->pendingAction !== null && $this->pendingProfile !== null) {
            return $this->handleConfirmKey($msg, $this->pendingAction, $this->pendingProfile);
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

        // The remaining keys mutate; they need an idle screen.
        if ($this->busy) {
            return [$this, null];
        }
        // Create needs no selected profile; open it before the selection guard.
        if ($rune === 'c') {
            return [$this->openCreate(), null];
        }

        $profile = $this->selectedProfile();
        if ($profile === null) {
            return [$this, null];
        }

        return match ($rune) {
            'E' => [$this->openEdit($profile), null],
            'p' => [$this->openPin($profile), null],
            'x' => [$this->arm(self::ACTION_DELETE, $profile), null],
            'k' => [$this->arm(self::ACTION_CLEAR_PIN, $profile), null],
            default => [$this, null],
        };
    }

    /** @return array{self, ?\Closure} */
    private function handleConfirmKey(KeyMsg $msg, string $action, Profile $profile): array
    {
        if ($msg->type === KeyType::Char && $msg->rune === 'y') {
            return $action === self::ACTION_DELETE
                ? [$this->working(), $this->actionCmd($this->admin->deleteProfile($profile->id), 'Profile deleted')]
                : [$this->working(), $this->actionCmd($this->admin->clearProfilePin($profile->id), 'PIN cleared')];
        }
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'n')) {
            return [$this->cancelPending(), null];
        }

        return [$this, null];
    }

    // ---- create / edit form (embedded candy-forms) ---------------------

    /**
     * Drive the embedded create / edit form. candy-forms' Form returns Cmd::quit()
     * on submit/abort; we intercept that — an abort closes the form, a submit
     * client-validates (keeping the form open with an error note on a failure, so a
     * guaranteed-400 never round-trips) then fires the create / edit request.
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
     * Validate + fire a create. An invalid name keeps the form open with an error
     * note and issues NO request; a valid name posts (with the selected rating
     * index) and (on the shared done/failed flow) toasts + refetches.
     *
     * @return array{self, ?\Closure}
     */
    private function submitCreate(Form $form): array
    {
        $name = trim($form->getString('name'));
        $rating = self::ratingIndexOf($form->getString('rating'));

        $error = self::validateName($name);
        if ($error !== null) {
            return [$this->reopenCreate($name, $rating, $error), null];
        }

        return [$this->closeForm()->working(), $this->actionCmd(
            $this->admin->createProfile($this->userId, $name, $rating),
            'Profile created',
        )];
    }

    /**
     * Validate + fire an edit. Only CHANGED fields are sent: an unchanged name is
     * omitted (null), and an unchanged rating index is omitted (null). A changed
     * name that fails validation keeps the form open with an error note and issues
     * NO request.
     *
     * @return array{self, ?\Closure}
     */
    private function submitEdit(Form $form, Profile $profile): array
    {
        $name = trim($form->getString('name'));
        $rating = self::ratingIndexOf($form->getString('rating'));

        $newName = $name === $profile->name ? null : $name;
        $newRating = $rating === $profile->ratingIndex() ? null : $rating;

        $error = $newName !== null ? self::validateName($newName) : null;
        if ($error !== null) {
            return [$this->reopenEdit($profile, $name, $rating, $error), null];
        }

        return [$this->closeForm()->working(), $this->actionCmd(
            $this->admin->updateProfile($profile->id, $newName, $newRating),
            'Profile updated',
        )];
    }

    /** The create form: name {@see Input} + rating {@see Select} (default `R`). */
    private static function buildCreateForm(): Form
    {
        return Form::new(
            Input::new('name')
                ->withTitle('Name')
                ->withPlaceholder('Kids'),
            self::ratingSelect(Profile::DEFAULT_RATING_INDEX),
        );
    }

    /**
     * The edit form: name + rating, pre-filled from the profile (the rating
     * pre-selected from its 0-6 index).
     */
    private static function buildEditForm(Profile $profile): Form
    {
        return Form::new(
            Input::new('name')
                ->withTitle('Name')
                ->withValue($profile->name),
            self::ratingSelect($profile->ratingIndex()),
        );
    }

    /** A rating Select of the seven ENUM labels, pre-selected by index. */
    private static function ratingSelect(int $index): Select
    {
        return Select::new('rating')
            ->withTitle('Content rating')
            ->withOptions(...Profile::RATINGS)
            ->withSelectedIndex($index);
    }

    /**
     * Map a selected rating LABEL back to its 0-6 index; an unknown label falls
     * back to the default (`R`).
     */
    private static function ratingIndexOf(string $label): int
    {
        $index = array_search($label, Profile::RATINGS, true);

        return $index === false ? Profile::DEFAULT_RATING_INDEX : $index;
    }

    /** Validate a profile name (1–50 chars after trim); null when valid. */
    private static function validateName(string $name): ?string
    {
        $length = mb_strlen($name);
        if ($length < 1 || $length > self::MAX_NAME) {
            return 'Name must be 1–' . self::MAX_NAME . ' characters.';
        }

        return null;
    }

    // ---- set-PIN input (embedded candy-forms) --------------------------

    /**
     * Drive the embedded PIN input. An abort cancels; a submit client-validates the
     * PIN (4 OR 6 digits) — an invalid value re-opens the input with an error toast
     * and issues NO request — then POSTs the PIN.
     *
     * @return array{self, ?\Closure}
     */
    private function updatePin(Msg $msg, Form $form, Profile $profile): array
    {
        /** @var array{0: Form, 1: ?\Closure} $result candy-forms' Form::update returns Model's loose `:array`; narrow it. */
        $result = $form->update($msg);
        [$next, $cmd] = $result;

        if ($next->isAborted()) {
            return [$this->closePin(), null];
        }
        if ($next->isSubmitted()) {
            return $this->submitPin($next->getString('pin'), $profile);
        }

        return [$this->withPinForm($next, $profile), $cmd];
    }

    /**
     * Validate + fire a set-PIN. An invalid PIN (not 4 or 6 digits) re-opens the
     * input with an error toast and issues no request; a valid PIN POSTs.
     *
     * @return array{self, ?\Closure}
     */
    private function submitPin(string $pin, Profile $profile): array
    {
        if (!self::isValidPin($pin)) {
            $fresh = self::buildPinForm();

            return [$this->withPinForm($fresh, $profile), Cmd::batch(
                Cmd::send(ShowToastMsg::error('PIN must be 4 or 6 digits.')),
                $fresh->init(),
            )];
        }

        return [$this->closePin()->working(), $this->actionCmd(
            $this->admin->setProfilePin($profile->id, $pin),
            'PIN updated',
        )];
    }

    private static function buildPinForm(): Form
    {
        return Form::new(
            Input::new('pin')
                ->withTitle('PIN (4 or 6 digits)')
                ->withPassword(),
        );
    }

    /** A PIN is valid when it is exactly 4 OR 6 ASCII digits. */
    private static function isValidPin(string $pin): bool
    {
        return preg_match('/^(\d{4}|\d{6})$/', $pin) === 1;
    }

    // ---- post-action ---------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function onActionDone(AdminProfileActionDoneMsg $msg): array
    {
        $toast = ShowToastMsg::success($msg->message === '' ? 'Done.' : $msg->message);

        return [$this->working(), Cmd::batch(Cmd::send($toast), $this->fetchCmd())];
    }

    /** @return array{self, ?\Closure} */
    private function refresh(): array
    {
        $next = clone $this;
        $next->loaded = false;
        $next->error = null;
        $next->pendingAction = null;
        $next->pendingProfile = null;

        return [$next, $next->fetchCmd()];
    }

    // ---- clone-mutate copies -------------------------------------------

    /** @param list<Profile> $profiles */
    private function withProfiles(array $profiles): self
    {
        $next = clone $this;
        $next->profiles = $profiles;
        $next->loaded = true;
        $next->busy = false;
        $next->error = null;
        $next->pendingAction = null;
        $next->pendingProfile = null;
        $next->selected = $profiles === [] ? 0 : min($this->selected, count($profiles) - 1);

        return $next;
    }

    private function withError(string $error): self
    {
        $next = clone $this;
        $next->error = $error;
        $next->loaded = false;
        $next->busy = false;
        $next->pendingAction = null;
        $next->pendingProfile = null;

        return $next;
    }

    /** Enter the busy (in-flight) state, clearing any armed confirm. */
    private function working(): self
    {
        $next = clone $this;
        $next->busy = true;
        $next->pendingAction = null;
        $next->pendingProfile = null;

        return $next;
    }

    /** Leave the busy state (after a failed action) without touching the list. */
    private function idle(): self
    {
        $next = clone $this;
        $next->busy = false;
        $next->pendingAction = null;
        $next->pendingProfile = null;

        return $next;
    }

    private function arm(string $action, Profile $profile): self
    {
        $next = clone $this;
        $next->pendingAction = $action;
        $next->pendingProfile = $profile;

        return $next;
    }

    private function cancelPending(): self
    {
        $next = clone $this;
        $next->pendingAction = null;
        $next->pendingProfile = null;

        return $next;
    }

    // ---- form open / close / reopen ------------------------------------

    private function openCreate(): self
    {
        return $this->withForm(self::buildCreateForm(), null);
    }

    private function openEdit(Profile $profile): self
    {
        return $this->withForm(self::buildEditForm($profile), $profile);
    }

    private function openPin(Profile $profile): self
    {
        return $this->withPinForm(self::buildPinForm(), $profile);
    }

    /** Reopen a fresh create form pre-filled with the entered values + an error. */
    private function reopenCreate(string $name, int $rating, string $error): self
    {
        $form = Form::new(
            Input::new('name')->withTitle('Name')->withValue($name),
            self::ratingSelect($rating),
        );
        $next = $this->withForm($form, null);
        $next->formError = $error;

        return $next;
    }

    /** Reopen a fresh edit form pre-filled with the entered values + an error. */
    private function reopenEdit(Profile $profile, string $name, int $rating, string $error): self
    {
        $form = Form::new(
            Input::new('name')->withTitle('Name')->withValue($name),
            self::ratingSelect($rating),
        );
        $next = $this->withForm($form, $profile);
        $next->formError = $error;

        return $next;
    }

    private function closeForm(): self
    {
        return $this->withForm(null, null);
    }

    private function withForm(?Form $form, ?Profile $editing): self
    {
        $next = clone $this;
        $next->form = $form;
        $next->editing = $form === null ? null : $editing;
        $next->formError = null;
        $next->pendingAction = null;
        $next->pendingProfile = null;
        // Opening a create/edit form supersedes any PIN input.
        $next->pinForm = null;
        $next->pinProfile = null;

        return $next;
    }

    private function closePin(): self
    {
        $next = clone $this;
        $next->pinForm = null;
        $next->pinProfile = null;

        return $next;
    }

    private function withPinForm(Form $form, Profile $profile): self
    {
        $next = clone $this;
        $next->pinForm = $form;
        $next->pinProfile = $profile;
        $next->pendingAction = null;
        $next->pendingProfile = null;

        return $next;
    }

    private function moveSelection(int $delta): self
    {
        $count = count($this->profiles);
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
            return "\n  Loading profiles…";
        }
        if ($this->error !== null) {
            return "\n  {$this->error}\n\n  Press r to retry.";
        }
        if ($this->profiles === []) {
            return "\n" . $this->headerLine() . "\n\n  No profiles yet.\n\n" . $this->statusLine();
        }

        $rows = [];
        foreach ($this->profiles as $profile) {
            $rows[] = [
                $profile->name === '' ? '—' : $profile->name,
                $profile->contentRating === '' ? '—' : $profile->contentRating,
                $profile->isActive ? '✓' : '–',
                $profile->pinRequiredForAdmin ? '✓' : '–',
            ];
        }

        $table = Table::render([
            ['title' => 'Name', 'width' => 0],
            ['title' => 'Rating', 'width' => 10],
            ['title' => 'Active', 'width' => 8],
            ['title' => 'PIN-admin', 'width' => 10],
        ], $rows, $this->selected, $this->cols - 4, $this->viewportRows());

        return "\n" . $this->headerLine() . "\n" . $table . "\n\n" . $this->statusLine();
    }

    /** The header: the owning user and the profile count. */
    private function headerLine(): string
    {
        return "  User: {$this->userLabel}   ({$this->countLabel()})";
    }

    private function countLabel(): string
    {
        $count = count($this->profiles);

        return $count === 1 ? '1 profile' : "{$count} profiles";
    }

    /**
     * The status line under the table: the armed confirm prompt when one is
     * pending, else a busy note, else a hint.
     */
    private function statusLine(): string
    {
        if ($this->pendingAction !== null && $this->pendingProfile !== null) {
            return '  ' . $this->confirmPrompt($this->pendingAction, $this->pendingProfile);
        }
        if ($this->busy) {
            return '  Working…';
        }

        return '  select a profile and press an action key.';
    }

    private function confirmPrompt(string $action, Profile $profile): string
    {
        $name = $profile->name === '' ? 'this profile' : "'{$profile->name}'";

        return $action === self::ACTION_DELETE
            ? "Delete {$name}? (y/n)"
            : "Clear the PIN for {$name}? (y/n)";
    }

    private function formBody(Form $form): string
    {
        $intro = $this->editing !== null
            ? "Edit '{$this->editing->name}'."
            : "Create a profile for {$this->userLabel}.";
        $lines = [$intro];
        if ($this->formError !== null) {
            $lines[] = '! ' . $this->formError;
        }
        $lines[] = '';

        return implode("\n", $lines) . $form->view();
    }

    private function pinBody(Form $form, Profile $profile): string
    {
        $name = $profile->name === '' ? 'this profile' : "'{$profile->name}'";
        $lines = [
            "Set the admin PIN for {$name}.",
            'Enter 4 or 6 digits. Use k on the list to clear it instead.',
            '',
        ];

        return implode("\n", $lines) . $form->view();
    }

    private function viewportRows(): int
    {
        // The frame body holds the header line, then the table (header + rule = 2
        // extra rows), then a blank line + the status line. Window the data rows to
        // the body height less those chrome rows so the selected row is never clipped.
        return max(1, Chrome::bodyHeight($this->rows) - 5);
    }

    private function selectedProfile(): ?Profile
    {
        return $this->profiles[$this->selected] ?? null;
    }

    // ---- breadcrumb ----------------------------------------------------

    public function crumbLabel(): string
    {
        return 'Profiles';
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

    /** @return list<Profile> */
    public function profileList(): array
    {
        return $this->profiles;
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

    public function pendingActionLabel(): ?string
    {
        return $this->pendingAction;
    }

    public function isCreating(): bool
    {
        return $this->form !== null && $this->editing === null;
    }

    public function isEditing(): bool
    {
        return $this->form !== null && $this->editing !== null;
    }

    public function isSettingPin(): bool
    {
        return $this->pinForm !== null;
    }

    public function formError(): ?string
    {
        return $this->formError;
    }
}
