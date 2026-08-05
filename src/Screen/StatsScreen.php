<?php
declare(strict_types=1);
namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Msg\InitMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Msg\StatsLoadedMsg;
use Phlix\Console\Store\LibrariesStore;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * Stats screen showing server statistics.
 * Replaces the old LibrariesStore-based library row count with real server stats.
 */
final class StatsScreen
{
    private bool $loading = false;
    private string $error = '';
    /** @var array{playback:?array|null, storage:?array|null, top_media:?list<array>|null, top_users:?list<array>|null} */
    private array $stats = [
        'playback' => null,
        'storage' => null,
        'top_media' => null,
        'top_users' => null,
    ];

    public function __construct(
        private AdminClient $adminClient,
        private LibrariesStore $libraries,
    ) {}

    public function init(): \Closure
    {
        return fn (): PromiseInterface => $this->fetchCmd();
    }

    /** @return array{StatsScreen, PromiseInterface<\SugarCraft\Core\Msg>|null} */
    public function update(mixed $msg): array
    {
        return match (true) {
            $msg instanceof InitMsg => [$this, $this->fetchCmd()],
            $msg instanceof KeyMsg => $this->handleKey($msg),
            $msg instanceof StatsLoadedMsg => $this->onLoaded($msg),
            default => [$this, null],
        };
    }

    /** @return PromiseInterface<\SugarCraft\Core\Msg> */
    private function fetchCmd(): PromiseInterface
    {
        $this->loading = true;
        return $this->adminClient->statsOverview()
            ->then(fn ($stats) => new StatsLoadedMsg($stats))
            ->catch(fn ($e) => ShowToastMsg::error('Failed: ' . $e->getMessage()));
    }

    /** @return array{StatsScreen, null} */
    private function onLoaded(StatsLoadedMsg $msg): array
    {
        $this->loading = false;
        $this->stats = $msg->stats;
        return [$this, null];
    }

    /** @return array{StatsScreen, PromiseInterface<\SugarCraft\Core\Msg>|null} */
    private function handleKey(KeyMsg $msg): array
    {
        $key = $msg->rune;
        if ($key === 'r') {
            return [$this, $this->fetchCmd()];
        }
        return [$this, null];
    }
}