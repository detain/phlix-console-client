<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Dto\MediaItem;

/**
 * Tracks the typed "delete" confirmation for a collection deletion.
 */
final class CollectionPendingAction
{
    private string $typed = '';

    public function __construct(
        private readonly string $action,
        private readonly MediaItem $collection,
    ) {
    }

    public function withTyped(string $rune): self
    {
        $next = clone $this;
        $next->typed .= $rune;

        return $next;
    }

    public function isConfirmed(): bool
    {
        return $this->typed === 'delete';
    }

    public function getCollection(): MediaItem
    {
        return $this->collection;
    }

    public function prompt(): string
    {
        $name = $this->collection->name === '' ? $this->collection->id : $this->collection->name;

        // [review console__writer CONSOLIDATED M19] the sentence is written
        // per action like the PlaylistPendingAction sibling: the old
        // "{$action}ing" suffix built "deleteing" out of the only action
        // that exists. The echo of $typed is the family's visible contract
        // (playlist's prompt carries it; without it the user's keystrokes
        // vanish from a prompt that asks them to type).
        return match ($this->action) {
            'delete' => "DELETE collection '{$name}'? This action cannot be undone. Type 'delete' to confirm: " . $this->typed,
            default => "Confirm action on collection '{$name}'?",
        };
    }

    /** @return array{action: string, collection: MediaItem, typed: string} */
    public function __debugInfo(): array
    {
        return [
            'action' => $this->action,
            'collection' => $this->collection,
            'typed' => $this->typed,
        ];
    }
}
