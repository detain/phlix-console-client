<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Screen\RecommendationsScreen;
use SugarCraft\Core\Msg;

/**
 * Carries the id of a dismissed recommendation for optimistic UI update.
 */
final readonly class RecommendationDismissedMsg implements Msg
{
    public function __construct(
        public string $mediaItemId,
    ) {
    }

    public function screenWith(RecommendationsScreen $screen): RecommendationsScreen
    {
        $next = clone $screen;
        $items = $next->items();

        // Find and remove the dismissed item.
        $filtered = [];
        foreach ($items as $item) {
            if ($item->id() !== $this->mediaItemId) {
                $filtered[] = $item;
            }
        }

        $next = $next->withItems($filtered);

        // Clamp selection to valid range after removal.
        $count = count($filtered);
        if ($count === 0) {
            return $next->withSelectedIndex(0);
        }

        if ($next->selectedIndex() >= $count) {
            return $next->withSelectedIndex($count - 1);
        }

        return $next;
    }
}
