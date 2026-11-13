<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\I18n\Lang;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\KeyReference;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;

/**
 * The `?` key-reference overlay screen [CON-17]: a read-only, scrollable
 * rendering of {@see KeyReference}. Esc / q / ? close it.
 */
final class KeyHelpScreen implements Model, Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;
    use ResizesTo;

    private int $offset = 0;

    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(private int $cols = 80, private int $rows = 24)
    {
    }

    public function init(): ?\Closure
    {
        // Static content: nothing to fetch.
        $initial = null;

        return $initial;
    }

    /** @return array{self, ?\Closure} */
    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [$this->resizedTo($msg->cols, $msg->rows), null];
        }
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }
        if (
            $msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && !$msg->ctrl && !$msg->alt && ($msg->rune === 'q' || $msg->rune === '?'))
        ) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }

        $page = max(1, Chrome::bodyHeight($this->rows) - 1);
        $delta = match (true) {
            $msg->type === KeyType::Up || ($msg->type === KeyType::Char && $msg->rune === 'k') => -1,
            $msg->type === KeyType::Down || ($msg->type === KeyType::Char && $msg->rune === 'j') => 1,
            $msg->type === KeyType::PageUp => -$page,
            $msg->type === KeyType::PageDown => $page,
            default => 0,
        };
        if ($delta === 0) {
            return [$this, null];
        }

        $next = clone $this;
        $next->offset = $this->clampOffset($this->offset + $delta);

        return [$next, null];
    }

    public function view(): string
    {
        $lines = KeyReference::lines();
        $height = max(1, Chrome::bodyHeight($this->rows) - 1);
        $slice = array_slice($lines, $this->clampOffset($this->offset), $height);

        return Chrome::frame(
            Lang::t('keys.title'),
            "\n" . implode("\n", $slice),
            Lang::t('keys.hint'),
            $this->cols,
            $this->rows,
            $this->crumbs,
            $this->theme(),
        );
    }

    public function crumbLabel(): string
    {
        return Lang::t('keys.title');
    }

    /** @param list<string> $trail */
    public function withCrumbs(array $trail): static
    {
        return $this->with(crumbs: $trail);
    }

    /** @param list<string> $crumbs */
    private function with(array $crumbs): static
    {
        $next = clone $this;
        $next->crumbs = $crumbs;

        return $next;
    }

    private function clampOffset(int $offset): int
    {
        // One body line is the leading blank of view().
        $max = max(0, count(KeyReference::lines()) - max(1, Chrome::bodyHeight($this->rows) - 1));

        return max(0, min($offset, $max));
    }
}
