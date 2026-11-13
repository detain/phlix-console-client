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
    // ---- Auth (session expiry + sign-in/register failures — every surface) --
    'auth.session_expired' => 'Your session expired. Please sign in again.',
    'auth.register_taken' => 'That username is already taken. Please choose a different one.',
    'auth.register_pending' => 'Your account is pending approval. You’ll be able to sign in once an administrator approves it.',
    'auth.register_disabled' => 'Registration is currently disabled on this server.',
    'auth.register_weak_password' => 'Your password is too weak. Please choose a stronger one.',
    'auth.register_failed' => 'Registration failed. Please try again.',
    'auth.signin_failed' => 'Could not sign in. Please try again.',
    'auth.logout_revoke_failed' => 'Could not sign out on the server. You are signed out on this device.',

    // ---- Errors (shared failure surface — ExceptionSentences) ------
    'errors.network' => 'Could not reach the server. Check the URL and your connection.',
    'errors.unexpected' => 'Something went wrong. Check the log for details.',

    // ---- RecommendationsScreen ----------------------------------------
    'recommendations.title' => 'For You',
    'recommendations.hint' => 'q  back      ↑↓  navigate      ⏎  open      x  dismiss',
    'recommendations.error_hint' => 'r  retry      q  back',
    'recommendations.load_failed' => 'Could not load the recommendations.',
    'recommendations.loading' => 'Loading recommendations…',
    'recommendations.empty' => "No recommendations yet.\n  Start watching to get personalized suggestions!",
    'recommendations.dismiss_failed' => 'Could not dismiss the recommendation.',
    'recommendations.crumb' => 'For You',

    // ---- DetailScreen -------------------------------------------------
    'detail.load_failed' => 'Could not load this title.',
    'detail.similar_load_failed' => 'Could not load the similar titles.',
    'detail.missing_episodes_load_failed' => 'Could not load the missing episodes.',
    'detail.children_load_failed' => 'Could not load this content.',
    'detail.rating_save_failed' => 'Could not save the rating: ',
    'detail.play_notice' => '▶  This title has no playable source.',
    'detail.actions_hint' => '▶  p  play      Esc  back',
    'detail.hint' => '↑↓  scroll synopsis      p  play      s  shuffle      C  cast      r  rate      F  favorite      w  watched      l  thumbs up      j  thumbs down      d  download      u  subtitles      Esc  back',
    'detail.container_hint' => '↑↓←→  move      ⏎  open      s  shuffle      Esc  back',
    'detail.error_hint' => 'r  retry      Esc  back',
    'detail.loading_hint' => 'Esc  back',
    'detail.loading' => 'Loading…',
    'detail.no_synopsis' => 'No synopsis available.',
    'detail.more_like_this' => 'More Like This',
    'detail.similar_navigate_hint' => '←→  navigate      ⏎  open',
    'detail.cast_label' => 'Cast',
    'detail.directed_by' => 'Directed by ',
    'detail.more_cast' => '  +{count} more',
    'detail.season' => 'season',
    'detail.season_plural' => 'seasons',
    'detail.episode' => 'episode',
    'detail.episode_plural' => 'episodes',
    'detail.item' => 'item',
    'detail.item_plural' => 'items',
    'detail.missing_episodes_one' => '⚠  {count} episode missing',
    'detail.missing_episodes_many' => '⚠  {count} episodes missing',
    'detail.loading_content' => 'Loading…',

    // ---- Player -------------------------------------------------------
    // Fragments compose PlayerScreen's status-line hints: leading double
    // spaces separate tokens, the key glyph stays untranslated, only the
    // word moves (house doctrine, review M-CONSOLE-11/91).
    'player.hint_main' => 'Space ⏯  ←→ ±10s  [ ] speed  m mode',
    'player.hint_captions_off' => '  c cc',
    'player.hint_captions_on' => '  c cc✓',
    'player.hint_quality' => '  v quality',
    'player.hint_next' => '  n next',
    'player.hint_sleep' => '  z ⏰ ',
    'player.hint_back' => '  q back',

    // ---- FilterBar ----------------------------------------------------
    'filter.search_placeholder' => '(type to filter)',
    'filter.search_label' => 'Search: ',
    'filter.sort_label' => 'Sort: ',
    'filter.sort_name' => 'name',
    'filter.sort_year' => 'year',
    'filter.sort_rating' => 'rating',
    'filter.sort_date_added' => 'date added',
    'filter.sort_runtime' => 'runtime',
    'filter.sort_genre' => 'genre',
    'filter.sort_artist' => 'artist',
    'filter.order_label' => 'Order: ',
    'filter.order_asc' => 'asc',
    'filter.order_desc' => 'desc',
    'filter.genre_label' => 'Genres: ',

    // ---- SearchScreen ----------------------------------------------
    'search.no_results_for' => 'No results for "{query}"',
    'search.results_one' => '{count} result',
    'search.results_many' => '{count} results',


    // ---- LibraryScreen -----------------------------------------------
    'library.no_items' => 'No items',
    'library.items_one' => '{count} item',
    'library.items_many' => '{count} items',


    // ---- SyncPlay errors ----------------------------------------------
    'syncplay.not_authenticated' => 'Sign in before using watch groups.',
    'syncplay.not_in_group' => 'You are not in a watch group.',
    'syncplay.not_host' => 'Only the group host can do that.',
    'syncplay.unknown_message' => 'The server did not recognize that request.',
    'syncplay.handler_error' => 'Something went wrong on the server.',
    'syncplay.protocol_version_mismatch' => 'This app is not compatible with the server version.',
    'syncplay.invalid_new_host' => 'The requested new host is not valid.',
    'syncplay.member_not_found' => 'That member was not found in the group.',
    'syncplay.same_host' => 'That member is already the group host.',
    'syncplay.create_failed' => 'Could not create the watch group.',
    'syncplay.join_failed' => 'Could not join the watch group.',
    'syncplay.leave_failed' => 'Could not leave the watch group.',
    'syncplay.group_limit_reached' => 'The host has reached the limit for watch groups.',
    'syncplay.group_not_found' => 'That watch group no longer exists.',
    'syncplay.invalid_password' => 'That password is not correct.',
    'syncplay.group_full' => 'That watch group is full.',
    'syncplay.queue_limit_exceeded' => 'The playback queue for that watch group is full.',
    'syncplay.unknown_error' => 'A sync error occurred.',

    // ---- SyncPlay modal (group noun = this catalog's glossary term) --
    'syncplay.modal_public_groups' => 'Public watch groups:',
    'syncplay.modal_create_new' => '+ Create new watch group',
    'syncplay.modal_create_title' => 'Create watch group',
    'syncplay.modal_joining' => 'Joining watch group…',

    'browse.loading' => 'Loading your libraries…',
    'browse.no_libraries' => 'No libraries yet.',
    'browse.no_libraries_admin' => 'Press : for the command palette, then Admin → Libraries to add one.',
    'browse.no_libraries_member' => 'Ask an administrator to add one.',
    'browse.hint_refresh_quit' => 'R  refresh      q  quit',

    // ---- Command palette (App::staticActions) ---------------------------
    'palette.search'     => 'Search',
    'palette.home'       => 'Home',
    'palette.settings'   => 'Settings',
    'palette.keys'       => 'Keyboard shortcuts',
    'palette.stats'      => 'Stats',
    'palette.watch_history'  => 'Watch History',
    'palette.favorites'  => 'Favorites',
    'palette.photos'     => 'Photos',
    'palette.music_artists'  => 'Music Artists',
    'palette.playlists'  => 'Playlists',
    'palette.collections'  => 'Collections',
    'palette.servers'    => 'Servers',
    'palette.shared_with_me'  => 'Shared With Me',
    'palette.invite_links'  => 'Invite Links',
    'palette.federation_shares'  => 'Federation Shares',
    'palette.show_metrics'  => 'Show metrics',
    'palette.hide_metrics'  => 'Hide metrics',
    'palette.admin'      => 'Admin',
    'palette.resume_playback'  => 'Resume playback',
    'palette.pause_playback'  => 'Pause playback',
    'palette.stop_playback'  => 'Stop playback',
    'palette.sign_out'   => 'Sign out',
    'palette.quit'       => 'Quit',
    'palette.go_to'      => 'Go to {name}',
    // CON-S17: auth/onboarding chrome + secondary surfaces
    'chrome.too_small_title' => 'Terminal too small.',
    'chrome.too_small_size' => 'Have {cols}×{rows} — Phlix needs at least {min_cols}×{min_rows}.',
    'chrome.too_small_resize' => 'Resize the window to continue.',
    'auth.signin_heading' => 'Sign in to Phlix.',
    'auth.signin_title' => 'Sign in',
    'auth.signin_progress' => 'Signing in…',
    'auth.signin_footer' => 'Tab  next      Enter  sign in      s  sign up      Esc  quit',
    'auth.signup_heading' => 'Create your Phlix account.',
    'auth.signup_title' => 'Sign up',
    'auth.signup_progress' => 'Creating account…',
    'auth.signup_footer' => 'Tab  next      Enter  sign up      Esc  back to sign in',
    'auth.insecure_warning' => '⚠ This server uses an insecure connection (http) — your password and session tokens travel unprotected.',
    'server.heading' => 'Connect to your Phlix server.',
    'server.title' => 'Setup',
    'server.footer' => 'Enter  connect      Esc  quit',
    'server.field_title' => 'Phlix server URL',
    'server.insecure_typed' => '⚠ http:// sends your password and session tokens unencrypted. Use https:// unless this is a trusted local network.',
    'server.invalid_url' => 'Enter a valid server URL (e.g. https://phlix.example.com).',
    'settings.heading' => 'Settings',
    'settings.logged_in_as' => 'Logged in as {name}',
    'settings.server_line' => 'Server: {url}',
    'settings.footer' => 'L  logout      S  servers      Enter  save      Esc  cancel',
    'syncplay.modal_none' => '(none available)',
    'syncplay.modal_more' => '… and {count} more',
    'syncplay.modal_hint_list' => '↑↓ select  Enter confirm  Esc cancel',
    'syncplay.modal_name_label' => 'Name:',
    'syncplay.modal_public' => 'Public',
    'syncplay.modal_private' => 'Private',
    'syncplay.modal_hint_create' => 'Enter create  ← back  type to edit name',
    'syncplay.modal_hint_cancel' => 'Esc cancel',
    'syncplay.modal_error_title' => 'Error',
    'syncplay.modal_error_default' => 'Could not complete the action.',
    'syncplay.modal_press_key' => 'Press any key to continue',
    'quality.auto' => 'Auto (recommended)',
];
