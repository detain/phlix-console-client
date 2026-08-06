<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Msg\OpenLoginMsg;
use Phlix\Console\Msg\SubmitRegisterMsg;
use Phlix\Console\Ui\Chrome;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Form;

/**
 * New account registration form. On submit it emits {@see SubmitRegisterMsg}
 * (the App runs the async registration) and shows a "creating account" state
 * that ignores further input until the result arrives. Esc returns to login.
 *
 * As with {@see LoginScreen}, the embedded Form's submit/abort Cmd::quit() is
 * intercepted and replaced with a navigation intent.
 */
final class RegisterScreen implements Model, Themed, CapturesSlash
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
            Input::new('email')->withTitle('Email')->required(),
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

        // Freeze input while a registration request is in flight.
        if ($this->submitting) {
            return [$this, null];
        }

        /** @var array{0: Form, 1: ?\Closure} $result candy-forms' Form::update inherits Model's loose `:array` return, so narrow it. */
        $result = $this->form->update($msg);
        [$form, $cmd] = $result;

        if ($form->isAborted()) {
            return [$this, Cmd::send(new OpenLoginMsg())];
        }

        if ($form->isSubmitted()) {
            $submit = new SubmitRegisterMsg(
                $form->getString('username'),
                $form->getString('email'),
                $form->getString('password'),
            );

            return [new self($form, null, true, $this->cols, $this->rows), Cmd::send($submit)];
        }

        return [new self($form, $this->error, false, $this->cols, $this->rows), $cmd];
    }

    public function view(): string
    {
        $lines = ['Create your Phlix account.', ''];
        if ($this->submitting) {
            $lines[] = '  Creating account…';
            $lines[] = '';
        } elseif ($this->error !== null) {
            $lines[] = '  ' . $this->error;
            $lines[] = '';
        }
        $body = implode("\n", $lines) . $this->form->view();

        return Chrome::frame('Register', $body, 'Tab  next      Enter  create account      Esc  back to login', $this->cols, $this->rows, theme: $this->theme());
    }
}
