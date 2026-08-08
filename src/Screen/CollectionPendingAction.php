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
        return "Type 'delete' to confirm {$this->action}ing '{$this->collection->name}': ";
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
