<?php

declare(strict_types=1);

/**
 * English (en) translation catalog for the Phlix console client.
 *
 * This file is loaded lazily by SugarCraft\Core\I18n\T on first lookup.
 * Each key is dot-separated: '<group>.<name>' where <group> is a logical
 * screen or feature grouping.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

return [
    // ---- RecommendationsScreen ----------------------------------------
    'recommendations.title' => 'For You',
    'recommendations.hint' => 'Q: Back  ↑↓: Navigate  Enter: Open  X: Dismiss',
    'recommendations.session_expired' => 'Your session expired. Please sign in again.',
    'recommendations.load_failed' => 'Could not load recommendations.',
    'recommendations.loading' => 'Loading recommendations…',
    'recommendations.empty' => "No recommendations yet.\n  Start watching to get personalized suggestions!",
    'recommendations.dismiss_failed' => 'Could not dismiss recommendation.',
    'recommendations.crumb' => 'For You',

    // ---- DetailScreen -------------------------------------------------
    'detail.session_expired' => 'Your session expired. Please sign in again.',
    'detail.play_notice' => '▶  This title has no playable source.',
    'detail.hint' => '↑↓  scroll synopsis      p  play      s  shuffle      C  cast      r  rate      F  favorite      w  watched      l  thumbs up      j  thumbs down      d  download      Esc  back',
    'detail.container_hint' => '↑↓←→  move      ⏎  open      s  shuffle      Esc  back',
    'detail.loading_hint' => 'Esc  back',
    'detail.loading' => 'Loading…',
    'detail.no_synopsis' => 'No synopsis available.',
    'detail.more_like_this' => 'More Like This',
    'detail.similar_navigate_hint' => '←→ navigate  ⏎ open',
    'detail.cast_label' => 'Cast',
    'detail.directed_by' => 'Directed by ',
    'detail.more_cast' => '  +{count} more',
    'detail.season' => 'season',
    'detail.episode' => 'episode',
    'detail.item' => 'item',
    'detail.loading_content' => 'Loading…',

    // ---- FilterBar ----------------------------------------------------
    'filter.search_placeholder' => '(type to filter)',
    'filter.search_label' => 'Search: ',
    'filter.sort_label' => 'Sort: ',
    'filter.order_label' => 'Order: ',
    'filter.order_asc' => 'asc',
    'filter.order_desc' => 'desc',
];
