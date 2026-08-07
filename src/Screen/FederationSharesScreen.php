<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Ui\Chrome;
use SugarCraft\Core\Msg;
use SugarCraft\Core\SubscriptionCapable;

/**
 * Shows outgoing federation library shares.
 */
final class FederationSharesScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    /** @var list<string> */
    private array $crumbs = [];
    private int $cols = 80;
    private int $rows = 24;

    public function __construct() {}

    public function init(): ?\Closure
    {
        return null;
    }

    /** @return array{self, ?\Closure} */
    public function update(Msg $msg): array
    {
        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame('Federation Shares', $this->body(), '', $this->cols, $this->rows, $this->crumbs, $this->theme());
    }

    private function body(): string
    {
        return '';
    }

    public function crumbLabel(): string
    {
        return 'Federation Shares';
    }

    /** @param list<string> $trail */
    public function withCrumbs(array $trail): static
    {
        $next = clone $this;
        $next->crumbs = $trail;
        return $next;
    }
}
