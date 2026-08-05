<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Hub\HubClient;
use Phlix\Console\Msg\SharedWithMeActionDoneMsg;
use Phlix\Console\Msg\SharedWithMeFailedMsg;
use Phlix\Console\Msg\SharedWithMeLoadedMsg;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\InitMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\SubscriptionCapable;

final class SharedWithMeScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const HINT = 'a  accept  r  reject  Esc  back';

    /** @var list<array{id:string,title:string,from:string,date:string}> */
    private array $items = [];
    private int $selectedIndex = 0;
    private bool $loading = true;
    private ?string $error = null;

    public function __construct(
        private readonly HubClient $hub,
    ) {}

    public function init(): array
    {
        return $this->fetchCmd();
    }

    private function fetchCmd(): array
    {
        $this->loading = true;
        $this->error = null;
        return $this->hub->sharedWithMe()->then(
            fn (array $items): array => $this->fetchSucceeded($items),
            fn (\Throwable $e): array => $this->fetchFailed($e->getMessage()),
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

    public function update(Msg $msg): array
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
            'q', 'Escape' => $this->back(),
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

    private function back(): array
    {
        return [$this, Cmd::send(new NavigateBackMsg())];
    }

    public function crumbs(): array
    {
        return [
            ['label' => 'Admin', 'screen' => Route::AdminMenu],
            ['label' => 'Shared With Me'],
        ];
    }
}
