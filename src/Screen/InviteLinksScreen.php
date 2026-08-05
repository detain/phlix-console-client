<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Hub\HubClient;
use Phlix\Console\Msg\InviteLinksLoadedMsg;
use Phlix\Console\Msg\InviteLinksFailedMsg;
use Phlix\Console\Msg\InviteLinkCreatedMsg;
use Phlix\Console\Msg\InviteLinkRevokedMsg;
use SugarCraft\Core\Msg\InitMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Screen\Breadcrumbed;
use SugarCraft\Screen\Screen;
use SugarCraft\Screen\Themed;
use SugarCraft\Screen\ThemedScreen;
use SugarCraft\Subscription\SubscriptionCapable;

final readonly class InviteLinksScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private ?string $error = null;
    private bool $loading = true;
    private int $selectedIndex = 0;
    /** @var list<array{id:string,code:string,created_at:string,uses:int}> */
    private array $links = [];

    public function __construct(
        private HubClient $hub,
    ) {}

    public function init(): array
    {
        return $this->fetchCmd();
    }

    private function fetchCmd(): array
    {
        $this->loading = true;
        return $this->hub->inviteLinks()->then(
            fn (array $links): array => [new InviteLinksLoadedMsg($links), null],
            fn (\Throwable $e): array => [new InviteLinksFailedMsg($e->getMessage()), null],
        )->wait();
    }

    private function fetchSucceeded(array $links): array
    {
        $this->loading = false;
        $this->links = $links;
        return $this->view();
    }

    private function fetchFailed(string $error): array
    {
        $this->loading = false;
        $this->error = $error;
        return $this->view();
    }

    public function update(\SugarCraft\Core\Msg\Msg $msg): array
    {
        return match (true) {
            $msg instanceof InitMsg => [$this, null],
            $msg instanceof KeyMsg => $this->handleKey($msg),
            $msg instanceof InviteLinksLoadedMsg => $this->fetchSucceeded($msg->links),
            $msg instanceof InviteLinksFailedMsg => $this->fetchFailed($msg->error),
            default => [$this, null],
        };
    }

    private function handleKey(KeyMsg $msg): array
    {
        return match ($msg->rune) {
            'q', 'Escape' => [$this->back(), null],
            'c' => $this->createLink(),
            'r' => $this->revokeSelected(),
            default => [$this, null],
        };
    }

    private function createLink(): array
    {
        return $this->hub->createInvite()->then(
            fn (array $link) => [new InviteLinkCreatedMsg($link), null],
            fn (\Throwable $e) => [new InviteLinksFailedMsg($e->getMessage()), null],
        )->wait();
    }

    private function revokeSelected(): array
    {
        if ($this->selectedIndex < 0 || $this->selectedIndex >= count($this->links)) {
            return [$this, null];
        }
        $link = $this->links[$this->selectedIndex];
        return $this->hub->revokeInvite($link['id'])->then(
            fn () => [new InviteLinkRevokedMsg(), null],
            fn (\Throwable $e) => [new InviteLinksFailedMsg($e->getMessage()), null],
        )->wait();
    }

    private function back(): Screen
    {
        return new AdminMenuScreen();
    }

    public function crumbs(): array
    {
        return [
            ['label' => 'Admin', 'screen' => Route::AdminMenu],
            ['label' => 'Invite Links'],
        ];
    }
}
