<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Msg\AdminServicesLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Ui\Chrome;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;

/**
 * The admin Services surface shows Trakt and Last.fm connection status.
 * Keys: t=disconnect Trakt, l=disconnect Last.fm.
 * Disconnect requires typing "disconnect" to confirm.
 * Tokens are never displayed.
 */
final class AdminServicesScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;
    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LOAD_FAILED = 'Could not load services.';
    private const HINT = 't  disconnect Trakt     l  disconnect Last.fm     r  refresh     Esc  back';
    private const CONFIRM_HINT = 'Type "disconnect" and press Enter to confirm     Esc  cancel';

    /** @var array{trakt: array{connected:bool, username?:?string, configured:bool}, lastfm: array{connected:bool, username?:?string, api_key_set:bool}}|null */
    private ?array $services = null;

    private bool $loaded = false;

    /** The service pending disconnect, or null when no confirm is in flight. */
    private ?string $pendingService = null;

    /** The characters typed so far while confirming a disconnect. */
    private string $typed = '';

    /** @var list<string> */
    private array $crumbs = ['Admin', 'Services'];

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
        return Cmd::promise(fn (): PromiseInterface => $this->admin->servicesStatus()->then(
            static fn (array $services): Msg => new AdminServicesLoadedMsg($services),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : ShowToastMsg::error(self::LOAD_FAILED),
        ));
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
        if ($msg instanceof AdminServicesLoadedMsg) {
            return [$this->withServices($msg->services), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame(
            'Admin · Services',
            $this->body(),
            $this->pendingService !== null ? self::CONFIRM_HINT : self::HINT,
            $this->cols,
            $this->rows,
            $this->crumbs,
        );
    }

    private function body(): string
    {
        if (!$this->loaded && $this->services === null) {
            return "\n\n  Loading...\n";
        }

        /** @var array{trakt: array{connected:bool, username?:?string, configured:bool}, lastfm: array{connected:bool, username?:?string, api_key_set:bool}} $services */
        $services = $this->services;

        $traktStatus = $this->formatTrakt($services['trakt']);
        $lastfmStatus = $this->formatLastfm($services['lastfm']);

        $confirmLine = '';
        if ($this->pendingService !== null) {
            $service = ucfirst($this->pendingService);
            $confirmLine = "\n\n  Disconnect {$service}?\n  Type \"disconnect\" to confirm: {$this->typed}";
        }

        return <<<BODY

  Connected Services

  {$traktStatus}

  {$lastfmStatus}{$confirmLine}
BODY;
    }

    /**
     * @param array{connected:bool, username?:?string, configured:bool} $info
     */
    private function formatTrakt(array $info): string
    {
        $connected = $info['connected'];
        $username = $info['username'] ?? null;
        $configured = $info['configured'];

        if (!$configured) {
            return '  Trakt: Not configured';
        }

        if ($connected) {
            $userStr = $username !== null ? " ({$username})" : '';

            return "  Trakt: Connected{$userStr}";
        }

        return '  Trakt: Not connected';
    }

    /**
     * @param array{connected:bool, username?:?string, api_key_set:bool} $info
     */
    private function formatLastfm(array $info): string
    {
        $connected = $info['connected'];
        $username = $info['username'] ?? null;
        $apiKeySet = $info['api_key_set'];

        if (!$apiKeySet) {
            return '  Last.fm: Not configured';
        }

        if ($connected) {
            $userStr = $username !== null ? " ({$username})" : '';

            return "  Last.fm: Connected{$userStr}";
        }

        return '  Last.fm: Not connected';
    }

    // ---- input ---------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        if ($this->pendingService !== null) {
            return $this->handleConfirmKey($msg);
        }

        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::send(new NavigateBackMsg())];
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
        if ($rune === 't' && $this->pendingService === null) {
            return [$this->with(pendingService: 'trakt', typed: ''), null];
        }
        if ($rune === 'l' && $this->pendingService === null) {
            return [$this->with(pendingService: 'lastfm', typed: ''), null];
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function handleConfirmKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape) {
            return [$this->with(typed: ''), null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'Enter') {
            if ($this->typed === 'disconnect') {
                return $this->doDisconnect($this->pendingService);
            }

            return [$this->with(typed: ''), Cmd::send(ShowToastMsg::error('Type "disconnect" to confirm'))];
        }
        if ($msg->type === KeyType::Char && strlen($this->typed) < 10) {
            return [$this->with(typed: $this->typed . $msg->rune), null];
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function doDisconnect(?string $service): array
    {
        if ($service === null) {
            return [$this->with(typed: ''), null];
        }

        $promise = $service === 'trakt'
            ? $this->admin->disconnectTrakt()
            : $this->admin->disconnectLastfm();

        $new = $this->with(typed: '');
        $serviceName = ucfirst($service);

        return [
            $new,
            Cmd::promise(static fn (): PromiseInterface => $promise->then(
                static fn (string $_): Msg => ShowToastMsg::success("{$serviceName} disconnected"),
                static fn (\Throwable $e): Msg => $e instanceof AuthError
                    ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                    : ShowToastMsg::error($e->getMessage()),
            )),
        ];
    }

    /** @return array{self, ?\Closure} */
    private function refresh(): array
    {
        $next = clone $this;
        $next->loaded = false;
        $next->pendingService = null;
        $next->typed = '';

        return [$next, $this->fetchCmd()];
    }

    // ---- state ---------------------------------------------------------

    private function with(string $pendingService = '', string $typed = ''): self
    {
        $new = clone $this;
        if ($pendingService !== '') {
            $new->pendingService = $pendingService;
        } else {
            $new->pendingService = null;
        }
        $new->typed = $typed;

        return $new;
    }

    /**
     * @param array{trakt: array{connected:bool, username?:?string, configured:bool}, lastfm: array{connected:bool, username?:?string, api_key_set:bool}} $services
     */
    private function withServices(array $services): self
    {
        $new = clone $this;
        $new->services = $services;
        $new->loaded = true;

        return $new;
    }

    private function resizedTo(int $cols, int $rows): self
    {
        $new = clone $this;
        $new->cols = $cols;
        $new->rows = $rows;

        return $new;
    }

    public function crumbLabel(): string
    {
        return 'Services';
    }

    public function withCrumbs(array $trail): static
    {
        $new = clone $this;
        $new->crumbs = $trail;

        return $new;
    }
}
