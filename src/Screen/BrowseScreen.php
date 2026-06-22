<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Dto\AuthUser;
use Phlix\Console\Ui\Chrome;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;

/**
 * Browse home — a placeholder for Phase 1. The next PR fills this with the
 * library poster rails (Continue Watching + one rail per library). For now it
 * confirms the logged-in user so the auth flow is end-to-end verifiable.
 */
final class BrowseScreen implements Model
{
    use SubscriptionCapable;

    public function __construct(
        public readonly AuthUser $user,
        public readonly int $cols = 80,
        public readonly int $rows = 24,
    ) {
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [new self($this->user, $msg->cols, $msg->rows), null];
        }

        if ($msg instanceof KeyMsg && ($msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && $msg->rune === 'q'))) {
            return [$this, Cmd::quit()];
        }

        return [$this, null];
    }

    public function view(): string
    {
        $name = $this->user->displayName !== '' ? $this->user->displayName : $this->user->username;
        $body = sprintf(
            "Welcome, %s.\n\n  Your libraries will appear here as scrolling poster rails\n  (Continue Watching + one rail per library) in the next step.",
            $name,
        );

        return Chrome::frame('Browse', $body, 'q  quit', $this->cols, $this->rows);
    }
}
