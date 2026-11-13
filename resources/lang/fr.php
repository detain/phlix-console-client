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
    // ---- Auth (session expiry + sign-in/register failures — every surface) --
    'auth.session_expired' => 'Votre session a expiré. Veuillez vous reconnecter.',
    'auth.register_taken' => 'Ce nom d’utilisateur est déjà pris. Veuillez en choisir un autre.',
    'auth.register_pending' => 'Votre compte est en attente d’approbation. Vous pourrez vous connecter une fois qu’un administrateur l’aura approuvé.',
    'auth.register_disabled' => 'L’inscription est actuellement désactivée sur ce serveur.',
    'auth.register_weak_password' => 'Votre mot de passe est trop faible. Veuillez en choisir un plus robuste.',
    'auth.register_failed' => 'L’inscription a échoué. Veuillez réessayer.',
    'auth.signin_failed' => 'Connexion impossible. Veuillez réessayer.',
    'auth.logout_revoke_failed' => 'La déconnexion n’a pas pu aboutir sur le serveur. Vous êtes bien déconnecté sur cet appareil.',

    // ---- Errors (shared failure surface — ExceptionSentences) ------
    'errors.network' => 'Impossible de joindre le serveur. Vérifiez l’URL et votre connexion.',
    'errors.unexpected' => 'Une erreur est survenue. Consultez le journal pour plus de détails.',

    // ---- RecommendationsScreen ----------------------------------------
    'recommendations.title' => 'Pour vous',
    'recommendations.hint' => 'q  retour      ↑↓  naviguer      ⏎  ouvrir      x  ignorer',
    'recommendations.load_failed' => 'Impossible de charger les recommandations.',
    'recommendations.loading' => 'Chargement des recommandations…',
    'recommendations.empty' => "Pas encore de recommandations.\n  Regardez des titres pour recevoir des suggestions personnalisées !",
    'recommendations.dismiss_failed' => "Impossible d’ignorer la recommandation.",
    'recommendations.crumb' => 'Pour vous',

    // ---- DetailScreen -------------------------------------------------
    'detail.load_failed' => 'Impossible de charger ce titre.',
    'detail.similar_load_failed' => 'Impossible de charger les titres similaires.',
    'detail.missing_episodes_load_failed' => 'Impossible de charger les épisodes manquants.',
    'detail.children_load_failed' => 'Impossible de charger ce contenu.',
    'detail.rating_save_failed' => "Impossible d’enregistrer la note : ",
    'detail.play_notice' => "▶  Ce titre n’a aucune source lisible.",
    'detail.actions_hint' => '▶  p  Lire      Esc  Retour',
    'detail.hint' => "↑↓  défilez le résumé      p  lire      s  aléatoire      C  distribution      r  noter      F  favori      w  vu      l  j’aime      j  je n’aime pas      d  télécharger      u  sous-titres      Esc  retour",
    'detail.container_hint' => '↑↓←→  déplacer      ⏎  ouvrir      s  aléatoire      Esc  retour',
    'detail.error_hint' => 'r  réessayer      Esc  retour',
    'detail.loading_hint' => 'Esc  retour',
    'detail.loading' => 'Chargement…',
    'detail.no_synopsis' => 'Aucun résumé disponible.',
    'detail.more_like_this' => 'Titres similaires',
    'detail.similar_navigate_hint' => '←→  naviguer      ⏎  ouvrir',
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

    // ---- Player -------------------------------------------------------
    'player.hint_main' => 'Space ⏯  ←→ ±10s  [ ] vitesse  m mode',
    'player.hint_captions_off' => '  c ST',
    'player.hint_captions_on' => '  c ST✓',
    'player.hint_quality' => '  v qualité',
    'player.hint_next' => '  n suivant',
    'player.hint_sleep' => '  z ⏰ ',
    'player.hint_back' => '  q retour',

    // ---- FilterBar ----------------------------------------------------
    'filter.search_placeholder' => '(tapez pour filtrer)',
    'filter.search_label' => 'Recherche : ',
    'filter.sort_label' => 'Trier : ',
    'filter.sort_name' => 'nom',
    'filter.sort_year' => 'année',
    'filter.sort_rating' => 'note',
    'filter.sort_date_added' => 'date d’ajout',
    'filter.sort_runtime' => 'durée',
    'filter.sort_genre' => 'genre',
    'filter.sort_artist' => 'artiste',
    'filter.order_label' => 'Ordre : ',
    'filter.order_asc' => 'asc',
    'filter.order_desc' => 'desc',
    'filter.genre_label' => 'Genres : ',

    // ---- SearchScreen ----------------------------------------------
    'search.no_results_for' => 'Aucun résultat pour « {query} »',
    'search.results_one' => '{count} résultat',
    'search.results_many' => '{count} résultats',


    // ---- LibraryScreen -----------------------------------------------
    'library.no_items' => 'Aucun élément',
    'library.items_one' => '{count} élément',
    'library.items_many' => '{count} éléments',


    // ---- SyncPlay errors ----------------------------------------------
    'syncplay.not_authenticated' => 'Connectez-vous avant d’utiliser les groupes synchronisés.',
    'syncplay.not_in_group' => 'Vous ne faites pas partie d’un groupe synchronisé.',
    'syncplay.not_host' => 'Seul l’hôte du groupe peut faire cela.',
    'syncplay.unknown_message' => "Le serveur n’a pas reconnu la demande.",
    'syncplay.handler_error' => 'Une erreur est survenue sur le serveur.',
    'syncplay.protocol_version_mismatch' => 'Cette application est incompatible avec la version du serveur.',
    'syncplay.invalid_new_host' => 'Le nouvel hôte demandé n’est pas valide.',
    'syncplay.member_not_found' => 'Ce membre est introuvable dans le groupe.',
    'syncplay.same_host' => 'Ce membre est déjà l’hôte du groupe.',
    'syncplay.create_failed' => 'Impossible de créer le groupe synchronisé.',
    'syncplay.join_failed' => 'Impossible de rejoindre le groupe synchronisé.',
    'syncplay.leave_failed' => 'Impossible de quitter le groupe synchronisé.',
    'syncplay.group_limit_reached' => 'L’hôte a atteint la limite de groupes synchronisés.',
    'syncplay.group_not_found' => 'Ce groupe synchronisé n’existe plus.',
    'syncplay.invalid_password' => 'Le mot de passe n’est pas correct.',
    'syncplay.group_full' => 'Ce groupe synchronisé est complet.',
    'syncplay.queue_limit_exceeded' => 'La file de lecture de ce groupe synchronisé est pleine.',
    'syncplay.unknown_error' => 'Une erreur de synchronisation est survenue.',

    // ---- SyncPlay modal (group noun = this catalog's glossary term) --
    'syncplay.modal_public_groups' => 'Groupes synchronisés publics :',
    'syncplay.modal_create_new' => '+ Créer un groupe synchronisé',
    'syncplay.modal_create_title' => 'Créer un groupe synchronisé',
    'syncplay.modal_joining' => 'Connexion au groupe synchronisé…',

    'browse.loading' => 'Chargement de vos bibliothèques…',
    'browse.no_libraries' => 'Aucune bibliothèque pour le moment.',
    'browse.no_libraries_admin' => 'Appuyez sur : pour ouvrir la palette de commandes, puis Admin → Libraries pour en ajouter une.',
    'browse.no_libraries_member' => 'Demandez à un administrateur d’en ajouter une.',
    'browse.hint_refresh_quit' => 'R  recharger      q  quitter',

    // ---- Command palette (App::staticActions) ---------------------------
    'palette.search'     => 'Rechercher',
    'palette.home'       => 'Accueil',
    'palette.settings'   => 'Paramètres',
    'palette.keys'       => 'Raccourcis clavier',
    'palette.stats'      => 'Statistiques',
    'palette.watch_history'  => 'Historique de lecture',
    'palette.favorites'  => 'Favoris',
    'palette.photos'     => 'Photos',
    'palette.music_artists'  => 'Artistes musicaux',
    'palette.playlists'  => 'Listes de lecture',
    'palette.collections'  => 'Collections',
    'palette.servers'    => 'Serveurs',
    'palette.shared_with_me'  => 'Partagés avec moi',
    'palette.invite_links'  => 'Liens d’invitation',
    'palette.federation_shares'  => 'Partages de la fédération',
    'palette.show_metrics'  => 'Afficher les métriques',
    'palette.hide_metrics'  => 'Masquer les métriques',
    'palette.admin'      => 'Administration',
    'palette.resume_playback'  => 'Reprendre la lecture',
    'palette.pause_playback'  => 'Mettre la lecture en pause',
    'palette.stop_playback'  => 'Arrêter la lecture',
    'palette.sign_out'   => 'Se déconnecter',
    'palette.quit'       => 'Quitter',
    'palette.go_to'      => 'Aller à {name}',
];
