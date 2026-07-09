<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Admin\ServerSetting;
use Phlix\Console\Api\Dto\Admin\ServerSettings;
use Phlix\Console\Msg\AdminSettingActionDoneMsg;
use Phlix\Console\Msg\AdminSettingActionFailedMsg;
use Phlix\Console\Msg\AdminSettingsFailedMsg;
use Phlix\Console\Msg\AdminSettingsLoadedMsg;
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
 * The admin Server-Settings surface: a windowed {@see Table} of every effective
 * setting (Key · Value · Type · Overridden) driven by per-key edits.
 *
 * The server controller validates the INTERNAL TYPE only (not enums), and the
 * client has no schema access, so the surface is fully driven by the GET
 * response's `types` map. Editing is type-based:
 *   - a **bool** key (`e`/Enter) toggles immediately and PUTs the flipped real
 *     bool — no form;
 *   - a **non-bool** key (int/float/string/json) opens an embedded candy-forms
 *     {@see Input} pre-filled with the current display value; the candy-forms
 *     quit-intercept (abort → cancel / submit → coerce + PUT) keeps the embedded
 *     `Cmd::quit()` from exiting the app (the Plugins/Backup pattern). The input
 *     is coerced by type BEFORE sending: int rejects a non-`^-?\d+$` value, float
 *     a non-numeric one, json must decode to an array — an invalid value re-opens
 *     the input with an error toast and issues NO request; a string passes
 *     through verbatim.
 *
 * After a successful PUT the screen REFETCHES via GET (the PUT response carries
 * no `types`); on failure the server `error` is toasted and the list left
 * unchanged; an auth failure surfaces a session expiry.
 *
 * The client is injected (built locally by the App from its shared ApiClient, so
 * the App holds no AdminClient field). Stable collaborators are readonly; the
 * loaded data, selection, busy flag, and the embedded edit form (plus the key it
 * edits) are private mutable view state set via clone-mutate (the established
 * screen idiom).
 */
final class AdminSettingsScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LOAD_FAILED = 'Could not load the settings.';
    private const HINT = '↑↓ select   e/⏎ edit   r refresh   Esc back';
    private const EDIT_HINT = 'Enter  save      Esc  cancel';

    /** @var list<ServerSetting> */
    private array $settings = [];
    private bool $loaded = false;
    private ?string $error = null;

    private int $selected = 0;

    /** A fetch / action is in flight (input that mutates is ignored while busy). */
    private bool $busy = false;

    /** The embedded edit form while the value input is open, else null. */
    private ?Form $editForm = null;

    /** The key being edited (so the submit knows which setting to coerce), else null. */
    private ?ServerSetting $editing = null;

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
        return Cmd::promise(fn () => $this->admin->serverSettings()->then(
            static fn (ServerSettings $settings): Msg => new AdminSettingsLoadedMsg($settings),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminSettingsFailedMsg(self::LOAD_FAILED),
        ));
    }

    /**
     * Build the command for a fired PUT: the update promise mapped to a
     * done/failed Msg (the server `message` on success, the server `error` on
     * failure, a session expiry on auth).
     *
     * @param PromiseInterface<string> $promise
     */
    private function actionCmd(PromiseInterface $promise): \Closure
    {
        return Cmd::promise(static fn () => $promise->then(
            static fn (string $message): Msg => new AdminSettingActionDoneMsg($message === '' ? 'Settings updated.' : $message),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminSettingActionFailedMsg($e->getMessage()),
        ));
    }

    // ---- update --------------------------------------------------------

    /** @return array{self, ?\Closure} */
    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [$this->resizedTo($msg->cols, $msg->rows), null];
        }
        // While the edit input is open it captures all keys.
        if ($this->editForm !== null && $this->editing !== null) {
            return $this->updateEdit($msg, $this->editForm, $this->editing);
        }
        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }
        if ($msg instanceof AdminSettingsLoadedMsg) {
            return [$this->withSettings($msg->settings->settings), null];
        }
        if ($msg instanceof AdminSettingsFailedMsg) {
            return [$this->withError($msg->message), null];
        }
        if ($msg instanceof AdminSettingActionDoneMsg) {
            return $this->onActionDone($msg);
        }
        if ($msg instanceof AdminSettingActionFailedMsg) {
            return [$this->idle(), Cmd::send(ShowToastMsg::error($msg->message))];
        }

        return [$this, null];
    }

    public function view(): string
    {
        if ($this->editForm !== null && $this->editing !== null) {
            return Chrome::frame('Admin · Settings · Edit', $this->editBody($this->editForm, $this->editing), self::EDIT_HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
        }

        return Chrome::frame('Admin · Settings', $this->body(), self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
    }

    // ---- input ---------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Up) {
            return [$this->moveSelection(-1), null];
        }
        if ($msg->type === KeyType::Down) {
            return [$this->moveSelection(1), null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'r') {
            return $this->refresh();
        }
        if ($this->busy) {
            return [$this, null];
        }
        if ($msg->type === KeyType::Enter || ($msg->type === KeyType::Char && $msg->rune === 'e')) {
            return $this->beginEdit();
        }

        return [$this, null];
    }

    /**
     * Begin editing the selected setting: a bool key toggles + PUTs immediately;
     * any other key opens the pre-filled value input.
     *
     * @return array{self, ?\Closure}
     */
    private function beginEdit(): array
    {
        $setting = $this->selectedSetting();
        if ($setting === null) {
            return [$this, null];
        }
        if ($setting->isBool()) {
            return [$this->working(), $this->actionCmd($this->admin->updateServerSetting($setting->key, !$setting->boolValue()))];
        }

        return [$this->openEdit($setting), null];
    }

    // ---- value-edit input (embedded candy-forms) -----------------------

    /**
     * Drive the embedded value form. candy-forms' Form returns Cmd::quit() on
     * submit/abort; we intercept that — an abort cancels, a submit coerces the
     * raw input by the setting's type (rejecting an invalid value with an error
     * toast and re-opening, issuing no request) and PUTs the coerced value.
     *
     * @return array{self, ?\Closure}
     */
    private function updateEdit(Msg $msg, Form $form, ServerSetting $setting): array
    {
        /** @var array{0: Form, 1: ?\Closure} $result candy-forms' Form::update returns Model's loose `:array`; narrow it. */
        $result = $form->update($msg);
        [$next, $cmd] = $result;

        if ($next->isAborted()) {
            return [$this->closeEdit(), null];
        }

        if ($next->isSubmitted()) {
            return $this->submitEdit($next->getString('value'), $setting);
        }

        return [$this->withEditForm($next, $setting), $cmd];
    }

    /**
     * Coerce the raw input by the setting's internal type and PUT it; an invalid
     * value re-opens the input with an error toast and issues no request.
     *
     * @return array{self, ?\Closure}
     */
    private function submitEdit(string $raw, ServerSetting $setting): array
    {
        $value = $this->coerce($raw, $setting->type);
        if ($value === null) {
            $fresh = self::buildEditForm($setting->displayValue);

            return [$this->withEditForm($fresh, $setting), Cmd::batch(Cmd::send(ShowToastMsg::error($this->coerceError($setting->type))), $fresh->init())];
        }

        [$coerced] = $value;

        return [$this->closeEdit()->working(), $this->actionCmd($this->admin->updateServerSetting($setting->key, $coerced))];
    }

    /**
     * Coerce a raw input to the setting's internal type, or null when invalid.
     * Returns a single-element tuple `{value}` so a legitimately-falsey coerced
     * value (e.g. `0`, `''`, `[]`) is still distinguishable from a rejection.
     *
     * @return array{0: int|float|string|array<array-key,mixed>}|null
     */
    private function coerce(string $raw, string $type): ?array
    {
        return match ($type) {
            'int' => preg_match('/^-?\d+$/', $raw) === 1 ? [(int) $raw] : null,
            'float' => is_numeric($raw) ? [(float) $raw] : null,
            'json' => self::coerceJson($raw),
            default => [$raw],
        };
    }

    /**
     * Decode a JSON string to an array (the server requires an array for a json
     * setting), or null when it is not valid JSON or not an array.
     *
     * @return array{0: array<array-key,mixed>}|null
     */
    private static function coerceJson(string $raw): ?array
    {
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? [$decoded] : null;
    }

    /** The error toast for an invalid value of the given type. */
    private function coerceError(string $type): string
    {
        return match ($type) {
            'int' => 'Enter a whole number.',
            'float' => 'Enter a number.',
            'json' => 'Enter a valid JSON array or object.',
            default => 'Invalid value.',
        };
    }

    private static function buildEditForm(string $value): Form
    {
        return Form::new(
            Input::new('value')
                ->withTitle('New value')
                ->withValue($value),
        );
    }

    // ---- post-action ---------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function onActionDone(AdminSettingActionDoneMsg $msg): array
    {
        // Refetch via GET so the change shows (the PUT response carries no types).
        return [$this->working(), Cmd::batch(Cmd::send(ShowToastMsg::success($msg->message)), $this->fetchCmd())];
    }

    /** @return array{self, ?\Closure} */
    private function refresh(): array
    {
        $next = clone $this;
        $next->loaded = false;
        $next->error = null;

        return [$next, $next->fetchCmd()];
    }

    // ---- clone-mutate copies -------------------------------------------

    /** @param list<ServerSetting> $settings */
    private function withSettings(array $settings): self
    {
        $next = clone $this;
        $next->settings = $settings;
        $next->loaded = true;
        $next->busy = false;
        $next->error = null;
        $next->selected = $settings === [] ? 0 : min($this->selected, count($settings) - 1);

        return $next;
    }

    private function withError(string $error): self
    {
        $next = clone $this;
        $next->error = $error;
        $next->loaded = false;
        $next->busy = false;

        return $next;
    }

    /** Enter the busy (in-flight) state. */
    private function working(): self
    {
        $next = clone $this;
        $next->busy = true;

        return $next;
    }

    /** Leave the busy state (after a failed action) without touching the list. */
    private function idle(): self
    {
        $next = clone $this;
        $next->busy = false;

        return $next;
    }

    private function openEdit(ServerSetting $setting): self
    {
        return $this->withEditForm(self::buildEditForm($setting->displayValue), $setting);
    }

    private function closeEdit(): self
    {
        $next = clone $this;
        $next->editForm = null;
        $next->editing = null;

        return $next;
    }

    private function withEditForm(Form $form, ServerSetting $setting): self
    {
        $next = clone $this;
        $next->editForm = $form;
        $next->editing = $setting;

        return $next;
    }

    private function moveSelection(int $delta): self
    {
        $count = count($this->settings);
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
            return "\n  Loading settings…";
        }
        if ($this->error !== null) {
            return "\n  {$this->error}\n\n  Press r to retry.";
        }
        if ($this->settings === []) {
            return "\n  No settings.\n\n" . $this->statusLine();
        }

        $rows = [];
        foreach ($this->settings as $setting) {
            $rows[] = [
                $setting->key,
                $setting->displayValue === '' ? '—' : $setting->displayValue,
                $setting->type === '' ? '—' : $setting->type,
                $setting->overridden ? '✓' : '–',
            ];
        }

        $table = Table::render([
            ['title' => 'Key', 'width' => 0],
            ['title' => 'Value', 'width' => 28],
            ['title' => 'Type', 'width' => 8],
            ['title' => 'Override', 'width' => 9, 'align' => 'right'],
        ], $rows, $this->selected, $this->cols - 4, $this->viewportRows());

        return "\n" . $table . "\n\n" . $this->statusLine();
    }

    /** The status line under the table: the busy note, else a hint. */
    private function statusLine(): string
    {
        if ($this->busy) {
            return '  Working…';
        }

        return '  select a setting and press e to edit it.';
    }

    private function editBody(Form $form, ServerSetting $setting): string
    {
        $lines = [
            "Editing '{$setting->key}' (type: {$setting->type}).",
            '',
        ];

        return implode("\n", $lines) . $form->view();
    }

    private function viewportRows(): int
    {
        // The frame body holds the table (header + rule = 2 extra rows), then a
        // blank line + the status line. Window the data rows to the body height
        // less those chrome rows so the selected row is never clipped.
        return max(1, Chrome::bodyHeight($this->rows) - 4);
    }

    private function selectedSetting(): ?ServerSetting
    {
        return $this->settings[$this->selected] ?? null;
    }

    // ---- breadcrumb ----------------------------------------------------

    public function crumbLabel(): string
    {
        return 'Settings';
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

    /** @return list<ServerSetting> */
    public function settingList(): array
    {
        return $this->settings;
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

    public function isEditing(): bool
    {
        return $this->editForm !== null;
    }

    public function editingKey(): ?string
    {
        return $this->editing?->key;
    }
}
