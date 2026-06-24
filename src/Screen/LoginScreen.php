<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Msg\SubmitLoginMsg;
use Phlix\Console\Ui\Chrome;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Form;

/**
 * Username + password login form. On submit it emits {@see SubmitLoginMsg}
 * (the App runs the async login) and shows a "signing in" state that ignores
 * further input until the result arrives. Esc quits.
 *
 * As with {@see ServerScreen}, the embedded Form's submit/abort Cmd::quit() is
 * intercepted and replaced with a navigation intent.
 */
final class LoginScreen implements Model, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    public function __construct(
        public readonly Form $form,
        public readonly ?string $error = null,
        public readonly bool $submitting = false,
        public readonly int $cols = 80,
        public readonly int $rows = 24,
    ) {
    }

    public static function create(?string $error = null, int $cols = 80, int $rows = 24): self
    {
        return new self(self::buildForm(), $error, false, $cols, $rows);
    }

    private static function buildForm(): Form
    {
        return Form::new(
            Input::new('username')->withTitle('Username')->required(),
            Input::new('password')->withTitle('Password')->withPassword()->required(),
        );
    }

    public function init(): ?\Closure
    {
        return $this->form->init();
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [new self($this->form, $this->error, $this->submitting, $msg->cols, $msg->rows), null];
        }

        // Freeze input while a login request is in flight.
        if ($this->submitting) {
            return [$this, null];
        }

        /** @var array{0: Form, 1: ?\Closure} $result candy-forms' Form::update inherits Model's loose `:array` return, so narrow it. */
        $result = $this->form->update($msg);
        [$form, $cmd] = $result;

        if ($form->isAborted()) {
            return [$this, Cmd::quit()];
        }

        if ($form->isSubmitted()) {
            $submit = new SubmitLoginMsg($form->getString('username'), $form->getString('password'));

            return [new self($form, null, true, $this->cols, $this->rows), Cmd::send($submit)];
        }

        return [new self($form, $this->error, false, $this->cols, $this->rows), $cmd];
    }

    public function view(): string
    {
        $lines = ['Sign in to Phlix.', ''];
        if ($this->submitting) {
            $lines[] = '  Signing in…';
            $lines[] = '';
        } elseif ($this->error !== null) {
            $lines[] = '  ' . $this->error;
            $lines[] = '';
        }
        $body = implode("\n", $lines) . $this->form->view();

        return Chrome::frame('Login', $body, 'Tab  next      Enter  sign in      Esc  quit', $this->cols, $this->rows, theme: $this->theme());
    }
}
