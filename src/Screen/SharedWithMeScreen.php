<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Hub\HubClient;
use Phlix\Console\Msg\InitMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SharedWithMeActionDoneMsg;
use Phlix\Console\Msg\SharedWithMeFailedMsg;
use Phlix\Console\Msg\SharedWithMeLoadedMsg;
use Phlix\Console\Route;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg as KeyMessage;
use SugarCraft\Core\Model;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\View;
use SugarCraft\Screen\Breadcrumbed;
use SugarCraft\Screen\Themed;

final class SharedWithMeScreen implements Model, Breadcrumbed, Themed
{
    use SubscriptionCapable;

    /** @var list<array{id:string,title:string,from:string,date:string}> */
    private array $items = [];
    private int $selectedIndex = 0;
    private bool $loading = true;
    private ?string $error = null;

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
        $this->error = null;
        $promise = $this->hub->sharedWithMe()->then(
            /** @param list<array<string, mixed>> $items */
            fn (array $items): array => $this->fetchSucceeded($items),
            fn (\Throwable $e): array => $this->fetchFailed($e->getMessage()),
        );

        return fn () => $promise->wait();
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{0: self, 1: \Closure|null}
     */
    private function fetchSucceeded(array $items): array
    {
        $this->loading = false;
        $this->items = $items;
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
            $msg instanceof SharedWithMeLoadedMsg => $this->fetchSucceeded($msg->shares),
            $msg instanceof SharedWithMeFailedMsg => $this->fetchFailed($msg->message),
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
            'a' => $this->acceptSelected(),
            'r' => $this->rejectSelected(),
            default => [$this, null],
        };
    }

    /**
     * @return array{0: self, 1: \Closure|null}
     */
    private function acceptSelected(): array
    {
        if ($this->selectedIndex < 0 || $this->selectedIndex >= count($this->items)) {
            return [$this, null];
        }
        $item = $this->items[$this->selectedIndex];
        $promise = $this->hub->acceptShare($item['id'])->then(
            fn (): SharedWithMeActionDoneMsg => new SharedWithMeActionDoneMsg('accepted'),
            fn (\Throwable $e): SharedWithMeFailedMsg => new SharedWithMeFailedMsg($e->getMessage()),
        );

        return [$this, fn () => $promise->wait()];
    }

    /**
     * @return array{0: self, 1: \Closure|null}
     */
    private function rejectSelected(): array
    {
        if ($this->selectedIndex < 0 || $this->selectedIndex >= count($this->items)) {
            return [$this, null];
        }
        $item = $this->items[$this->selectedIndex];
        $promise = $this->hub->rejectShare($item['id'])->then(
            fn (): SharedWithMeActionDoneMsg => new SharedWithMeActionDoneMsg('rejected'),
            fn (\Throwable $e): SharedWithMeFailedMsg => new SharedWithMeFailedMsg($e->getMessage()),
        );

        return [$this, fn () => $promise->wait()];
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
        return 'Shared With Me';
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
            ['label' => 'Shared With Me', 'screen' => null],
        ];
    }

    // ---- Themed ---- //

    public function theme(): ?string
    {
        return null;
    }
}
