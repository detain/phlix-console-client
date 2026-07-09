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
 * Carries the loaded recommendations from the API to the screen.
 */
final readonly class RecommendationsLoadedMsg implements Msg
{
    /**
     * @param list<array<string, mixed>> $recommendations
     */
    public function __construct(
        public array $recommendations,
    ) {
    }

    public function screenWith(RecommendationsScreen $screen): RecommendationsScreen
    {
        $next = clone $screen;
        $next = $next->withLoading(false);
        $next = $next->withError(null);
        $next = $next->withItems(array_map(
            static fn (array $item): \Phlix\Console\Ui\RecommendationCard => \Phlix\Console\Ui\RecommendationCard::fromArray($item),
            $this->recommendations,
        ));

        return $next;
    }
}
