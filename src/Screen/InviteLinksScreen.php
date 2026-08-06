<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Hub\HubClient;
use Phlix\Console\Msg\InitMsg;
use Phlix\Console\Msg\InviteLinkCreatedMsg;
use Phlix\Console\Msg\InviteLinksFailedMsg;
use Phlix\Console\Msg\InviteLinksLoadedMsg;
use Phlix\Console\Msg\InviteLinkRevokedMsg;
use Phlix\Console\Route;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg as KeyMessage;
use SugarCraft\Core\Model;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\View;
use SugarCraft\Screen\Breadcrumbed;
use SugarCraft\Screen\Themed;

final class InviteLinksScreen implements Model, Breadcrumbed, Themed
{
    use SubscriptionCapable;

    private ?string $error = null;
    private bool $loading = true;
    private int $selectedIndex = 0;
    /** @var list<array{id:string,code:string,created_at:string,uses:int}> */
    private array $links = [];

    public function __construct(
        private readonly HubClient $hub,
    ) {}

    public function init(): ?\Closure
    {
        return $this->fetchCmd();
    }

    /**
     * @return \Closure
     */
    private function fetchCmd(): \Closure
    {
        $this->loading = true;
        $promise = $this->hub->inviteLinks()->then(
            /** @param list<array<string, mixed>> $links */
            fn (array $links): array => $this->fetchSucceeded($links),
            fn (\Throwable $e): array => $this->fetchFailed($e->getMessage()),
        );

        return fn () => $promise->wait();
    }

    /**
     * @param list<array<string, mixed>> $links
     * @return array{0: self, 1: \Closure|null}
     */
    private function fetchSucceeded(array $links): array
    {
        $this->loading = false;
        $this->links = $links;
        return [$this, null];
    }

    /**
     * @return array{0: self, 1: \Closure|null}
     */
    private function fetchFailed(string $error): array
    {
        $this->loading = false;
        $this->error = $error;
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
            'q', 'Escape' => [$this->back(), null],
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
            /** @param array{id:string,code:string,url:string} $link */
            fn (array $link): InviteLinkCreatedMsg => new InviteLinkCreatedMsg($link),
            fn (\Throwable $e): InviteLinksFailedMsg => new InviteLinksFailedMsg($e->getMessage()),
        );

        return [$this, fn () => $promise->wait()];
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
        $promise = $this->hub->revokeInvite($link['id'])->then(
            fn (): InviteLinkRevokedMsg => new InviteLinkRevokedMsg(),
            fn (\Throwable $e): InviteLinksFailedMsg => new InviteLinksFailedMsg($e->getMessage()),
        );

        return [$this, fn () => $promise->wait()];
    }

    /**
     * @return array{0: self, 1: \Closure|null}
     */
    private function back(): array
    {
        return [new AdminMenuScreen(), null];
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
