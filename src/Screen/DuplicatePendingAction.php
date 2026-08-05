<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Dto\Library;

/**
 * A destructive duplicate-merge action that has been armed and is awaiting a
 * typed confirmation on the AdminDuplicatesScreen's status line.
 * Immutable.
 */
final readonly class DuplicatePendingAction
{
    /**
     * @param Library                          $library   The library being acted on
     * @param string                           $primaryId The id of the primary item (kept)
     * @param string                           $primaryName The name of the primary item (for typed confirmation)
     * @param list<string>                     $duplicateIds The ids of items to merge into primary
     * @param string                           $typed     Accumulated typed characters
     */
    public function __construct(
        public Library $library,
        public string $primaryId,
        public string $primaryName,
        public array $duplicateIds,
        public string $typed = '',
    ) {
    }

    /**
     * The confirm prompt text for the typed confirmation.
     */
    public function prompt(): string
    {
        $count = count($this->duplicateIds);
        $item = $this->primaryName === '' ? 'this item' : "'{$this->primaryName}'";
        $label = $count === 1 ? '1 item' : "{$count} items";

        return "Merge {$label} into {$item}? Type {$this->primaryName} to confirm: " . $this->typed;
    }

    /**
     * Whether the typed confirmation matches the primary name exactly.
     */
    public function isConfirmed(): bool
    {
        return $this->typed === $this->primaryName;
    }

    /**
     * With an additional typed character.
     */
    public function withTyped(string $char): self
    {
        $next = $this->typed . $char;
        // Cap at the length of the primary name to prevent unbounded growth
        if (mb_strlen($next, 'UTF-8') > mb_strlen($this->primaryName, 'UTF-8')) {
            return $this;
        }

        return new self($this->library, $this->primaryId, $this->primaryName, $this->duplicateIds, $next);
    }
}
