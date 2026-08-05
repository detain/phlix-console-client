<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Config\ServerEntry;
use Phlix\Console\Msg\AddServerMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenServersMsg;
use Phlix\Console\Msg\RemoveServerMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Msg\SwitchServerMsg;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\Theme;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\KeyType;

use function trim;

/**
 * Server list screen — shows all configured servers, highlights the active one,
 * and lets the user:
 *   - 'h'   import servers from the hub via myServers()
 *   - 'a'   add a server manually (URL + label, via form that submits AddServerMsg)
 *   - 'r'   remove the selected server (submits RemoveServerMsg)
 *   - Enter  switch to the selected server (submits SwitchServerMsg)
 *   - Esc    pop back
 *
 * The hub-imported servers carry a hub_id; locally-added ones have null hub_id.
 */
final class ServersScreen implements Model, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    /** @param array<ServerEntry> $servers */
    public function __construct(
        public readonly array $servers,
        public readonly ?string $activeServerId,
        public readonly int $selectedIndex = 0,
        public readonly bool $loading = false,
        public readonly ?string $error = null,
        public readonly int $cols = 80,
        public readonly int $rows = 24,
    ) {
    }

    /**
     * @param array<ServerEntry> $servers
     */
    public static function create(
        array $servers,
        ?string $activeServerId,
        int $cols = 80,
        int $rows = 24,
    ): self {
        return new self($servers, $activeServerId, 0, false, null, $cols, $rows);
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [new self(
                $this->servers,
                $this->activeServerId,
                $this->selectedIndex,
                $this->loading,
                $this->error,
                $msg->cols,
                $msg->rows,
            ), null];
        }

        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        // Esc → navigate back
        if ($msg->type === KeyType::Escape) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }

        // Arrow keys navigate the list
        if ($msg->type === KeyType::Up) {
            $newIndex = $this->selectedIndex > 0 ? $this->selectedIndex - 1 : count($this->servers) - 1;

            return [new self(
                $this->servers,
                $this->activeServerId,
                $newIndex,
                $this->loading,
                $this->error,
                $this->cols,
                $this->rows,
            ), null];
        }

        if ($msg->type === KeyType::Down) {
            $newIndex = ($this->selectedIndex + 1) % max(1, count($this->servers));

            return [new self(
                $this->servers,
                $this->activeServerId,
                $newIndex,
                $this->loading,
                $this->error,
                $this->cols,
                $this->rows,
            ), null];
        }

        // Enter → switch to selected server
        if ($msg->type === KeyType::Enter) {
            if (isset($this->servers[$this->selectedIndex])) {
                $server = $this->servers[$this->selectedIndex];

                return [new self(
                    $this->servers,
                    $this->activeServerId,
                    $this->selectedIndex,
                    $this->loading,
                    $this->error,
                    $this->cols,
                    $this->rows,
                ), Cmd::batch(
                    Cmd::send(new SwitchServerMsg($server->id)),
                    Cmd::send(new NavigateBackMsg()),
                )];
            }

            return [$this, null];
        }

        // 'h' → import from hub (loading state, App fetches and sends back updated list)
        if ($msg->type === KeyType::Char && $msg->rune === 'h' && !$msg->ctrl) {
            return [new self(
                $this->servers,
                $this->activeServerId,
                $this->selectedIndex,
                true,
                null,
                $this->cols,
                $this->rows,
            ), Cmd::send(new OpenServersMsg())];
        }

        // 'a' → add server manually — signal via toast (the App provides a way via palette/other screen)
        if ($msg->type === KeyType::Char && $msg->rune === 'a' && !$msg->ctrl) {
            return [$this, Cmd::send(ShowToastMsg::info('Use the palette "Add server" action to add manually.'))];
        }

        // 'r' → remove selected server (only if not the active one, and only if there are servers)
        if ($msg->type === KeyType::Char && $msg->rune === 'r' && !$msg->ctrl) {
            if (isset($this->servers[$this->selectedIndex])) {
                $server = $this->servers[$this->selectedIndex];

                // Don't allow removing the active server
                if ($server->id === $this->activeServerId) {
                    return [new self(
                        $this->servers,
                        $this->activeServerId,
                        $this->selectedIndex,
                        false,
                        'Cannot remove the active server.',
                        $this->cols,
                        $this->rows,
                    ), null];
                }

                return [new self(
                    $this->servers,
                    $this->activeServerId,
                    $this->selectedIndex,
                    false,
                    null,
                    $this->cols,
                    $this->rows,
                ), Cmd::send(new RemoveServerMsg($server->id))];
            }

            return [$this, null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        $lines = ['Servers', ''];

        if ($this->loading) {
            $lines[] = '  Loading from hub...';
            $lines[] = '';
        }

        if ($this->error !== null) {
            $lines[] = '  ' . $this->error;
            $lines[] = '';
        }

        if ($this->servers === []) {
            $lines[] = '  No servers configured.';
            $lines[] = '  Press h to import from hub, or use the palette to add one.';
            $lines[] = '';
        } else {
            foreach ($this->servers as $index => $server) {
                $prefix = $index === $this->selectedIndex ? '>' : ' ';
                $active = $server->id === $this->activeServerId ? ' [active]' : '';
                $hub = $server->hubId !== null ? ' [hub]' : '';

                $lines[] = sprintf(
                    '%s %s%s%s  (%s)',
                    $prefix,
                    $server->label,
                    $active,
                    $hub,
                    $server->url,
                );
            }
            $lines[] = '';
        }

        $body = implode("\n", $lines);

        return Chrome::frame(
            'Servers',
            $body,
            'Enter  switch      h  import from hub      r  remove      Esc  back',
            $this->cols,
            $this->rows,
            theme: $this->theme(),
        );
    }
}
