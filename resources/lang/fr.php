<?php

declare(strict_types=1);

/**
 * French (fr) translation catalog for the Phlix console client.
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
    'recommendations.title' => 'Pour vous',
    'recommendations.hint' => 'Q : Retour  ↑↓ : Naviguer  Entrée : Ouvrir  X : Ignorer',
    'recommendations.session_expired' => 'Votre session a expiré. Veuillez vous reconnecter.',
    'recommendations.load_failed' => 'Impossible de charger les recommandations.',
    'recommendations.loading' => 'Chargement des recommandations…',
    'recommendations.empty' => "Pas encore de recommandations.\n  Regardez des titres pour recevoir des suggestions personnalisées !",
    'recommendations.dismiss_failed' => "Impossible d'ignorer la recommandation.",
    'recommendations.crumb' => 'Pour vous',

    // ---- DetailScreen -------------------------------------------------
    'detail.session_expired' => 'Votre session a expiré. Veuillez vous reconnecter.',
    'detail.load_failed' => 'Impossible de charger ce titre.',
    'detail.similar_load_failed' => 'Impossible de charger les titres similaires.',
    'detail.missing_episodes_load_failed' => 'Impossible de charger les épisodes manquants.',
    'detail.children_load_failed' => 'Impossible de charger ce contenu.',
    'detail.rating_save_failed' => "Échec de l'enregistrement de la note : ",
    'detail.play_notice' => "▶  Ce titre n'a aucune source lisible.",
    'detail.actions_hint' => '▶  p  Lire    Esc  Retour',
    'detail.hint' => "↑↓  défilez le résumé      p  lire      s  aléatoire      C  distribution      r  noter      F  favori      w  vu      l  j'aime      j  je n'aime pas      d  télécharger      Esc  retour",
    'detail.container_hint' => '↑↓←→  déplacer      ⏎  ouvrir      s  aléatoire      Esc  retour',
    'detail.loading_hint' => 'Esc  retour',
    'detail.loading' => 'Chargement…',
    'detail.no_synopsis' => 'Aucun résumé disponible.',
    'detail.more_like_this' => 'Titres similaires',
    'detail.similar_navigate_hint' => '←→ naviguer  ⏎ ouvrir',
    'detail.cast_label' => 'Distribution',
    'detail.directed_by' => 'Réalisé par ',
    'detail.more_cast' => '  +{count} de plus',
    'detail.season' => 'saison',
    'detail.season_plural' => 'saisons',
    'detail.episode' => 'épisode',
    'detail.episode_plural' => 'épisodes',
    'detail.item' => 'élément',
    'detail.item_plural' => 'éléments',
    'detail.missing_episodes_one' => '⚠  {count} épisode manquant',
    'detail.missing_episodes_many' => '⚠  {count} épisodes manquants',
    'detail.loading_content' => 'Chargement…',

    // ---- FilterBar ----------------------------------------------------
    'filter.search_placeholder' => '(tapez pour filtrer)',
    'filter.search_label' => 'Recherche : ',
    'filter.sort_label' => 'Trier : ',
    'filter.order_label' => 'Ordre : ',
    'filter.order_asc' => 'asc',
    'filter.order_desc' => 'desc',

    // ---- SyncPlay errors ----------------------------------------------
    'syncplay.not_authenticated' => 'Connectez-vous avant d’utiliser les soirées synchronisées.',
    'syncplay.not_in_group' => 'Vous ne faites pas partie d’un groupe synchronisé.',
    'syncplay.not_host' => 'Seul l’hôte du groupe peut faire cela.',
    'syncplay.unknown_message' => 'Le serveur a rejeté une demande non reconnue.',
    'syncplay.handler_error' => 'Une erreur est survenue sur le serveur de synchronisation.',
    'syncplay.protocol_version_mismatch' => 'Cette application est incompatible avec la version du serveur.',
    'syncplay.invalid_new_host' => 'Le nouvel hôte demandé n’est pas valide.',
    'syncplay.member_not_found' => 'Ce membre est introuvable dans le groupe.',
    'syncplay.same_host' => 'Ce membre est déjà l’hôte du groupe.',
    'syncplay.create_failed' => 'Impossible de créer le groupe synchronisé.',
    'syncplay.join_failed' => 'Impossible de rejoindre le groupe synchronisé.',
    'syncplay.leave_failed' => 'Impossible de quitter le groupe synchronisé.',
    'syncplay.unknown_error' => 'Une erreur de synchronisation est survenue.',
];
