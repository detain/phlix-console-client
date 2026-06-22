<?php

declare(strict_types=1);

namespace Phlix\Console;

use SugarCraft\Boxer\SugarBoxer;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;

/**
 * Phase-0 root model: a minimal full-window shell (header / body / status)
 * composed with sugar-boxer. It proves the TEA runtime, the layout engine,
 * live resize handling and quit wiring before the real screens (Browse,
 * Library, Detail, Player) land in later phases.
 *
 * Immutable per TEA convention: state changes return a new instance.
 */
final class App implements Model
{
    use SubscriptionCapable;

    public function __construct(
        private readonly int $cols = 80,
        private readonly int $rows = 24,
    ) {
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [new self($msg->cols, $msg->rows), null];
        }

        if ($msg instanceof KeyMsg && $this->isQuit($msg)) {
            return [$this, Cmd::quit()];
        }

        return [$this, null];
    }

    public function view(): string
    {
        $b = SugarBoxer::new();

        $header = $b->leaf(' Phlix  ·  console client')->withMinHeight(1);
        $body = $b->leaf(
            "\n  Phase 0 shell is alive.\n\n" .
            "  Browse · Library · Detail · Player arrive in later phases.\n" .
            "  Press q or Esc to quit."
        )->withBorder(true)->withTitle('Home');
        $status = $b->leaf(sprintf(' %d×%d   q quit', $this->cols, $this->rows))->withMinHeight(1);

        $root = $b->vertical($header, $body, $status);

        return $b->render($root, max(1, $this->cols), max(1, $this->rows));
    }

    private function isQuit(KeyMsg $msg): bool
    {
        if ($msg->type === KeyType::Escape) {
            return true;
        }

        return $msg->type === KeyType::Char
            && ($msg->rune === 'q' || ($msg->ctrl && $msg->rune === 'c'));
    }
}
