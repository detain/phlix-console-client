<?php
declare(strict_types=1);
namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Msg\InitMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Msg\StatsLoadedMsg;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Model;
use SugarCraft\Core\SubscriptionCapable;

/**
 * Stats screen showing server statistics.
 * Replaces the old LibrariesStore-based library row count with real server stats.
 */
final class StatsScreen implements Model
{
    use SubscriptionCapable;

    private bool $loading = false;
    /** @var array<string, mixed> */
    private array $stats = [];

    public function __construct(
        private AdminClient $adminClient,
    ) {}

    public function init(): \Closure
    {
        return $this->fetchCmd();
    }

    /** @return array{self, ?\Closure} */
    public function update(Msg $msg): array
    {
        return match (true) {
            $msg instanceof InitMsg => [$this, $this->fetchCmd()],
            $msg instanceof KeyMsg => $this->handleKey($msg),
            $msg instanceof StatsLoadedMsg => $this->onLoaded($msg),
            default => [$this, null],
        };
    }

    public function view(): string
    {
        if ($this->loading) {
            return "\n\n  Loading stats...\n";
        }

        $out = "\n\n  Server Statistics\n";
        $out .= "  ─────────────────\n\n";

        $playback = $this->stats['playback'] ?? [];
        if (is_array($playback)) {
            $out .= "  Playback:\n";
            /** @var int $activeSessions */
            $activeSessions = $playback['active_sessions'] ?? 0;
            $out .= "    Active: {$activeSessions}\n";
        }

        $storage = $this->stats['storage'] ?? [];
        if (is_array($storage)) {
            $out .= "  Storage:\n";
            /** @var int $usedBytes */
            $usedBytes = $storage['used_bytes'] ?? 0;
            /** @var int $totalBytes */
            $totalBytes = $storage['total_bytes'] ?? 0;
            $out .= "    Used: {$usedBytes}\n";
            $out .= "    Total: {$totalBytes}\n";
        }

        return $out;
    }

    /** @return \Closure */
    private function fetchCmd(): \Closure
    {
        return Cmd::promise(fn (): PromiseInterface => $this->doFetchCmd());
    }

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';

    /** @return PromiseInterface<\SugarCraft\Core\Msg> */
    private function doFetchCmd(): PromiseInterface
    {
        $this->loading = true;
        return $this->adminClient->statsOverview()
            ->then(fn ($stats) => new StatsLoadedMsg($stats))
            ->catch(fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : ShowToastMsg::error('Failed: ' . $e->getMessage()));
    }

    /** @return array{self, null} */
    private function onLoaded(StatsLoadedMsg $msg): array
    {
        $this->loading = false;
        $this->stats = $msg->stats;
        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        $key = $msg->rune;
        if ($key === 'r') {
            return [$this, $this->fetchCmd()];
        }
        return [$this, null];
    }
}