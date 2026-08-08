<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\I18n\Lang;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenDetailMsg;
use Phlix\Console\Msg\RecommendationDismissFailedMsg;
use Phlix\Console\Msg\RecommendationDismissedMsg;
use Phlix\Console\Msg\RecommendationsLoadedMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\RecommendationCard;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Model;
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

    private const SESSION_EXPIRED_KEY = 'recommendations.session_expired';
    private const LOAD_FAILED_KEY = 'recommendations.load_failed';

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
                    ? new SessionExpiredMsg(Lang::t(self::SESSION_EXPIRED_KEY))
                    : new \Phlix\Console\Msg\RecommendationsFailedMsg(Lang::t(self::LOAD_FAILED_KEY)),
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
        if ($msg instanceof RecommendationDismissedMsg) {
            return [$msg->screenWith($this), null];
        }
        if ($msg instanceof RecommendationDismissFailedMsg) {
            // Non-fatal: just show the error and keep the current view.
            return [$this->withError($msg->reason), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame(
            Lang::t('recommendations.title'),
            $this->body(),
            Lang::t('recommendations.hint'),
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
        if (
            $msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && ($msg->rune === 'q' || $msg->rune === 'Q'))
        ) {
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

        if ($msg->type === KeyType::Char && ($msg->rune === 'x' || $msg->rune === 'X')) {
            return $this->dismissSelected();
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

        $item = $this->items[$this->selectedIndex];

        return [$this, Cmd::batch(
            Cmd::send(new NavigateBackMsg()),
            Cmd::send(new OpenDetailMsg($item->id(), $item->title())),
        )];
    }

    /** @return array{self, ?\Closure} */
    private function dismissSelected(): array
    {
        if (!isset($this->items[$this->selectedIndex])) {
            return [$this, null];
        }

        $item = $this->items[$this->selectedIndex];

        return [$this, Cmd::promise(function () use ($item): \React\Promise\PromiseInterface {
            return $this->api->dismissRecommendation($item->id())->then(
                static function (bool $_) use ($item): \Phlix\Console\Msg\RecommendationDismissedMsg {
                    return new RecommendationDismissedMsg($item->id());
                },
                static function (\Throwable $e): \Phlix\Console\Msg\RecommendationDismissFailedMsg {
                    return new RecommendationDismissFailedMsg(
                        $e instanceof AuthError
                            ? Lang::t(self::SESSION_EXPIRED_KEY)
                            : Lang::t('recommendations.dismiss_failed'),
                    );
                },
            );
        })];
    }

    private function body(): string
    {
        if ($this->loading) {
            return "\n\n  " . Lang::t('recommendations.loading');
        }
        if ($this->error !== null) {
            return "\n\n  {$this->error}";
        }
        if ($this->items === []) {
            return "\n\n  " . Lang::t('recommendations.empty');
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
        return Lang::t('recommendations.crumb');
    }
}
