<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Config\Config;
use Phlix\Console\Msg\SubmitServerMsg;
use Phlix\Console\Ui\Chrome;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Form;

/**
 * First-run wizard: a one-field sugar-forms form asking for the Phlix server
 * URL. On submit it emits {@see SubmitServerMsg} (the App persists + proceeds);
 * Esc quits.
 *
 * The embedded Form returns Cmd::quit() on submit/abort (it's built to run as a
 * standalone Program); this screen intercepts that and substitutes its own
 * navigation intent so the app doesn't exit.
 */
final class ServerScreen implements Model, Themed, CapturesSlash
{
    use SubscriptionCapable;
    use ThemedScreen;

    public function __construct(
        public readonly Form $form,
        public readonly ?string $error = null,
        public readonly int $cols = 80,
        public readonly int $rows = 24,
    ) {
    }

    public static function create(?string $error = null, int $cols = 80, int $rows = 24): self
    {
        return new self(self::buildForm(), $error, $cols, $rows);
    }

    private static function buildForm(): Form
    {
        return Form::new(
            Input::new('server')
                ->withTitle('Phlix server URL')
                ->withPlaceholder('https://host:8096')
                ->required(),
        );
    }

    public function init(): ?\Closure
    {
        return $this->form->init();
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [new self($this->form, $this->error, $msg->cols, $msg->rows), null];
        }

        /** @var array{0: Form, 1: ?\Closure} $result sugar-forms' Form::update inherits Model's loose `:array` return, so narrow it. */
        $result = $this->form->update($msg);
        [$form, $cmd] = $result;

        if ($form->isAborted()) {
            return [$this, Cmd::quit()];
        }

        if ($form->isSubmitted()) {
            $url = Config::normalizeUrl($form->getString('server'));
            if ($url === '') {
                $fresh = self::buildForm();
                return [new self($fresh, 'Please enter a server URL.', $this->cols, $this->rows), $fresh->init()];
            }

            // Shape gate [review console__qa m-2, M-CONSOLE-50]: a URL that
            // cannot exist must not be persisted + advanced past — the wizard
            // would announce "Connecting…" and land on Login for a host that is
            // structurally impossible (5k chars, emoji, spaces). Reachability
            // stays a sign-in-time concern (that failure UX is already fast and
            // clear); this gate only enforces the shape law at the boundary.
            if (!self::isPlausibleServerUrl($url)) {
                return [
                    new self($form, 'Enter a valid server URL (e.g. https://phlix.example.com).', $this->cols, $this->rows),
                    null,
                ];
            }

            return [new self($form, null, $this->cols, $this->rows), Cmd::send(new SubmitServerMsg($url))];
        }

        // When Enter is pressed on an empty required field, isSubmitted() is false
        // because the form hasn't received any input yet. Catch this case to show
        // the validation error rather than silently ignoring the submit attempt.
        if ($msg instanceof KeyMsg && $msg->type === KeyType::Enter && trim($form->getString('server')) === '') {
            $fresh = self::buildForm();
            return [new self($fresh, 'Please enter a server URL.', $this->cols, $this->rows), $fresh->init()];
        }

        return [new self($form, $this->error, $this->cols, $this->rows), $cmd];
    }

    public function view(): string
    {
        $lines = ['Connect to your Phlix server.', ''];
        if ($this->error !== null) {
            $lines[] = '  ' . $this->error;
            $lines[] = '';
        }
        // Live insecure warning [review console__secaudit, M-CONSOLE-52]: an
        // explicitly typed http:// URL is accepted (LAN/dev reality) but the
        // form says out loud what it means for the credentials about to move.
        $typed = Config::normalizeUrl($this->form->getString('server'));
        if ($typed !== '' && Config::isInsecureUrl($typed)) {
            $lines[] = '  ⚠ http:// sends your password and session tokens unencrypted.';
            $lines[] = '    Use https:// unless this is a trusted local network.';
            $lines[] = '';
        }
        $body = implode("\n", $lines) . $this->form->view();

        return Chrome::frame('Setup', $body, 'Enter  connect      Esc  quit', $this->cols, $this->rows, theme: $this->theme());
    }

    /**
     * Does the normalized URL have a shape a server could ever have? http(s)
     * scheme (normalizeUrl forces one, so this only fires on scheme-embedded
     * oddities) and filter_var-valid — which empirically refuses absurd lengths,
     * non-ASCII host labels, and spaces before the value is persisted.
     */
    private static function isPlausibleServerUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
