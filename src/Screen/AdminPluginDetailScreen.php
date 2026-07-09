<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Admin\PluginDetail;
use Phlix\Console\Api\Dto\Admin\PluginSettingField;
use Phlix\Console\Msg\AdminPluginDetailFailedMsg;
use Phlix\Console\Msg\AdminPluginDetailLoadedMsg;
use Phlix\Console\Msg\AdminPluginSettingFailedMsg;
use Phlix\Console\Msg\AdminPluginSettingSavedMsg;
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
 * The admin Plugin-detail surface: a header (name · version · type · enabled ·
 * installed-at) over a windowed {@see Table} of the plugin's settings-schema
 * fields (Label · Type · Value · Required), driven by per-field edits.
 *
 * The field set comes from the plugin's `settings_schema` (the authoritative
 * keys); editing mirrors the Server-Settings P8.6 typed editor:
 *   - a **bool** field (`e`/Enter) toggles immediately and PUTs the flipped real
 *     bool — no form;
 *   - any **non-bool** field opens an embedded candy-forms {@see Input}; the
 *     candy-forms quit-intercept (abort → cancel / submit → coerce + PUT) keeps
 *     the embedded `Cmd::quit()` from exiting the app. The input is coerced by the
 *     field type BEFORE sending: int rejects a non-`^-?\d+$` value, float a
 *     non-numeric one, json must decode to an array — an invalid value re-opens
 *     the input with an error toast and issues NO request; a string passes
 *     through verbatim.
 *   - a **secret** field is special: the input pre-fills BLANK (with a "leave
 *     blank to keep" note — the masked value is never put back), and a blank
 *     submit is a NO-OP (the input closes, no request fires).
 *
 * UNLIKE the Server-Settings screen, the PUT response carries the REFRESHED
 * detail (`{plugin}`), so a successful save swaps the whole detail in directly —
 * no refetch. On failure the server `error` is toasted and the detail is left
 * unchanged; an auth failure surfaces a session expiry.
 *
 * The client is injected (built locally by the App from its shared ApiClient, so
 * the App holds no AdminClient field). Stable collaborators are readonly; the
 * loaded detail, selection, busy flag, and the embedded edit form (plus the field
 * it edits) are private mutable view state set via clone-mutate (the established
 * screen idiom).
 */
final class AdminPluginDetailScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LOAD_FAILED = 'Could not load the plugin.';
    private const HINT = '↑↓ select   e/⏎ edit   r refresh   Esc back';
    private const EDIT_HINT = 'Enter  save      Esc  cancel';
    private const SECRET_MASK = '••••••';

    private ?PluginDetail $detail = null;
    private bool $loaded = false;
    private ?string $error = null;

    private int $selected = 0;

    /** A fetch / action is in flight (input that mutates is ignored while busy). */
    private bool $busy = false;

    /** The embedded edit form while the value input is open, else null. */
    private ?Form $editForm = null;

    /** The field being edited (so the submit knows which key to coerce), else null. */
    private ?PluginSettingField $editing = null;

    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private readonly AdminClient $admin,
        private readonly string $pluginName,
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
        return Cmd::promise(fn () => $this->admin->pluginDetail($this->pluginName)->then(
            static fn (PluginDetail $detail): Msg => new AdminPluginDetailLoadedMsg($detail),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminPluginDetailFailedMsg(self::LOAD_FAILED),
        ));
    }

    /**
     * Build the command for a fired PUT: the update promise mapped to a
     * saved/failed Msg. On success the server returns the REFRESHED detail, so the
     * saved Msg carries it (no refetch); on failure the server `error` is surfaced
     * (a session expiry on auth).
     *
     * @param PromiseInterface<PluginDetail> $promise
     */
    private function saveCmd(PromiseInterface $promise): \Closure
    {
        return Cmd::promise(static fn () => $promise->then(
            static fn (PluginDetail $detail): Msg => new AdminPluginSettingSavedMsg($detail),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminPluginSettingFailedMsg($e->getMessage()),
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
        if ($msg instanceof AdminPluginDetailLoadedMsg) {
            return [$this->withDetail($msg->detail), null];
        }
        if ($msg instanceof AdminPluginDetailFailedMsg) {
            return [$this->withError($msg->message), null];
        }
        if ($msg instanceof AdminPluginSettingSavedMsg) {
            return $this->onSaved($msg);
        }
        if ($msg instanceof AdminPluginSettingFailedMsg) {
            return [$this->idle(), Cmd::send(ShowToastMsg::error($msg->message))];
        }

        return [$this, null];
    }

    public function view(): string
    {
        if ($this->editForm !== null && $this->editing !== null) {
            return Chrome::frame('Admin · Plugin · Edit', $this->editBody($this->editForm, $this->editing), self::EDIT_HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
        }

        return Chrome::frame('Admin · Plugin', $this->body(), self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
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
     * Begin editing the selected field: a bool field toggles + PUTs immediately;
     * any other field opens the value input (pre-filled, or BLANK for a secret).
     *
     * @return array{self, ?\Closure}
     */
    private function beginEdit(): array
    {
        $field = $this->selectedField();
        if ($field === null) {
            return [$this, null];
        }
        if ($field->isBool()) {
            return [$this->working(), $this->saveCmd($this->admin->updatePluginSetting($this->pluginName, $field->key, !$field->boolValue()))];
        }

        return [$this->openEdit($field), null];
    }

    // ---- value-edit input (embedded candy-forms) -----------------------

    /**
     * Drive the embedded value form. candy-forms' Form returns Cmd::quit() on
     * submit/abort; we intercept that — an abort cancels, a submit coerces the raw
     * input by the field's type (rejecting an invalid value with an error toast and
     * re-opening, issuing no request) and PUTs the coerced value. A blank secret
     * submit is a no-op (the input just closes).
     *
     * @return array{self, ?\Closure}
     */
    private function updateEdit(Msg $msg, Form $form, PluginSettingField $field): array
    {
        /** @var array{0: Form, 1: ?\Closure} $result candy-forms' Form::update returns Model's loose `:array`; narrow it. */
        $result = $form->update($msg);
        [$next, $cmd] = $result;

        if ($next->isAborted()) {
            return [$this->closeEdit(), null];
        }

        if ($next->isSubmitted()) {
            return $this->submitEdit($next->getString('value'), $field);
        }

        return [$this->withEditForm($next, $field), $cmd];
    }

    /**
     * Coerce the raw input by the field's type and PUT it; an invalid value
     * re-opens the input with an error toast and issues no request. A blank secret
     * is a no-op (closes the input, keeps the stored value, no request).
     *
     * @return array{self, ?\Closure}
     */
    private function submitEdit(string $raw, PluginSettingField $field): array
    {
        if ($field->secret && $raw === '') {
            // Leave-blank-to-keep: never re-send the masked value.
            return [$this->closeEdit(), null];
        }

        $value = $this->coerce($raw, $field->kind());
        if ($value === null) {
            $fresh = self::buildEditForm($field, '');

            return [$this->withEditForm($fresh, $field), Cmd::batch(Cmd::send(ShowToastMsg::error($this->coerceError($field->kind()))), $fresh->init())];
        }

        [$coerced] = $value;

        return [$this->closeEdit()->working(), $this->saveCmd($this->admin->updatePluginSetting($this->pluginName, $field->key, $coerced))];
    }

    /**
     * Coerce a raw input to the field's normalized {@see PluginSettingField::kind()}
     * (`int|float|json|string` — a `bool` field never reaches here, it toggles),
     * or null when invalid. Returns a single-element tuple `{value}` so a
     * legitimately-falsey coerced value (e.g. `0`, `''`, `[]`) is still
     * distinguishable from a rejection.
     *
     * @return array{0: bool|int|float|string|array<array-key,mixed>}|null
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
     * Decode a JSON string to an array (a json field requires an array), or null
     * when it is not valid JSON or not an array.
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

    private static function buildEditForm(PluginSettingField $field, string $value): Form
    {
        $input = Input::new('value')
            ->withTitle('New value')
            ->withValue($value);
        if ($field->secret) {
            $input = $input->withPlaceholder('leave blank to keep');
        }

        return Form::new($input);
    }

    // ---- post-save -----------------------------------------------------

    /**
     * A save landed with the refreshed detail: swap it in (clearing busy) and
     * toast success. No refetch — the PUT already returned the fresh detail.
     *
     * @return array{self, ?\Closure}
     */
    private function onSaved(AdminPluginSettingSavedMsg $msg): array
    {
        return [$this->withDetail($msg->detail), Cmd::send(ShowToastMsg::success('Setting updated.'))];
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

    private function withDetail(PluginDetail $detail): self
    {
        $next = clone $this;
        $next->detail = $detail;
        $next->loaded = true;
        $next->busy = false;
        $next->error = null;
        $next->editForm = null;
        $next->editing = null;
        $count = count($detail->fields);
        $next->selected = $count === 0 ? 0 : min($this->selected, $count - 1);

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

    /** Leave the busy state (after a failed save) without touching the detail. */
    private function idle(): self
    {
        $next = clone $this;
        $next->busy = false;

        return $next;
    }

    private function openEdit(PluginSettingField $field): self
    {
        // A secret pre-fills BLANK (leave-blank-to-keep); everything else
        // pre-fills the current display value.
        $prefill = $field->secret ? '' : $field->value;

        return $this->withEditForm(self::buildEditForm($field, $prefill), $field);
    }

    private function closeEdit(): self
    {
        $next = clone $this;
        $next->editForm = null;
        $next->editing = null;

        return $next;
    }

    private function withEditForm(Form $form, PluginSettingField $field): self
    {
        $next = clone $this;
        $next->editForm = $form;
        $next->editing = $field;

        return $next;
    }

    private function moveSelection(int $delta): self
    {
        $count = count($this->fields());
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
            return "\n  Loading plugin…";
        }
        if ($this->error !== null) {
            return "\n  {$this->error}\n\n  Press r to retry.";
        }

        $fields = $this->fields();
        if ($fields === []) {
            return "\n" . $this->headerLine() . "\n\n  This plugin has no editable settings.\n\n" . $this->statusLine();
        }

        $rows = [];
        foreach ($fields as $field) {
            $rows[] = [
                $field->displayLabel(),
                $field->type === '' ? '—' : $field->type,
                $this->fieldValue($field),
                $field->required ? '✓' : '–',
            ];
        }

        $table = Table::render([
            ['title' => 'Setting', 'width' => 0],
            ['title' => 'Type', 'width' => 8],
            ['title' => 'Value', 'width' => 28],
            ['title' => 'Required', 'width' => 9, 'align' => 'right'],
        ], $rows, $this->selected, $this->cols - 4, $this->viewportRows());

        return "\n" . $this->headerLine() . "\n" . $table . "\n\n" . $this->statusLine();
    }

    /** A field's display value: a secret is shown masked; a blank is an em dash. */
    private function fieldValue(PluginSettingField $field): string
    {
        if ($field->secret) {
            return $field->value === '' ? '—' : self::SECRET_MASK;
        }

        return $field->value === '' ? '—' : $field->value;
    }

    /** The header: name · version · type · enabled · installed-at. */
    private function headerLine(): string
    {
        $detail = $this->detail;
        if ($detail === null) {
            return '  ' . $this->pluginName;
        }
        $parts = [$detail->name === '' ? $this->pluginName : $detail->name];
        if ($detail->version !== '') {
            $parts[] = 'v' . $detail->version;
        }
        if ($detail->type !== '') {
            $parts[] = $detail->type;
        }
        $parts[] = $detail->enabled ? 'enabled' : 'disabled';
        if ($detail->installedAt !== null) {
            $parts[] = 'installed ' . $detail->installedAt;
        }

        return '  ' . implode(' · ', $parts);
    }

    /** The status line under the table: the busy note, else a hint. */
    private function statusLine(): string
    {
        if ($this->busy) {
            return '  Working…';
        }

        return '  select a setting and press e to edit it.';
    }

    private function editBody(Form $form, PluginSettingField $field): string
    {
        $lines = ["Editing '{$field->displayLabel()}' (type: {$field->type})."];
        if ($field->secret) {
            $lines[] = 'This is a secret — leave blank to keep the current value.';
        }
        if ($field->description !== '') {
            $lines[] = $field->description;
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

    /** @return list<PluginSettingField> */
    private function fields(): array
    {
        return $this->detail === null ? [] : $this->detail->fields;
    }

    private function selectedField(): ?PluginSettingField
    {
        return $this->fields()[$this->selected] ?? null;
    }

    // ---- breadcrumb ----------------------------------------------------

    public function crumbLabel(): string
    {
        return 'Plugin';
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

    public function detail(): ?PluginDetail
    {
        return $this->detail;
    }

    /** @return list<PluginSettingField> */
    public function fieldList(): array
    {
        return $this->fields();
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
