<?php

declare(strict_types=1);

/**
 * German (de) translation catalog for the Phlix console client.
 *
 * This file is loaded lazily by SugarCraft\Core\I18n\T on first lookup.
 * Each key is dot-separated: '<group>.<name>' where <group> is a logical
 * screen or feature grouping.
 *
 * Key set and placeholder tokens ({name}) must stay identical to en.php —
 * enforced by tests/I18n/LocaleCatalogsTest.php and scripts/check-i18n-catalogs.php.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

return [
    // ---- RecommendationsScreen ----------------------------------------
    'recommendations.title' => 'Für dich',
    'recommendations.hint' => 'Q: Zurück  ↑↓: Navigieren  Enter: Öffnen  X: Verwerfen',
    'recommendations.session_expired' => 'Deine Sitzung ist abgelaufen. Bitte melde dich erneut an.',
    'recommendations.load_failed' => 'Empfehlungen konnten nicht geladen werden.',
    'recommendations.loading' => 'Empfehlungen werden geladen…',
    'recommendations.empty' => "Noch keine Empfehlungen.\n  Fange an zu schauen, um personalisierte Vorschläge zu erhalten!",
    'recommendations.dismiss_failed' => 'Empfehlung konnte nicht verworfen werden.',
    'recommendations.crumb' => 'Für dich',

    // ---- DetailScreen -------------------------------------------------
    'detail.session_expired' => 'Deine Sitzung ist abgelaufen. Bitte melde dich erneut an.',
    'detail.load_failed' => 'Dieser Titel konnte nicht geladen werden.',
    'detail.similar_load_failed' => 'Ähnliche Titel konnten nicht geladen werden.',
    'detail.missing_episodes_load_failed' => 'Fehlende Folgen konnten nicht geladen werden.',
    'detail.children_load_failed' => 'Dieser Inhalt konnte nicht geladen werden.',
    'detail.rating_save_failed' => 'Bewertung konnte nicht gespeichert werden: ',
    'detail.play_notice' => '▶  Dieser Titel hat keine abspielbare Quelle.',
    'detail.actions_hint' => '▶  p  Abspielen   Esc  Zurück',
    'detail.hint' => '↑↓  Handlung scrollen      p  abspielen      s  Zufall      C  Besetzung      r  bewerten      F  Favorit      w  gesehen      l  Daumen hoch      j  Daumen runter      d  herunterladen      Esc  zurück',
    'detail.container_hint' => '↑↓←→  bewegen      ⏎  öffnen      s  Zufall      Esc  zurück',
    'detail.loading_hint' => 'Esc  zurück',
    'detail.loading' => 'Wird geladen…',
    'detail.no_synopsis' => 'Keine Beschreibung verfügbar.',
    'detail.more_like_this' => 'Ähnliche Titel',
    'detail.similar_navigate_hint' => '←→ navigieren  ⏎ öffnen',
    'detail.cast_label' => 'Besetzung',
    'detail.directed_by' => 'Regie: ',
    'detail.more_cast' => '  +{count} weitere',
    'detail.season' => 'Staffel',
    'detail.season_plural' => 'Staffeln',
    'detail.episode' => 'Folge',
    'detail.episode_plural' => 'Folgen',
    'detail.item' => 'Eintrag',
    'detail.item_plural' => 'Einträge',
    'detail.missing_episodes_one' => '⚠  {count} Folge fehlt',
    'detail.missing_episodes_many' => '⚠  {count} Folgen fehlen',
    'detail.loading_content' => 'Wird geladen…',

    // ---- FilterBar ----------------------------------------------------
    'filter.search_placeholder' => '(Tippen zum Filtern)',
    'filter.search_label' => 'Suchen: ',
    'filter.sort_label' => 'Sortierung: ',
    'filter.order_label' => 'Reihenfolge: ',
    'filter.order_asc' => 'asc',
    'filter.order_desc' => 'desc',
];
