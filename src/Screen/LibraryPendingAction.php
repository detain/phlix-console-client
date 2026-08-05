<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Dto\Library;

/**
 * A destructive library action that has been armed and is awaiting an inline
 * (y/n or typed) confirmation on the AdminLibrariesScreen's status line.
 * Immutable.
 */
final readonly class LibraryPendingAction
{
    /**
     * @param string             $action   One of: 'delete', 'prune', 'clear-metadata', 'clear-artwork', 'delete-all'
     * @param Library            $library  The library being acted upon
     * @param string             $typed    Accumulated typed characters (for delete-all confirmation)
     */
    public function __construct(
        public string $action,
        public Library $library,
        public string $typed = '',
    ) {
    }

    /**
     * The confirm prompt text for y/n confirmations.
     */
    public function prompt(): string
    {
        $name = $this->library->name === '' ? $this->library->id : $this->library->name;

        $verb = match ($this->action) {
            'delete' => "Delete library '{$name}'? This removes the library and ALL its items.",
            'prune' => "Prune library '{$name}'? This removes items whose files are gone.",
            'clear-metadata' => "Clear metadata for library '{$name}'? Metadata will be re-fetched on next scan.",
            'clear-artwork' => "Clear artwork cache for library '{$name}'? Artwork will be re-downloaded on next match.",
            'delete-all' => "DELETE ALL ITEMS from library '{$name}'? Type 'delete' to confirm: " . $this->typed,
            default => "Confirm action on library '{$name}'?",
        };

        if ($this->action !== 'delete-all') {
            return $verb . ' (y/n)';
        }

        return $verb;
    }

    /**
     * Whether the typed confirmation matches "delete".
     */
    public function isConfirmed(): bool
    {
        return $this->action === 'delete-all' && $this->typed === 'delete';
    }

    /**
     * With an additional typed character.
     */
    public function withTyped(string $char): self
    {
        // Only accumulate up to 6 characters ("delete" = 6 chars)
        $next = $this->typed . $char;

        return new self($this->action, $this->library, mb_strlen($next, 'UTF-8') > 6 ? $this->typed : $next);
    }
}
