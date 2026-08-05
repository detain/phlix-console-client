<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Msg\InitMsg;
use Phlix\Console\Msg\AdminPluginUpdateLoadedMsg;
use Phlix\Console\Msg\FailedMsg;
use Phlix\Console\Msg\KeyMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Route;
use Phlix\Console\Store\PluginsStore;
use React\Promise\PromiseInterface;

/**
 * Plugin update, auto-update toggle, and channel selection.
 *
 * @param plugins The plugins store
 * @param adminClient The admin API client
 */
final readonly class AdminPluginUpdateScreen extends Screen
{
    private ?string $pendingPluginId = null;
    private string $typed = '';
    private string $error = '';

    public function __construct(
        private PluginsStore $plugins,
        private AdminClient $adminClient,
    ) {}

    public function update(mixed $msg): array
    {
        return match (true) {
            $msg instanceof InitMsg => $this->init(),
            $msg instanceof KeyMsg => $this->handleKey($msg),
            default => [$this, null],
        };
    }

    private function init(): array
    {
        return [$this, $this->fetchCmd()];
    }

    private function fetchCmd(): PromiseInterface
    {
        return $this->adminClient->pluginUpdateInfo()
            ->then(
                fn ($info) => new AdminPluginUpdateLoadedMsg($info),
                fn ($e) => new FailedMsg('Failed to load plugin update info: ' . $e->getMessage())
            );
    }

    private function handleKey(KeyMsg $msg): array
    {
        $key = $msg->key;

        if ($key === 'q' || $key === 'Escape') {
            return App::openAdminSection(Route::AdminPlugins);
        }

        if ($key === 'u' && $this->pendingPluginId === null) {
            $this->pendingPluginId = $this->plugins->selectedPluginId() ?? '';
            $this->typed = '';
            return [$this, null];
        }

        if ($this->pendingPluginId !== null) {
            if ($key === 'Enter') {
                if ($this->typed === 'update') {
                    return [$this, $this->doUpdate($this->pendingPluginId)];
                }
                $this->error = 'Type "update" to confirm';
                $this->pendingPluginId = null;
                $this->typed = '';
                return [$this, new ShowToastMsg('error', $this->error)];
            }
            if (strlen($this->typed) < 6) {
                $this->typed .= $key;
            }
            return [$this, null];
        }

        if ($key === 'a') {
            return [$this, $this->doAutoUpdateToggle()];
        }

        if ($key === 'c') {
            return [$this, $this->doChannelChange()];
        }

        return [$this, null];
    }

    private function doUpdate(string $pluginId): PromiseInterface
    {
        $this->pendingPluginId = null;
        $this->typed = '';
        return $this->adminClient->updatePlugin($pluginId)
            ->then(
                fn () => new ShowToastMsg('success', 'Plugin updated'),
                fn ($e) => new ShowToastMsg('error', 'Update failed: ' . $e->getMessage())
            );
    }

    private function doAutoUpdateToggle(): PromiseInterface
    {
        return $this->adminClient->setPluginAutoUpdate(!$this->autoUpdate)
            ->then(
                fn () => new ShowToastMsg('success', 'Auto-update toggled'),
                fn ($e) => new ShowToastMsg('error', 'Failed: ' . $e->getMessage())
            );
    }

    private function doChannelChange(): PromiseInterface
    {
        return $this->adminClient->setPluginChannel($this->channel)
            ->then(
                fn () => new ShowToastMsg('success', 'Channel updated'),
                fn ($e) => new ShowToastMsg('error', 'Failed: ' . $e->getMessage())
            );
    }
}
