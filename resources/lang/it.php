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
    // ---- RecommendationsScreen ----------------------------------------
    'recommendations.title' => 'Per te',
    'recommendations.hint' => 'Q: Indietro  ↑↓: Sfoglia  Invio: Apri  X: Ignora',
    'recommendations.session_expired' => 'La tua sessione è scaduta. Accedi di nuovo.',
    'recommendations.load_failed' => 'Impossibile caricare i consigli.',
    'recommendations.loading' => 'Caricamento consigli…',
    'recommendations.empty' => "Ancora nessun consiglio.\n  Inizia a guardare per ricevere suggerimenti personalizzati!",
    'recommendations.dismiss_failed' => 'Impossibile ignorare il consiglio.',
    'recommendations.crumb' => 'Per te',

    // ---- DetailScreen -------------------------------------------------
    'detail.session_expired' => 'La tua sessione è scaduta. Accedi di nuovo.',
    'detail.load_failed' => 'Impossibile caricare questo titolo.',
    'detail.similar_load_failed' => 'Impossibile caricare i titoli simili.',
    'detail.missing_episodes_load_failed' => 'Impossibile caricare gli episodi mancanti.',
    'detail.children_load_failed' => 'Impossibile caricare questo contenuto.',
    'detail.rating_save_failed' => 'Salvataggio della valutazione non riuscito: ',
    'detail.play_notice' => '▶  Questo titolo non ha una sorgente riproducibile.',
    'detail.actions_hint' => '▶  p  Riproduci   Esc  Indietro',
    'detail.hint' => '↑↓  scorri sinossi      p  riproduci      s  casuale      C  cast      r  valuta      F  preferito      w  visto      l  mi piace      j  non mi piace      d  scarica      Esc  indietro',
    'detail.container_hint' => '↑↓←→  sposta      ⏎  apri      s  casuale      Esc  indietro',
    'detail.loading_hint' => 'Esc  indietro',
    'detail.loading' => 'Caricamento…',
    'detail.no_synopsis' => 'Nessuna sinossi disponibile.',
    'detail.more_like_this' => 'Titoli simili',
    'detail.similar_navigate_hint' => '←→ scorri  ⏎ apri',
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

    // ---- FilterBar ----------------------------------------------------
    'filter.search_placeholder' => '(scrivi per filtrare)',
    'filter.search_label' => 'Cerca: ',
    'filter.sort_label' => 'Ordina: ',
    'filter.order_label' => 'Ordine: ',
    'filter.order_asc' => 'asc',
    'filter.order_desc' => 'desc',

    // ---- SyncPlay errors ----------------------------------------------
    'syncplay.not_authenticated' => 'Accedi prima di usare le sale di visione.',
    'syncplay.not_in_group' => 'Non fai parte di un gruppo di visione.',
    'syncplay.not_host' => 'Solo l’ospite del gruppo può farlo.',
    'syncplay.unknown_message' => 'Il server ha rifiutato una richiesta non riconosciuta.',
    'syncplay.handler_error' => 'Qualcosa è andato storto sul server di sincronizzazione.',
    'syncplay.protocol_version_mismatch' => 'Questa app non è compatibile con la versione del server.',
    'syncplay.invalid_new_host' => 'Il nuovo ospite richiesto non è valido.',
    'syncplay.member_not_found' => 'Il membro non è stato trovato nel gruppo.',
    'syncplay.same_host' => 'Il membro è già l’ospite del gruppo.',
    'syncplay.create_failed' => 'Impossibile creare il gruppo di visione.',
    'syncplay.join_failed' => 'Impossibile unirsi al gruppo di visione.',
    'syncplay.leave_failed' => 'Impossibile uscire dal gruppo di visione.',
    'syncplay.group_limit_reached' => 'L’ospite ha raggiunto il limite di sale di visione.',
    'syncplay.group_not_found' => 'Quel gruppo di visione non esiste più.',
    'syncplay.invalid_password' => 'La password non è corretta.',
    'syncplay.group_full' => 'Quel gruppo di visione è pieno.',
    'syncplay.unknown_error' => 'Si è verificato un errore di sincronizzazione.',
];
