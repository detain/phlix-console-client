<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Hub\HubClient;
use Phlix\Console\Msg\InitMsg;
use Phlix\Console\Msg\InviteLinkCreatedMsg;
use Phlix\Console\Msg\InviteLinksFailedMsg;
use Phlix\Console\Msg\InviteLinksLoadedMsg;
use Phlix\Console\Msg\InviteLinkRevokedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Route;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg as KeyMessage;
use SugarCraft\Core\Model;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\View;
use Phlix\Console\SugarCraft\Screen\Breadcrumbed;
use Phlix\Console\SugarCraft\Screen\Themed;

final class InviteLinksScreen implements Model, Breadcrumbed, Themed
{
    use SubscriptionCapable;

    private int $selectedIndex = 0;
    /** @var list<array<string, mixed>> */
    private array $links = [];

    public function __construct(
        private readonly HubClient $hub,
    ) {
    }

    public function init(): \Closure
    {
        return $this->fetchCmd();
    }

    /**
     * Kick off the hub fetch and hand the Program an async command carrying
     * the promise. Resolution rides the OUTER event loop (the one Program
     * pumps) and comes back through {@see update()} as
     * InviteLinksLoadedMsg/InviteLinksFailedMsg.
     *
     * M-CONSOLE-36: this previously returned a closure that re-entered
     * `Loop::get()->run()` and drained the promise with `Loop::stop()`.
     * Program::scheduleCmd() invokes cmd closures inside that same global
     * loop and StreamSelectLoop has no re-entry guard — the nested drain's
     * stop() cleared the shared running flag, killing the app pump mid-frame.
     *
     * @return \Closure
     */
    private function fetchCmd(): \Closure
    {
        $promise = $this->hub->inviteLinks()->then(
            /** @param list<array<string, mixed>> $links */
            static fn (array $links): Msg => new InviteLinksLoadedMsg($links),
            static fn (\Throwable $e): Msg => new InviteLinksFailedMsg($e->getMessage()),
        );

        return Cmd::promise(static fn (): PromiseInterface => $promise);
    }

    /**
     * @param list<array<string, mixed>> $links
     * @return array{0: self, 1: \Closure|null}
     */
    private function fetchSucceeded(array $links): array
    {
        $this->links = $links;
        return [$this, null];
    }

    /**
     * @return array{0: self, 1: \Closure|null}
     */
    private function fetchFailed(string $error): array
    {
        return [$this, null];
    }

    /**
     * @return array{0: Model, 1: \Closure|null}
     */
    public function update(Msg $msg): array
    {
        return match (true) {
            $msg instanceof InitMsg => [$this, null],
            $msg instanceof KeyMessage => $this->handleKey($msg),
            $msg instanceof InviteLinksLoadedMsg => $this->fetchSucceeded($msg->links),
            $msg instanceof InviteLinksFailedMsg => $this->fetchFailed($msg->message),
            default => [$this, null],
        };
    }

    /**
     * @return array{0: self, 1: \Closure|null}
     */
    private function handleKey(KeyMessage $msg): array
    {
        return match ($msg->rune) {
            'q', 'Escape' => $this->back(),
            'c' => $this->createLink(),
            'r' => $this->revokeSelected(),
            default => [$this, null],
        };
    }

    /**
     * @return array{0: self, 1: \Closure|null}
     */
    private function createLink(): array
    {
        $promise = $this->hub->createInvite('Invitation')->then(
            /** @param string $message */
            static fn (string $message): InviteLinkCreatedMsg => new InviteLinkCreatedMsg([
                'id' => '',
                'code' => '',
                'url' => '',
            ]),
            static fn (\Throwable $e): InviteLinksFailedMsg => new InviteLinksFailedMsg($e->getMessage()),
        );

        return [$this, Cmd::promise(static fn (): PromiseInterface => $promise)];
    }

    /**
     * @return array{0: self, 1: \Closure|null}
     */
    private function revokeSelected(): array
    {
        if ($this->selectedIndex < 0 || $this->selectedIndex >= count($this->links)) {
            return [$this, null];
        }
        $link = $this->links[$this->selectedIndex];
        if (!is_string($link['id'])) {
            return [$this, null];
        }
        $promise = $this->hub->revokeInvite($link['id'])->then(
            static fn (): InviteLinkRevokedMsg => new InviteLinkRevokedMsg(),
            static fn (\Throwable $e): InviteLinksFailedMsg => new InviteLinksFailedMsg($e->getMessage()),
        );

        return [$this, Cmd::promise(static fn (): PromiseInterface => $promise)];
    }

    /**
     * @return array{0: self, 1: \Closure|null}
     */
    private function back(): array
    {
        return [$this, fn () => Cmd::send(new NavigateBackMsg())];
    }

    public function view(): string
    {
        return '';
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }

    // ---- Breadcrumbed ---- //

    public function crumbLabel(): string
    {
        return 'Invite Links';
    }

    public function withCrumbs(array $crumbs): self
    {
        return $this;
    }

    /**
     * @return array<int, array{label: string, screen: mixed}>
     */
    public function crumbs(): array
    {
        $adminScreen = Route::Admin;
        return [
            ['label' => 'Admin', 'screen' => $adminScreen],
            ['label' => 'Invite Links', 'screen' => null],
        ];
    }

    // ---- Themed ---- //

    public function theme(): ?string
    {
        return null;
    }
}
