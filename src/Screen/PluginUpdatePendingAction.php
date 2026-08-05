<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

/**
 * An armed plugin-update action awaiting typed confirmation on the
 * AdminPluginUpdateScreen's status line. Immutable.
 */
final readonly class PluginUpdatePendingAction
{
    /**
     * @param int  $updateCount Number of plugins with available updates
     * @param string $typed     Accumulated typed characters
     */
    public function __construct(
        public int $updateCount,
        public string $typed = '',
    ) {
    }

    /**
     * The confirm prompt text for the typed confirmation.
     */
    public function prompt(): string
    {
        $count = $this->updateCount;
        $label = $count === 1 ? '1 plugin has' : "{$count} plugins have";

        return "{$label} updates available. Applying updates can reset plugin settings. Type 'update' to confirm: {$this->typed}";
    }

    /**
     * Whether the typed confirmation matches "update" exactly.
     */
    public function isConfirmed(): bool
    {
        return $this->typed === 'update';
    }

    /**
     * With an additional typed character appended.
     */
    public function withTyped(string $char): self
    {
        $next = $this->typed . $char;
        // Cap at 6 ("update" = 6 chars)
        if (mb_strlen($next, 'UTF-8') > 6) {
            return $this;
        }

        return new self($this->updateCount, $next);
    }
}
