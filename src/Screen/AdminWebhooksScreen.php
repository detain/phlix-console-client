<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Msg\AdminWebhooksFailedMsg;
use Phlix\Console\Msg\AdminWebhooksLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\Table;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;

/**
 * The admin webhooks management screen: a scrollable list of webhook
 * subscriptions with their URL, events, and enabled status.
 *
 * `r` refetches; Esc/q go back. A fetch failure shows a line plus a retry hint; an auth
 * failure surfaces a session expiry.
 *
 * The client is injected (built locally by the App from its shared ApiClient,
 * so the App holds no AdminClient field). Stable collaborators are readonly;
 * the loaded data + flags are private mutable view state set via clone-mutate
 * (the established screen idiom).
 */
final class AdminWebhooksScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LOAD_FAILED = 'Could not load webhooks.';
    private const HINT = 'r  refresh  Esc  back';

    /** @var list<array<string,mixed>> */
    private array $webhooks = [];
    private int $selectedIndex = 0;
    private bool $loading = true;
    private ?string $error = null;
    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private readonly AdminClient $admin,
        private int $cols = 80,
        private int $rows = 24,
    ) {
    }

    public function init(): \Closure
    {
        return $this->fetchCmd();
    }

    private function fetchCmd(): \Closure
    {
        return Cmd::promise(fn (): \React\Promise\PromiseInterface => $this->admin->webhooks()
            ->then(
                static fn (array $webhooks): AdminWebhooksLoadedMsg => new AdminWebhooksLoadedMsg($webhooks),
                static fn (\Throwable $e): Msg => $e instanceof AuthError
                    ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                    : new AdminWebhooksFailedMsg(self::LOAD_FAILED),
            ));
    }

    /** @return array{self, ?\Closure} */
    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [$this->resizedTo($msg->cols, $msg->rows), null];
        }
        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }
        if ($msg instanceof AdminWebhooksLoadedMsg) {
            $next = clone $this;
            $next->webhooks = $msg->webhooks;
            $next->loading = false;
            $next->error = null;
            $next->selectedIndex = 0;

            return [$next, null];
        }
        if ($msg instanceof AdminWebhooksFailedMsg) {
            return [$this->withError($msg->message), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame(
            'Admin · Webhooks',
            $this->body(),
            self::HINT,
            $this->cols,
            $this->rows,
            $this->crumbs,
            $this->theme(),
        );
    }

    // ---- input ---------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'r') {
            return [$this->reloading(), $this->fetchCmd()];
        }
        if ($msg->type === KeyType::Up || ($msg->type === KeyType::Char && $msg->rune === 'k')) {
            return $this->selectPrev();
        }
        if ($msg->type === KeyType::Down || ($msg->type === KeyType::Char && $msg->rune === 'j')) {
            return $this->selectNext();
        }

        return [$this, null];
    }

    // ---- selection -----------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function selectPrev(): array
    {
        if ($this->selectedIndex > 0) {
            return [$this->withSelectedIndex($this->selectedIndex - 1), null];
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function selectNext(): array
    {
        if ($this->selectedIndex < count($this->webhooks) - 1) {
            return [$this->withSelectedIndex($this->selectedIndex + 1), null];
        }

        return [$this, null];
    }

    private function withSelectedIndex(int $index): self
    {
        $next = clone $this;
        $next->selectedIndex = $index;

        return $next;
    }

    // ---- error ---------------------------------------------------------

    private function withError(string $reason): self
    {
        $next = clone $this;
        $next->error = $reason;
        $next->loading = false;

        return $next;
    }

    private function reloading(): self
    {
        $next = clone $this;
        $next->loading = true;
        $next->error = null;

        return $next;
    }

    // ---- rendering -----------------------------------------------------

    private function body(): string
    {
        if ($this->loading) {
            return "\n\n  Loading webhooks…";
        }
        if ($this->error !== null) {
            return "\n\n  {$this->error}\n  Press r to retry.";
        }
        if ($this->webhooks === []) {
            return "\n\n  No webhooks configured.";
        }

        return "\n" . Table::render(
            [
                ['title' => 'URL', 'width' => 0],
                ['title' => 'Events', 'width' => 20],
                ['title' => 'Enabled', 'width' => 8],
            ],
            $this->tableRows(),
            $this->selectedIndex,
            $this->cols - 4,
            Chrome::bodyHeight($this->rows),
        );
    }

    /** @return list<array{string}> */
    private function tableRows(): array
    {
        $rows = [];
        foreach ($this->webhooks as $webhook) {
            $url = is_string($webhook['url'] ?? null) ? $webhook['url'] : '—';
            $events = $this->formatEvents($webhook['events'] ?? null);
            $enabled = !empty($webhook['enabled']) ? 'Yes' : 'No';

            $rows[] = [
                $this->truncate($url, $this->cols - 40),
                $events,
                $enabled,
            ];
        }

        return $rows;
    }

    /** @param mixed $events */
    private function formatEvents($events): string
    {
        if (!is_array($events) || $events === []) {
            return '—';
        }

        /** @var list<scalar|null> $eventValues */
        $eventValues = $events;
        $labels = array_slice(
            array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '?', $eventValues),
            0,
            3,
        );
        $suffix = count($events) > 3 ? ' …' : '';

        return implode(', ', $labels) . $suffix;
    }

    private function truncate(string $text, int $maxWidth): string
    {
        if ($maxWidth <= 0) {
            return '';
        }
        if (mb_strlen($text, 'UTF-8') <= $maxWidth) {
            return $text;
        }

        return mb_substr($text, 0, $maxWidth - 1, 'UTF-8') . '…';
    }

    // ---- clone-mutate --------------------------------------------------

    private function resizedTo(int $cols, int $rows): self
    {
        $next = clone $this;
        $next->cols = $cols;
        $next->rows = $rows;

        return $next;
    }

    // ---- breadcrumb ----------------------------------------------------

    public function crumbLabel(): string
    {
        return 'Webhooks';
    }

    /** @param list<string> $trail */
    public function withCrumbs(array $trail): static
    {
        $next = clone $this;
        $next->crumbs = $trail;

        return $next;
    }

    // ---- accessors -----------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function webhooks(): array
    {
        return $this->webhooks;
    }

    public function selectedIndex(): int
    {
        return $this->selectedIndex;
    }

    public function isLoading(): bool
    {
        return $this->loading;
    }

    public function error(): ?string
    {
        return $this->error;
    }
}
