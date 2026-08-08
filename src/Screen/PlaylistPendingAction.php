<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Dto\MediaItem;

/**
 * A destructive playlist action that has been armed and is awaiting an inline
 * typed confirmation on the PlaylistsScreen's status line.
 * Immutable.
 */
final readonly class PlaylistPendingAction
{
    /**
     * @param string     $action   One of: 'delete'
     * @param MediaItem  $playlist The playlist being acted upon
     * @param string     $typed    Accumulated typed characters (for typed confirmation)
     */
    public function __construct(
        public string $action,
        public MediaItem $playlist,
        public string $typed = '',
    ) {
    }

    /**
     * The confirm prompt text for typed confirmation.
     */
    public function prompt(): string
    {
        $name = $this->playlist->name === '' ? $this->playlist->id : $this->playlist->name;

        $verb = match ($this->action) {
            'delete' => "DELETE playlist '{$name}'? This action cannot be undone. Type 'delete' to confirm: " . $this->typed,
            default => "Confirm action on playlist '{$name}'?",
        };

        return $verb;
    }

    /**
     * Whether the typed confirmation matches "delete".
     */
    public function isConfirmed(): bool
    {
        return $this->action === 'delete' && $this->typed === 'delete';
    }

    /**
     * With an additional typed character.
     */
    public function withTyped(string $char): self
    {
        // Only accumulate up to 6 characters ("delete" = 6 chars)
        $next = $this->typed . $char;

        return new self($this->action, $this->playlist, mb_strlen($next, 'UTF-8') > 6 ? $this->typed : $next);
    }
}
