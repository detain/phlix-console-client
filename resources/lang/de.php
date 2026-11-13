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
    // ---- Auth (session expiry + sign-in/register failures — every surface) --
    'auth.session_expired' => 'Deine Sitzung ist abgelaufen. Bitte melde dich erneut an.',
    'auth.register_taken' => 'Dieser Benutzername ist bereits vergeben. Bitte wähle einen anderen.',
    'auth.register_pending' => 'Dein Konto wartet auf Genehmigung. Du kannst dich anmelden, sobald ein Administrator es genehmigt hat.',
    'auth.register_disabled' => 'Die Registrierung ist auf diesem Server derzeit deaktiviert.',
    'auth.register_weak_password' => 'Dein Passwort ist zu schwach. Bitte wähle ein sichereres.',
    'auth.register_failed' => 'Die Registrierung ist fehlgeschlagen. Bitte versuche es erneut.',
    'auth.signin_failed' => 'Anmeldung fehlgeschlagen. Bitte versuche es erneut.',
    'auth.logout_revoke_failed' => 'Abmelden auf dem Server war nicht möglich. Auf diesem Gerät bist du abgemeldet.',

    // ---- Errors (shared failure surface — ExceptionSentences) ------
    'errors.network' => 'Der Server ist nicht erreichbar. Prüfe die URL und deine Netzwerkverbindung.',
    'errors.unexpected' => 'Etwas ist schiefgelaufen. Details stehen im Protokoll.',

    // ---- RecommendationsScreen ----------------------------------------
    'recommendations.title' => 'Für dich',
    'recommendations.hint' => 'q  zurück      ↑↓  navigieren      ⏎  öffnen      x  verwerfen',
    'recommendations.error_hint' => 'r  erneut versuchen      q  zurück',
    'recommendations.load_failed' => 'Empfehlungen konnten nicht geladen werden.',
    'recommendations.loading' => 'Empfehlungen werden geladen…',
    'recommendations.empty' => "Noch keine Empfehlungen.\n  Fange an zu schauen, um personalisierte Vorschläge zu erhalten!",
    'recommendations.dismiss_failed' => 'Empfehlung konnte nicht verworfen werden.',
    'recommendations.crumb' => 'Für dich',

    // ---- DetailScreen -------------------------------------------------
    'detail.load_failed' => 'Dieser Titel konnte nicht geladen werden.',
    'detail.similar_load_failed' => 'Ähnliche Titel konnten nicht geladen werden.',
    'detail.missing_episodes_load_failed' => 'Fehlende Folgen konnten nicht geladen werden.',
    'detail.children_load_failed' => 'Dieser Inhalt konnte nicht geladen werden.',
    'detail.rating_save_failed' => 'Bewertung konnte nicht gespeichert werden: ',
    'detail.play_notice' => '▶  Dieser Titel hat keine abspielbare Quelle.',
    'detail.actions_hint' => '▶  p  Abspielen      Esc  Zurück',
    'detail.hint' => '↑↓  Handlung scrollen      p  abspielen      s  Zufall      C  Besetzung      r  bewerten      F  Favorit      w  gesehen      l  Daumen hoch      j  Daumen runter      d  herunterladen      u  Untertitel      Esc  zurück',
    'detail.container_hint' => '↑↓←→  bewegen      ⏎  öffnen      s  Zufall      Esc  zurück',
    'detail.error_hint' => 'r  erneut versuchen      Esc  zurück',
    'detail.loading_hint' => 'Esc  zurück',
    'detail.loading' => 'Wird geladen…',
    'detail.no_synopsis' => 'Keine Beschreibung verfügbar.',
    'detail.more_like_this' => 'Ähnliche Titel',
    'detail.similar_navigate_hint' => '←→  navigieren      ⏎  öffnen',
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

    // ---- Player -------------------------------------------------------
    'player.hint_main' => 'Space ⏯  ←→ ±10s  [ ] Tempo  m Modus',
    'player.hint_captions_off' => '  c UT',
    'player.hint_captions_on' => '  c UT✓',
    'player.hint_quality' => '  v Qualität',
    'player.hint_next' => '  n weiter',
    'player.hint_sleep' => '  z ⏰ ',
    'player.hint_back' => '  q zurück',

    // ---- FilterBar ----------------------------------------------------
    'filter.search_placeholder' => '(Tippen zum Filtern)',
    'filter.search_label' => 'Suchen: ',
    'filter.sort_label' => 'Sortierung: ',
    'filter.sort_name' => 'Name',
    'filter.sort_year' => 'Jahr',
    'filter.sort_rating' => 'Bewertung',
    'filter.sort_date_added' => 'Hinzugefügt',
    'filter.sort_runtime' => 'Laufzeit',
    'filter.sort_genre' => 'Genre',
    'filter.sort_artist' => 'Künstler',
    'filter.order_label' => 'Reihenfolge: ',
    'filter.order_asc' => 'asc',
    'filter.order_desc' => 'desc',
    'filter.genre_label' => 'Genre: ',

    // ---- SearchScreen ----------------------------------------------
    'search.no_results_for' => 'Keine Ergebnisse für "{query}"',
    'search.results_one' => '{count} Ergebnis',
    'search.results_many' => '{count} Ergebnisse',


    // ---- LibraryScreen -----------------------------------------------
    'library.no_items' => 'Keine Einträge',
    'library.items_one' => '{count} Eintrag',
    'library.items_many' => '{count} Einträge',


    // ---- SyncPlay errors ----------------------------------------------
    'syncplay.not_authenticated' => 'Melde dich an, bevor du synchronisierte Gruppen nutzt.',
    'syncplay.not_in_group' => 'Du bist in keiner synchronisierten Gruppe.',
    'syncplay.not_host' => 'Nur der Gruppenhost kann das tun.',
    'syncplay.unknown_message' => 'Der Server hat die Anfrage nicht erkannt.',
    'syncplay.handler_error' => 'Auf dem Server ist etwas schiefgelaufen.',
    'syncplay.protocol_version_mismatch' => 'Diese App ist mit der Serverversion nicht kompatibel.',
    'syncplay.invalid_new_host' => 'Der angeforderte neue Host ist ungültig.',
    'syncplay.member_not_found' => 'Dieses Mitglied wurde in der Gruppe nicht gefunden.',
    'syncplay.same_host' => 'Dieses Mitglied ist bereits der Gruppenhost.',
    'syncplay.create_failed' => 'Die synchronisierte Gruppe konnte nicht erstellt werden.',
    'syncplay.join_failed' => 'Der synchronisierten Gruppe konnte nicht beigetreten werden.',
    'syncplay.leave_failed' => 'Die synchronisierte Gruppe konnte nicht verlassen werden.',
    'syncplay.group_limit_reached' => 'Der Host hat die maximale Anzahl an synchronisierten Gruppen erreicht.',
    'syncplay.group_not_found' => 'Diese synchronisierte Gruppe existiert nicht mehr.',
    'syncplay.invalid_password' => 'Das Passwort ist nicht korrekt.',
    'syncplay.group_full' => 'Diese synchronisierte Gruppe ist voll.',
    'syncplay.queue_limit_exceeded' => 'Die Wiedergabeliste dieser synchronisierten Gruppe ist voll.',
    'syncplay.unknown_error' => 'Ein Synchronisierungsfehler ist aufgetreten.',

    // ---- SyncPlay modal (group noun = this catalog's glossary term) --
    'syncplay.modal_public_groups' => 'Öffentliche synchronisierte Gruppen:',
    'syncplay.modal_create_new' => '+ Neue synchronisierte Gruppe erstellen',
    'syncplay.modal_create_title' => 'Synchronisierte Gruppe erstellen',
    'syncplay.modal_joining' => 'Beitritt zur synchronisierten Gruppe…',

    // ---- BrowseScreen (empty-library banner + hints) -----------------
    'browse.loading' => 'Deine Bibliotheken werden geladen…',
    'browse.no_libraries' => 'Noch keine Bibliotheken.',
    'browse.no_libraries_admin' => 'Drücke : für die Befehlspalette, dann {admin} → {libraries}, um eine hinzuzufügen.',
    'browse.no_libraries_member' => 'Bitte einen Administrator, eine hinzuzufügen.',
    'browse.hint_refresh_quit' => 'R  neu laden      q  beenden',

    // ---- Command palette (App::staticActions) ---------------------------
    'palette.search'     => 'Suche',
    'palette.home'       => 'Startseite',
    'palette.settings'   => 'Einstellungen',
    'palette.keys'       => 'Tastenkürzel',
    'palette.stats'      => 'Statistiken',
    'palette.watch_history'  => 'Wiedergabeverlauf',
    'palette.favorites'  => 'Favoriten',
    'palette.photos'     => 'Fotos',
    'palette.music_artists'  => 'Musikinterpreten',
    'palette.playlists'  => 'Wiedergabelisten',
    'palette.collections'  => 'Sammlungen',
    'palette.servers'    => 'Server',
    'palette.shared_with_me'  => 'Mit mir geteilt',
    'palette.invite_links'  => 'Einladungslinks',
    'palette.federation_shares'  => 'Föderations-Freigaben',
    'palette.show_metrics'  => 'Metriken anzeigen',
    'palette.hide_metrics'  => 'Metriken ausblenden',
    'palette.admin'      => 'Verwaltung',
    // ---- Admin menu sections (AdminMenuScreen::SECTIONS) ----------------
    'adminmenu.dashboard' => 'Dashboard',
    'adminmenu.users' => 'Benutzer',
    'adminmenu.settings' => 'Servereinstellungen',
    'adminmenu.transcoding' => 'Transkodierung',
    'adminmenu.plugins' => 'Plugins',
    'adminmenu.plugin_updates' => 'Plugin-Updates',
    'adminmenu.libraries' => 'Bibliotheken',
    'adminmenu.logs' => 'Protokolle',
    'adminmenu.backup' => 'Sicherung',
    'adminmenu.dlna' => 'DLNA',
    'adminmenu.remote' => 'Fernzugriff',
    'adminmenu.live_tv' => 'Live-TV',
    'adminmenu.parental' => 'Jugendschutz',
    'adminmenu.metrics' => 'Metriken',
    'adminmenu.watch_history' => 'Wiedergabeverlauf',
    'adminmenu.duplicates' => 'Duplikate',
    'adminmenu.webhooks' => 'Webhooks',
    'adminmenu.auth_providers' => 'Anmeldeanbieter',
    'adminmenu.restart' => 'Server-Neustart',
    'adminmenu.filesystem' => 'Dateisystem',
    'palette.resume_playback'  => 'Wiedergabe fortsetzen',
    'palette.pause_playback'  => 'Wiedergabe pausieren',
    'palette.stop_playback'  => 'Wiedergabe stoppen',
    'palette.sign_out'   => 'Abmelden',
    'palette.quit'       => 'Beenden',
    'palette.go_to'      => 'Wechseln zu {name}',
    // CON-S17: auth/onboarding chrome + secondary surfaces
    'chrome.too_small_title' => 'Terminal zu klein.',
    'chrome.too_small_size' => 'Vorhanden: {cols}×{rows} — Phlix benötigt mindestens {min_cols}×{min_rows}.',
    'chrome.too_small_resize' => 'Fenster vergrößern, um fortzufahren.',
    'auth.signin_heading' => 'Bei Phlix anmelden.',
    'auth.signin_title' => 'Anmelden',
    'auth.signin_progress' => 'Anmeldung läuft…',
    'auth.signin_footer' => 'Tab  weiter      Enter  anmelden      s  registrieren      Esc  beenden',
    'auth.signup_heading' => 'Phlix-Konto erstellen.',
    'auth.signup_title' => 'Registrieren',
    'auth.signup_progress' => 'Konto wird erstellt…',
    'auth.signup_footer' => 'Tab  weiter      Enter  registrieren      Esc  zurück zur Anmeldung',
    'auth.insecure_warning' => '⚠ Dieser Server nutzt eine unsichere Verbindung (http) — Passwort und Sitzungstoken werden ungeschützt übertragen.',
    'server.heading' => 'Mit dem Phlix-Server verbinden.',
    'server.title' => 'Einrichtung',
    'server.footer' => 'Enter  verbinden      Esc  beenden',
    'server.field_title' => 'Phlix-Server-URL',
    'server.insecure_typed' => '⚠ http:// sendet Passwort und Sitzungstoken unverschlüsselt. Verwende https://, außer in einem vertrauenswürdigen lokalen Netzwerk.',
    'server.invalid_url' => 'Gib eine gültige Server-URL ein (z. B. https://phlix.example.com).',
    'settings.heading' => 'Einstellungen',
    'settings.logged_in_as' => 'Angemeldet als {name}',
    'settings.server_line' => 'Server: {url}',
    'settings.footer' => 'L  abmelden      S  Server      Enter  speichern      Esc  abbrechen',
    'syncplay.modal_none' => '(keine verfügbar)',
    'syncplay.modal_more' => '… und {count} weitere',
    'syncplay.modal_hint_list' => '↑↓ auswählen  Enter bestätigen  Esc abbrechen',
    'syncplay.modal_name_label' => 'Name:',
    'syncplay.modal_public' => 'Öffentlich',
    'syncplay.modal_private' => 'Privat',
    'syncplay.modal_hint_create' => 'Enter erstellen  ← zurück  tippen, um den Namen zu ändern',
    'syncplay.modal_hint_cancel' => 'Esc abbrechen',
    'syncplay.modal_error_title' => 'Fehler',
    'syncplay.modal_error_default' => 'Die Aktion konnte nicht abgeschlossen werden.',
    'syncplay.modal_press_key' => 'Beliebige Taste drücken, um fortzufahren',
    'quality.auto' => 'Auto (empfohlen)',
];
