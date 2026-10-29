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
use React\Promise\PromiseInterface;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg as KeyMessage;
use SugarCraft\Core\Model;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\View;
use Phlix\Console\SugarCraft\Screen\Breadcrumbed;
use Phlix\Console\SugarCraft\Screen\Themed;

final class SharedWithMeScreen implements Model, Breadcrumbed, Themed
{
    use SubscriptionCapable;

    /** @var list<array<string, mixed>> */
    private array $items = [];
    private int $selectedIndex = 0;

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
     * SharedWithMeLoadedMsg/SharedWithMeFailedMsg.
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
        $promise = $this->hub->sharedWithMe()->then(
            /** @param list<array<string, mixed>> $items */
            static fn (array $items): Msg => new SharedWithMeLoadedMsg($items),
            static fn (\Throwable $e): Msg => new SharedWithMeFailedMsg($e->getMessage()),
        );

        return Cmd::promise(static fn (): PromiseInterface => $promise);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{0: self, 1: \Closure|null}
     */
    private function fetchSucceeded(array $items): array
    {
        $this->items = $items;
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
        /** @var string $id */
        $id = $item['id'];
        $promise = $this->hub->acceptShare($id)->then(
            static fn (): SharedWithMeActionDoneMsg => new SharedWithMeActionDoneMsg('accepted'),
            static fn (\Throwable $e): SharedWithMeFailedMsg => new SharedWithMeFailedMsg($e->getMessage()),
        );

        return [$this, Cmd::promise(static fn (): PromiseInterface => $promise)];
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
        /** @var string $id */
        $id = $item['id'];
        $promise = $this->hub->rejectShare($id)->then(
            static fn (): SharedWithMeActionDoneMsg => new SharedWithMeActionDoneMsg('rejected'),
            static fn (\Throwable $e): SharedWithMeFailedMsg => new SharedWithMeFailedMsg($e->getMessage()),
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
