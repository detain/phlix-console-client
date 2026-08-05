<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Hub\HubClient;
use Phlix\Console\Msg\SharedWithMeActionDoneMsg;
use Phlix\Console\Msg\SharedWithMeFailedMsg;
use Phlix\Console\Msg\SharedWithMeLoadedMsg;
use SugarCraft\Core\Msg\InitMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Screen\Breadcrumbed;
use SugarCraft\Screen\Screen;
use SugarCraft\Screen\Themed;
use SugarCraft\Screen\ThemedScreen;
use SugarCraft\Subscription\SubscriptionCapable;

final readonly class SharedWithMeScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private ?string $error = null;
    private bool $loading = true;
    private int $selectedIndex = 0;
    /** @var list<array{id:string,title:string,from:string,date:string}> */
    private array $items = [];

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
        return $this->hub->sharedWithMe()->then(
            fn (array $items): array => [new SharedWithMeLoadedMsg($items), null],
            fn (\Throwable $e): array => [new SharedWithMeFailedMsg($e->getMessage()), null],
        )->wait();
    }

    private function fetchSucceeded(array $items): array
    {
        $this->loading = false;
        $this->items = $items;
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
            $msg instanceof SharedWithMeLoadedMsg => $this->fetchSucceeded($msg->items),
            $msg instanceof SharedWithMeFailedMsg => $this->fetchFailed($msg->error),
            default => [$this, null],
        };
    }

    private function handleKey(KeyMsg $msg): array
    {
        return match ($msg->rune) {
            'q', 'Escape' => [$this->back(), null],
            'a' => $this->acceptSelected(),
            'r' => $this->rejectSelected(),
            default => [$this, null],
        };
    }

    private function acceptSelected(): array
    {
        if ($this->selectedIndex < 0 || $this->selectedIndex >= count($this->items)) {
            return [$this, null];
        }
        $item = $this->items[$this->selectedIndex];
        return $this->hub->acceptShare($item['id'])->then(
            fn () => [new SharedWithMeActionDoneMsg('accepted'), null],
            fn (\Throwable $e) => [new SharedWithMeFailedMsg($e->getMessage()), null],
        )->wait();
    }

    private function rejectSelected(): array
    {
        if ($this->selectedIndex < 0 || $this->selectedIndex >= count($this->items)) {
            return [$this, null];
        }
        $item = $this->items[$this->selectedIndex];
        return $this->hub->rejectShare($item['id'])->then(
            fn () => [new SharedWithMeActionDoneMsg('rejected'), null],
            fn (\Throwable $e) => [new SharedWithMeFailedMsg($e->getMessage()), null],
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
            ['label' => 'Shared With Me'],
        ];
    }
}
