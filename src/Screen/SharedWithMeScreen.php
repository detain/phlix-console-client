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
     * @return \Closure
     */
    private function fetchCmd(): \Closure
    {
        $promise = $this->hub->sharedWithMe()->then(
            /** @param list<array<string, mixed>> $items */
            fn (array $items): array => $this->fetchSucceeded($items),
            fn (\Throwable $e): array => $this->fetchFailed($e->getMessage()),
        );

        return function () use ($promise): array {
            $loop = \React\EventLoop\Loop::get();
            $result = null;
            $exception = null;
            $promise->then(
                static function ($v) use (&$result, $loop): void {
                    $result = $v;
                    $loop->stop();
                },
                static function (\Throwable $e) use (&$exception, $loop): void {
                    $exception = $e;
                    $loop->stop();
                }
            );
            $loop->run();
            if ($exception !== null) {
                throw $exception;
            }
            /** @var array{0: self, 1: \Closure|null} */
            return $result;
        };
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
            fn (): SharedWithMeActionDoneMsg => new SharedWithMeActionDoneMsg('accepted'),
            fn (\Throwable $e): SharedWithMeFailedMsg => new SharedWithMeFailedMsg($e->getMessage()),
        );

        return [$this, function () use ($promise): SharedWithMeActionDoneMsg|SharedWithMeFailedMsg {
            $loop = \React\EventLoop\Loop::get();
            $result = null;
            $exception = null;
            $promise->then(
                static function ($v) use (&$result, $loop): void {
                    $result = $v;
                    $loop->stop();
                },
                static function (\Throwable $e) use (&$exception, $loop): void {
                    $exception = $e;
                    $loop->stop();
                }
            );
            $loop->run();
            if ($exception !== null) {
                throw $exception;
            }
            /** @var SharedWithMeActionDoneMsg|SharedWithMeFailedMsg */
            return $result;
        }];
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
            fn (): SharedWithMeActionDoneMsg => new SharedWithMeActionDoneMsg('rejected'),
            fn (\Throwable $e): SharedWithMeFailedMsg => new SharedWithMeFailedMsg($e->getMessage()),
        );

        return [$this, function () use ($promise): SharedWithMeActionDoneMsg|SharedWithMeFailedMsg {
            $loop = \React\EventLoop\Loop::get();
            $result = null;
            $exception = null;
            $promise->then(
                static function ($v) use (&$result, $loop): void {
                    $result = $v;
                    $loop->stop();
                },
                static function (\Throwable $e) use (&$exception, $loop): void {
                    $exception = $e;
                    $loop->stop();
                }
            );
            $loop->run();
            if ($exception !== null) {
                throw $exception;
            }
            /** @var SharedWithMeActionDoneMsg|SharedWithMeFailedMsg */
            return $result;
        }];
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
