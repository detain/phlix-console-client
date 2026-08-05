<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Admin\ToneMappingSettings;
use Phlix\Console\Api\Dto\Admin\TranscodingAccelerators;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Msg\TranscodingLoadedMsg;
use Phlix\Console\Msg\TranscodingLoadFailedMsg;
use Phlix\Console\Msg\TranscodingActionDoneMsg;
use Phlix\Console\Msg\TranscodingActionFailedMsg;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\Table;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Field\Toggle;
use SugarCraft\Forms\Form;

/**
 * The admin Transcoding surface: displays hardware-accelerator introspection
 * and HDR tone-mapping settings. The accelerators panel is read-only;
 * tone-mapping is editable via the same type-based form pattern as
 * {@see AdminSettingsScreen}.
 *
 * The screen fetches both panels concurrently on init; the tone-mapping
 * form POSTs on submit and refetches on success.
 */
final class AdminTranscodingScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LOAD_FAILED = 'Could not load transcoding settings.';
    private const HINT = '↑↓ select   e/⏎ edit tone-mapping   r refresh   Esc back';
    private const EDIT_HINT = 'Enter  save      Esc  cancel';
    private const TONE_MAPPING_SECTION = 0;

    private ?TranscodingAccelerators $accelerators = null;
    private ?ToneMappingSettings $toneMapping = null;
    private bool $loaded = false;
    private ?string $error = null;

    /** 0 = tone-mapping, 1 = accelerators */
    private int $selected = 0;

    /** @var list<string> */
    private array $crumbs = [];

    /** A fetch / action is in flight. */
    private bool $busy = false;

    /** The embedded edit form while tone-mapping input is open, else null. */
    private ?Form $editForm = null;

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
        return Cmd::promise(function (): \React\Promise\PromiseInterface {
            return \React\Promise\all([
                $this->admin->transcodingAccelerators(),
                $this->admin->transcodingToneMapping(),
            ])->then(
                /** @param array<int, mixed> $results */
                static function (array $results): Msg {
                    /** @var TranscodingAccelerators $accel */
                    $accel = $results[0];
                    /** @var ToneMappingSettings $tone */
                    $tone = $results[1];

                    return new TranscodingLoadedMsg($accel, $tone);
                },
                static fn (\Throwable $e): Msg => $e instanceof AuthError
                    ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                    : new TranscodingLoadFailedMsg(self::LOAD_FAILED),
            );
        });
    }

    // ---- update --------------------------------------------------------

    /** @return array{self, ?\Closure} */
    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [$this->resizedTo($msg->cols, $msg->rows), null];
        }
        if ($this->editForm !== null) {
            return $this->updateEdit($msg);
        }
        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }
        if ($msg instanceof TranscodingLoadedMsg) {
            return [$this->withData($msg->accelerators, $msg->toneMapping), null];
        }
        if ($msg instanceof TranscodingLoadFailedMsg) {
            return [$this->withError($msg->message), null];
        }
        if ($msg instanceof TranscodingActionDoneMsg) {
            return [$this->idle(), Cmd::batch(Cmd::send(ShowToastMsg::success($msg->message)), $this->fetchCmd())];
        }
        if ($msg instanceof TranscodingActionFailedMsg) {
            return [$this->idle(), Cmd::send(ShowToastMsg::error($msg->message))];
        }

        return [$this, null];
    }

    public function view(): string
    {
        if ($this->editForm !== null) {
            return Chrome::frame(
                'Admin · Transcoding · Edit',
                $this->editBody(),
                self::EDIT_HINT,
                $this->cols,
                $this->rows,
                $this->crumbs,
                $this->theme(),
            );
        }

        return Chrome::frame(
            'Admin · Transcoding',
            $this->body(),
            self::HINT,
            $this->cols,
            $this->rows,
            $this->crumbs,
            $this->theme(),
        );
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
     * Begin editing tone-mapping: open the value input form.
     *
     * @return array{self, ?\Closure}
     */
    private function beginEdit(): array
    {
        if ($this->selected !== self::TONE_MAPPING_SECTION) {
            return [$this, null];
        }

        return [$this->openEdit(), null];
    }

    // ---- value-edit form -----------------------------------------------

    /** @return array{self, ?\Closure} */
    private function updateEdit(Msg $msg): array
    {
        $form = $this->editForm;
        assert($form !== null);
        /** @var array{0: Form, 1: ?\Closure} $result */
        $result = $form->update($msg);
        [$next, $cmd] = $result;

        if ($next->isAborted()) {
            return [$this->closeEdit(), null];
        }

        if ($next->isSubmitted()) {
            return $this->submitEdit($next);
        }

        return [$this->withEditForm($next), $cmd];
    }

    /**
     * Submit the tone-mapping form: extract the two fields and PUT.
     *
     * @return array{self, ?\Closure}
     */
    private function submitEdit(Form $form): array
    {
        $mode = $form->getString('tone_mapping_mode');
        $preferHdrRaw = $form->getString('prefer_hdr_output');
        $preferHdr = $preferHdrRaw === 'Yes';

        return [
            $this->closeEdit()->working(),
            $this->actionCmd($this->admin->updateToneMapping($mode, $preferHdr)),
        ];
    }

    private static function buildEditForm(ToneMappingSettings $settings): Form
    {
        return Form::new(
            Select::new('tone_mapping_mode')
                ->withTitle('Tone Mapping Mode')
                ->withOptions('none', 'zscale', 'libplacebo')
                ->withSelected($settings->toneMappingMode),
            Select::new('prefer_hdr_output')
                ->withTitle('Prefer HDR Output')
                ->withOptions('No', 'Yes')
                ->withSelected($settings->preferHdrOutput ? 'Yes' : 'No'),
        );
    }

    // ---- post-action ---------------------------------------------------

    /**
     * Fire the tone-mapping PUT and resolve the done/failed message.
     *
     * @param \React\Promise\PromiseInterface<ToneMappingSettings> $promise
     * @return \Closure
     */
    private function actionCmd(\React\Promise\PromiseInterface $promise): \Closure
    {
        return Cmd::promise(static fn () => $promise->then(
            static function (ToneMappingSettings $s): Msg {
                return new TranscodingActionDoneMsg('Tone-mapping settings updated.');
            },
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new TranscodingActionFailedMsg($e->getMessage()),
        ));
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

    private function withData(TranscodingAccelerators $accelerators, ToneMappingSettings $toneMapping): self
    {
        $next = clone $this;
        $next->accelerators = $accelerators;
        $next->toneMapping = $toneMapping;
        $next->loaded = true;
        $next->busy = false;
        $next->error = null;

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

    /** Leave the busy state (after a failed action). */
    private function idle(): self
    {
        $next = clone $this;
        $next->busy = false;

        return $next;
    }

    private function openEdit(): self
    {
        if ($this->toneMapping === null) {
            return $this;
        }

        return $this->withEditForm(self::buildEditForm($this->toneMapping));
    }

    private function closeEdit(): self
    {
        $next = clone $this;
        $next->editForm = null;

        return $next;
    }

    private function withEditForm(Form $form): self
    {
        $next = clone $this;
        $next->editForm = $form;

        return $next;
    }

    private function moveSelection(int $delta): self
    {
        $count = 2;
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
            return "\n  Loading transcoding settings…";
        }
        if ($this->error !== null) {
            return "\n  {$this->error}\n\n  Press r to retry.";
        }

        $sections = [];

        // Tone-mapping section
        $tm = $this->toneMapping;
        $sections[] = [
            'Tone Mapping',
            $tm !== null
                ? "Mode: {$tm->toneMappingMode}  |  Prefer HDR: " . ($tm->preferHdrOutput ? 'Yes' : 'No')
                : '—',
        ];

        // Accelerators section
        $acc = $this->accelerators;
        $accelLines = [];
        if ($acc !== null) {
            $accelLines[] = "FFmpeg: {$acc->ffmpegVersion}";
            if ($acc->preferredAccelerator !== null) {
                $accelLines[] = "Preferred: {$acc->preferredAccelerator}";
            }
            foreach ($acc->accelerators as $a) {
                $hw = $a->isHardware ? 'HW' : 'SW';
                $encoders = implode(', ', $a->encoders);
                $accelLines[] = "{$a->name} [{$hw}] — {$encoders}";
            }
        } else {
            $accelLines[] = '—';
        }
        $sections[] = ['Hardware Accelerators', implode("\n" . str_repeat(' ', 24), $accelLines)];

        return "\n" . Table::render([
            ['title' => 'Panel', 'width' => 22],
            ['title' => 'Value', 'width' => 0],
        ], $sections, $this->selected, $this->cols - 4, $this->viewportRows()) . "\n\n" . $this->statusLine();
    }

    private function editBody(): string
    {
        if ($this->editForm === null) {
            return '';
        }

        return $this->editForm->view();
    }

    private function statusLine(): string
    {
        if ($this->busy) {
            return '  Working…';
        }

        return $this->selected === self::TONE_MAPPING_SECTION
            ? '  Select tone-mapping and press e to edit.'
            : '  Hardware accelerator info is read-only.';
    }

    private function viewportRows(): int
    {
        return max(1, Chrome::bodyHeight($this->rows) - 4);
    }

    // ---- breadcrumb ----------------------------------------------------

    public function crumbLabel(): string
    {
        return 'Transcoding';
    }

    public function withCrumbs(array $trail): static
    {
        $next = clone $this;
        $next->crumbs = $trail;

        return $next;
    }
}
