<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\RecommendationsLoadedMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\RecommendationCard;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;

/**
 * Displays personalized "For You" recommendations.
 *
 * Fetches from GET /api/v1/me/recommendations and displays
 * recommendation cards in a scrollable list.
 */
final class RecommendationsScreen implements Model, Teardownable, CapturesSlash, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const HINT = 'Q: Back  ↑↓: Navigate  Enter: Open';
    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LOAD_FAILED = 'Could not load recommendations.';

    /** @var list<RecommendationCard> */
    private array $items = [];
    private int $selectedIndex = 0;
    private bool $loading = true;
    private ?string $error = null;
    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private readonly ApiClient $api,
        private int $cols = 80,
        private int $rows = 24,
    ) {
    }

    public function init(): \Closure
    {
        return Cmd::promise(function (): \React\Promise\PromiseInterface {
            return $this->api->send('GET', '/api/v1/me/recommendations', ['limit' => 20])->then(
                function (array $data): Msg {
                    /** @var list<array<string, mixed>> $recommendations */
                    $recommendations = [];
                    if (isset($data['recommendations']) && is_array($data['recommendations'])) {
                        foreach ($data['recommendations'] as $item) {
                            if (is_array($item)) {
                                $recommendations[] = $item;
                            }
                        }
                    }

                    return new RecommendationsLoadedMsg($recommendations);
                },
                static fn (\Throwable $e): Msg => $e instanceof AuthError
                    ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                    : new \Phlix\Console\Msg\RecommendationsFailedMsg(self::LOAD_FAILED),
            );
        });
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
        if ($msg instanceof RecommendationsLoadedMsg) {
            return [$msg->screenWith($this), null];
        }
        if ($msg instanceof \Phlix\Console\Msg\RecommendationsFailedMsg) {
            return [$this->withError($msg->reason), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame(
            'For You',
            $this->body(),
            self::HINT,
            $this->cols,
            $this->rows,
            $this->crumbs,
            $this->theme(),
        );
    }

    public function teardown(): void
    {
        // Nothing to tear down - no external resources held.
    }

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && ($msg->rune === 'q' || $msg->rune === 'Q'))) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }

        if ($msg->type === KeyType::Up || ($msg->type === KeyType::Char && $msg->rune === 'k')) {
            return $this->selectPrev();
        }

        if ($msg->type === KeyType::Down || ($msg->type === KeyType::Char && $msg->rune === 'j')) {
            return $this->selectNext();
        }

        if ($msg->type === KeyType::Enter) {
            return $this->openSelected();
        }

        return [$this, null];
    }

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
        if ($this->selectedIndex < count($this->items) - 1) {
            return [$this->withSelectedIndex($this->selectedIndex + 1), null];
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function openSelected(): array
    {
        if (!isset($this->items[$this->selectedIndex])) {
            return [$this, null];
        }

        // Future: navigate to the item's detail screen.
        // For now, this is a placeholder.

        return [$this, null];
    }

    private function body(): string
    {
        if ($this->loading) {
            return "\n\n  Loading recommendations…";
        }
        if ($this->error !== null) {
            return "\n\n  {$this->error}";
        }
        if ($this->items === []) {
            return "\n\n  No recommendations yet.\n  Start watching to get personalized suggestions!";
        }

        $cards = [];
        foreach ($this->items as $i => $item) {
            $prefix = $i === $this->selectedIndex ? '▶ ' : '  ';
            $cards[] = $prefix . $item->render();
        }

        return "\n\n" . implode("\n", $cards);
    }

    // ---- clone-mutate ----------------------------------------------------

    /** @param list<RecommendationCard> $items */
    public function withItems(array $items): self
    {
        $next = clone $this;
        $next->items = $items;

        return $next;
    }

    public function withLoading(bool $loading): self
    {
        $next = clone $this;
        $next->loading = $loading;

        return $next;
    }

    public function withError(?string $error): self
    {
        $next = clone $this;
        $next->error = $error;
        $next->loading = false;

        return $next;
    }

    public function withSelectedIndex(int $index): self
    {
        $next = clone $this;
        $next->selectedIndex = $index;

        return $next;
    }

    private function resizedTo(int $cols, int $rows): self
    {
        $next = clone $this;
        $next->cols = $cols;
        $next->rows = $rows;

        return $next;
    }

    /**
     * @param list<string> $trail
     */
    public function withCrumbs(array $trail): static
    {
        $next = clone $this;
        $next->crumbs = $trail;

        return $next;
    }

    // ---- accessors ------------------------------------------------------

    /** @return list<RecommendationCard> */
    public function items(): array
    {
        return $this->items;
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

    public function crumbLabel(): string
    {
        return 'For You';
    }
}
