<?php

declare(strict_types=1);

/**
 * Italian (it) translation catalog for the Phlix console client.
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
    'auth.session_expired' => 'La tua sessione è scaduta. Accedi di nuovo.',
    'auth.register_taken' => 'Questo nome utente è già in uso. Scegline uno diverso.',
    'auth.register_pending' => 'Il tuo account è in attesa di approvazione. Potrai accedere quando un amministratore lo approverà.',
    'auth.register_disabled' => 'La registrazione è attualmente disabilitata su questo server.',
    'auth.register_weak_password' => 'La tua password è troppo debole. Scegline una più sicura.',
    'auth.register_failed' => 'Registrazione non riuscita. Riprova.',
    'auth.signin_failed' => 'Accesso non riuscito. Riprova.',
    'auth.logout_revoke_failed' => 'Impossibile disconnettersi sul server. Su questo dispositivo sei già uscito.',

    // ---- Errors (shared failure surface — ExceptionSentences) ------
    'errors.network' => 'Impossibile contattare il server. Controlla l’URL e la connessione.',
    'errors.unexpected' => 'Qualcosa è andato storto. Controlla il log per i dettagli.',

    // ---- RecommendationsScreen ----------------------------------------
    'recommendations.title' => 'Per te',
    'recommendations.hint' => 'q  indietro      ↑↓  sfoglia      ⏎  apri      x  ignora',
    'recommendations.error_hint' => 'r  riprova      q  indietro',
    'recommendations.load_failed' => 'Impossibile caricare i consigli.',
    'recommendations.loading' => 'Caricamento consigli…',
    'recommendations.empty' => "Ancora nessun consiglio.\n  Inizia a guardare per ricevere suggerimenti personalizzati!",
    'recommendations.dismiss_failed' => 'Impossibile ignorare il consiglio.',
    'recommendations.crumb' => 'Per te',

    // ---- DetailScreen -------------------------------------------------
    'detail.load_failed' => 'Impossibile caricare questo titolo.',
    'detail.similar_load_failed' => 'Impossibile caricare i titoli simili.',
    'detail.missing_episodes_load_failed' => 'Impossibile caricare gli episodi mancanti.',
    'detail.children_load_failed' => 'Impossibile caricare questo contenuto.',
    'detail.rating_save_failed' => 'Impossibile salvare la valutazione: ',
    'detail.play_notice' => '▶  Questo titolo non ha una sorgente riproducibile.',
    'detail.actions_hint' => '▶  p  Riproduci      Esc  Indietro',
    'detail.hint' => '↑↓  scorri sinossi      p  riproduci      s  casuale      C  cast      r  valuta      F  preferito      w  visto      l  mi piace      j  non mi piace      d  scarica      u  sottotitoli      Esc  indietro',
    'detail.container_hint' => '↑↓←→  sposta      ⏎  apri      s  casuale      Esc  indietro',
    'detail.error_hint' => 'r  riprova      Esc  indietro',
    'detail.loading_hint' => 'Esc  indietro',
    'detail.loading' => 'Caricamento…',
    'detail.no_synopsis' => 'Nessuna sinossi disponibile.',
    'detail.more_like_this' => 'Titoli simili',
    'detail.similar_navigate_hint' => '←→  scorri      ⏎  apri',
    'detail.cast_label' => 'Cast',
    'detail.directed_by' => 'Regia di ',
    'detail.more_cast' => '  +{count} altri',
    'detail.season' => 'stagione',
    'detail.season_plural' => 'stagioni',
    'detail.episode' => 'episodio',
    'detail.episode_plural' => 'episodi',
    'detail.item' => 'elemento',
    'detail.item_plural' => 'elementi',
    'detail.missing_episodes_one' => '⚠  {count} episodio mancante',
    'detail.missing_episodes_many' => '⚠  {count} episodi mancanti',
    'detail.loading_content' => 'Caricamento…',

    // ---- Player -------------------------------------------------------
    'player.hint_main' => 'Space ⏯  ←→ ±10s  [ ] velocità  m modo',
    'player.hint_captions_off' => '  c Sott',
    'player.hint_captions_on' => '  c Sott✓',
    'player.hint_quality' => '  v qualità',
    'player.hint_next' => '  n successivo',
    'player.hint_sleep' => '  z ⏰ ',
    'player.hint_back' => '  q indietro',

    // ---- FilterBar ----------------------------------------------------
    'filter.search_placeholder' => '(scrivi per filtrare)',
    'filter.search_label' => 'Cerca: ',
    'filter.sort_label' => 'Ordina: ',
    'filter.sort_name' => 'nome',
    'filter.sort_year' => 'anno',
    'filter.sort_rating' => 'valutazione',
    'filter.sort_date_added' => 'data di aggiunta',
    'filter.sort_runtime' => 'durata',
    'filter.sort_genre' => 'genere',
    'filter.sort_artist' => 'artista',
    'filter.order_label' => 'Ordine: ',
    'filter.order_asc' => 'asc',
    'filter.order_desc' => 'desc',
    'filter.genre_label' => 'Generi: ',

    // ---- SearchScreen ----------------------------------------------
    'search.no_results_for' => 'Nessun risultato per "{query}"',
    'search.results_one' => '{count} risultato',
    'search.results_many' => '{count} risultati',


    // ---- LibraryScreen -----------------------------------------------
    'library.no_items' => 'Nessun elemento',
    'library.items_one' => '{count} elemento',
    'library.items_many' => '{count} elementi',


    // ---- SyncPlay errors ----------------------------------------------
    'syncplay.not_authenticated' => 'Accedi prima di usare i gruppi di visione.',
    'syncplay.not_in_group' => 'Non fai parte di un gruppo di visione.',
    'syncplay.not_host' => 'Solo l’host del gruppo può farlo.',
    'syncplay.unknown_message' => 'Il server non ha riconosciuto la richiesta.',
    'syncplay.handler_error' => 'Qualcosa è andato storto sul server.',
    'syncplay.protocol_version_mismatch' => 'Questa app non è compatibile con la versione del server.',
    'syncplay.invalid_new_host' => 'Il nuovo host richiesto non è valido.',
    'syncplay.member_not_found' => 'Il membro non è stato trovato nel gruppo.',
    'syncplay.same_host' => 'Il membro è già l’host del gruppo.',
    'syncplay.create_failed' => 'Impossibile creare il gruppo di visione.',
    'syncplay.join_failed' => 'Impossibile unirsi al gruppo di visione.',
    'syncplay.leave_failed' => 'Impossibile uscire dal gruppo di visione.',
    'syncplay.group_limit_reached' => 'L’host ha raggiunto il limite di gruppi di visione.',
    'syncplay.group_not_found' => 'Quel gruppo di visione non esiste più.',
    'syncplay.invalid_password' => 'La password non è corretta.',
    'syncplay.group_full' => 'Quel gruppo di visione è pieno.',
    'syncplay.queue_limit_exceeded' => 'La coda di riproduzione di quel gruppo di visione è piena.',
    'syncplay.unknown_error' => 'Si è verificato un errore di sincronizzazione.',

    // ---- SyncPlay modal (group noun = this catalog's glossary term) --
    'syncplay.modal_public_groups' => 'Gruppi di visione pubblici:',
    'syncplay.modal_create_new' => '+ Crea un gruppo di visione',
    'syncplay.modal_create_title' => 'Crea gruppo di visione',
    'syncplay.modal_joining' => 'Ingresso nel gruppo di visione…',

    // ---- BrowseScreen (empty-library banner + hints) -----------------
    'browse.loading' => 'Caricamento delle librerie…',
    'browse.no_libraries' => 'Ancora nessuna libreria.',
    'browse.no_libraries_admin' => 'Premi : per aprire la palette dei comandi, poi {admin} → {libraries} per aggiungerne una.',
    'browse.no_libraries_member' => 'Chiedi a un amministratore di aggiungerne una.',
    'browse.hint_refresh_quit' => 'R  ricarica      q  esci',

    // ---- Command palette (App::staticActions) ---------------------------
    'palette.search'     => 'Cerca',
    'palette.home'       => 'Pagina iniziale',
    'palette.settings'   => 'Impostazioni',
    'palette.keys'       => 'Scorciatoie da tastiera',
    'palette.stats'      => 'Statistiche',
    'palette.watch_history'  => 'Cronologia di riproduzione',
    'palette.favorites'  => 'Preferiti',
    'palette.photos'     => 'Foto',
    'palette.music_artists'  => 'Artisti musicali',
    'palette.playlists'  => 'Liste di riproduzione',
    'palette.collections'  => 'Raccolte',
    'palette.servers'    => 'Server',
    'palette.shared_with_me'  => 'Condivisi con me',
    'palette.invite_links'  => 'Link di invito',
    'palette.federation_shares'  => 'Condivisioni della federazione',
    'palette.show_metrics'  => 'Mostra metriche',
    'palette.hide_metrics'  => 'Nascondi metriche',
    'palette.admin'      => 'Amministrazione',
    // ---- Admin menu sections (AdminMenuScreen::SECTIONS) ----------------
    'adminmenu.dashboard' => 'Dashboard',
    'adminmenu.users' => 'Utenti',
    'adminmenu.settings' => 'Impostazioni del server',
    'adminmenu.transcoding' => 'Transcodifica',
    'adminmenu.plugins' => 'Plugin',
    'adminmenu.plugin_updates' => 'Aggiornamenti dei plugin',
    'adminmenu.libraries' => 'Librerie',
    'adminmenu.logs' => 'Log',
    'adminmenu.backup' => 'Backup',
    'adminmenu.dlna' => 'DLNA',
    'adminmenu.remote' => 'Accesso remoto',
    'adminmenu.live_tv' => 'TV in diretta',
    'adminmenu.parental' => 'Controllo genitori',
    'adminmenu.metrics' => 'Metriche',
    'adminmenu.watch_history' => 'Cronologia di visione',
    'adminmenu.duplicates' => 'Duplicati',
    'adminmenu.webhooks' => 'Webhook',
    'adminmenu.auth_providers' => 'Provider di autenticazione',
    'adminmenu.restart' => 'Riavvio del server',
    'adminmenu.filesystem' => 'File system',
    'palette.resume_playback'  => 'Riprendi riproduzione',
    'palette.pause_playback'  => 'Metti in pausa',
    'palette.stop_playback'  => 'Ferma riproduzione',
    'palette.sign_out'   => 'Disconnetti',
    'palette.quit'       => 'Esci',
    'palette.go_to'      => 'Vai a {name}',
    // CON-S17: auth/onboarding chrome + secondary surfaces
    'chrome.too_small_title' => 'Terminale troppo piccolo.',
    'chrome.too_small_size' => 'Disponibile {cols}×{rows} — Phlix richiede almeno {min_cols}×{min_rows}.',
    'chrome.too_small_resize' => 'Ridimensiona la finestra per continuare.',
    'auth.signin_heading' => 'Accedi a Phlix.',
    'auth.signin_title' => 'Accedi',
    'auth.signin_progress' => 'Accesso in corso…',
    'auth.signin_footer' => 'Tab  avanti      Invio  accedi      s  registrati      Esc  esci',
    'auth.signup_heading' => 'Crea il tuo account Phlix.',
    'auth.signup_title' => 'Registrazione',
    'auth.signup_progress' => 'Creazione dell’account…',
    'auth.signup_footer' => 'Tab  avanti      Invio  registrati      Esc  torna all’accesso',
    'auth.insecure_warning' => '⚠ Questo server usa una connessione non sicura (http): la password e i token di sessione viaggiano senza protezione.',
    'server.heading' => 'Connettiti al tuo server Phlix.',
    'server.title' => 'Configurazione',
    'server.footer' => 'Invio  connetti      Esc  esci',
    'server.field_title' => 'URL del server Phlix',
    'server.insecure_typed' => '⚠ http:// invia la password e i token di sessione senza crittografia. Usa https:// a meno che non sia una rete locale fidata.',
    'server.invalid_url' => 'Inserisci un URL del server valido (es. https://phlix.example.com).',
    'settings.heading' => 'Impostazioni',
    'settings.logged_in_as' => 'Accesso come {name}',
    'settings.server_line' => 'Server: {url}',
    'settings.footer' => 'L  esci      S  server      Invio  salva      Esc  annulla',
    'syncplay.modal_none' => '(nessuno disponibile)',
    'syncplay.modal_more' => '… e altri {count}',
    'syncplay.modal_hint_list' => '↑↓ seleziona  Invio conferma  Esc annulla',
    'syncplay.modal_name_label' => 'Nome:',
    'syncplay.modal_public' => 'Pubblico',
    'syncplay.modal_private' => 'Privato',
    'syncplay.modal_hint_create' => 'Invio crea  ← indietro  digita per modificare il nome',
    'syncplay.modal_hint_cancel' => 'Esc annulla',
    'syncplay.modal_error_title' => 'Errore',
    'syncplay.modal_error_default' => 'Impossibile completare l’azione.',
    'syncplay.modal_press_key' => 'Premi un tasto qualsiasi per continuare',
    'quality.auto' => 'Auto (consigliato)',
];
