# Changelog

All notable changes to **phlix-console-client** are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- **docs**: Add `docs/clients/console.md` user-facing page covering installation, configuration, key bindings, and common usage scenarios (C10.1)
- **docs**: Add `docs/dev/client-console.md` architecture page covering component layout, screen hierarchy, and data-flow patterns for developers (C10.2)
- **cli**: Complete `bin/phlix help` output with all commands, flags, and usage examples; `bin/phlix doctor` now checks PHP version, required extensions, server connectivity, and config validity (C10.4)
- **docs**: Reconstructed server contracts for books, audiobooks, and photos endpoints in `docs/dev/client-console.md`, clarifying request/response shapes and pagination behavior (C10.6)
- **docs**: Cross-repo documentation sweep across both `phlix` and `phlix-console-client` clarifying setup instructions, architecture docs, and user guides (C10.7)

### Fixed

- **docs**: Fix README.md to reflect current PHP version requirement (8.3+), production-ready status, accurate key bindings list, and coherent install story (C10.3)
- **docs**: Fix stale docblocks throughout `src/` and `tests/` and triage deferral comments with either concrete issue references or completed markers (C10.5)

## [1.0.0] - 2026-08-08

### Added

- **auth**: Add user sign-up (C9.8)
- **settings**: Sync user settings with the server (C9.7)
- **download**: Allow downloading video files (C9.6)
- **discovery**: Add playlists and collections (C9.5)
- **discovery**: Add external subtitle search (C9.4)
- **discovery**: Add missing episodes row to detail screen (C9.3)
- **discovery**: Add dismiss recommendation (C9.2)
- **discovery**: Add shuffle play (C9.2)
- **discovery**: Add most-watched rail (C9.1)
- **discovery**: Add a More Like This row on the detail screen (C9.1)
- **cli**: Add `--version` / `-V` / `version` command printing `phlix {version}`. Unknown commands now exit `2` with error to STDERR instead of printing help. Split `help` and `default` case branches. Help text refactored to shared `HELP_TEXT` constant. (C0.5)
- **cli**: Add `run --selftest` mode that boots the full object graph and performs config-loaded, graph-wired (including `PlayerScreen`'s `SyncPlayService` null check), http-reachable, and decode-ok checks. Exits 0 on all pass, 1 otherwise. Honours `PHLIX_SERVER_URL`. (C0.6)
- **i18n**: Add internationalization scaffolding using SugarCraft's `T` translation system. Three screens are converted: `RecommendationsScreen`, `DetailScreen`, and `FilterBar`. Locale is auto-detected from `$LANG` / `$LC_ALL` / `$LC_MESSAGES` at boot. See `docs/i18n.md` for conversion guide. **Coverage is partial** — the remaining ~40 screens still need conversion. (C8.10)
- **player**: Add `completeSession()` to report playback completion. Sends `POST /api/v1/sessions/{id}/complete` when playback reaches 95% or natural end-of-stream. Guard prevents double-fire. Rejection raises `ShowToastMsg`. (C1.3)
- **syncplay**: `PlayerScreen` now constructs `SyncPlayService` (required, not optional). All 7 callbacks registered: `onPlaybackCommand`, `onMemberJoined`, `onMemberLeft`, `onHostChanged`, `onGroupState`, `onDisconnect`, `onError`. New `SyncPlayHostChangedMsg`, `SyncPlayDisconnectedMsg`, `SyncPlayGroupStateMsg` messages. (C1.2)
- **syncplay**: All four SyncPlay HTTP calls now use `/syncplay/groups` (not `/rooms`), leave uses `POST` (not `DELETE`), and response parsing reads `groups` key. DTOs renamed `SyncPlayRoom` → `SyncPlayGroup`. (C1.1)
- **ci**: Add PHP version matrix (8.3, 8.4, 8.5) with `fail-fast: false` (C9.2)
- **ci**: Add PHPCS PSR-12 gate for `src/` and `tests/` with `composer cs` and `composer cs:fix` scripts (C9.3)
- **ci**: Add `phpunit.xml.dist` coverage floor at 66% global, 70% per-file for `PlayerScreen` (C9.1)
- **build**: Add dual publish paths: master → rolling `latest` prerelease, tag → real release. Generate `SHA256SUMS` for both. Smoke-test before publish. Tag-vs-version consistency check. (C9.7)
- **tests**: Add PHPStan level 9 analysis for `tests/` directory (C9.4)
- **tests**: Add graphics decoder tests (`SixelDecoder`, `Iterm2Decoder`, `KittyDecoder`) and `PosterRenderTest` (C9.11)
- **Dependency drift is now detected automatically.** A scheduled workflow runs every
  Monday at 06:00 UTC (and can be triggered manually), pulling the latest `dev-master`
  resolves for all SugarCraft packages, running PHPStan and PHPUnit, and — only
  when both pass — committing the updated `composer.lock` directly to `master`.

### Changed

- **ci**: Add PHP CodeSniffer and configure PSR-12 (C9.9)
- **ci**: Remove Codacy coverage upload (C9.5)

### Fixed

- **player**: `PlayerScreen::fetchSiblings()` now pages through episodes in 100-item chunks (server maximum) when locating the current episode for next/previous navigation. Previously it requested up to 500 items which exceeded the server clamp and silently failed for series beyond the first page. Shows a toast error if the episode cannot be found after exhausting all pages (up to 10,000 episodes). (C1.5)
- **api**: `{success:false}` responses now throw `ApiError` instead of silently returning an empty DTO. The `decode()` method now centralises envelope handling for all 2xx responses, unwrapping `{success:true,data:...}` responses and surfacing `{success:false}` with the `message` or `error` field as the exception message. (C1.4)
- **discovery**: Remove dead null checks in `DetailScreen::onLoaded` (C9.1)
- **discovery**: Restore branching return contract for hero/ratings/similar batch (C9.1)
- **config**: `TokenStore::exists()` checks cache not file presence (C9.7)
- **config**: Store migration data as array so `load()` can read it back (C9.7)
- **tests**: Update `AppTest` for multi-server Config API and `TokenStore` changes (C9.7)
- **tests**: Update `ConfigTest` for multi-server API after C7.1 refactor (C9.7)
- **api**: Restructure VTT promise chain in `fetchCaptionsCmd()` from `.then(success, error)` to `.then(success)->catch(error)` to satisfy PHPStan level 9 type inference. When VTT fetch fails, shows `ShowToastMsg` with error. (C0.4)
- **A stalled server can no longer freeze the TUI on a shimmer skeleton for 60 seconds.** `BrowserTransport` now enforces a 15-second request timeout (configurable via the constructor) instead of relying on PHP's `default_socket_timeout` (~60 s). GET and HEAD requests that fail with a genuine connection or timeout error are retried exactly once after a 250 ms delay, while `POST`/`PUT`/`PATCH`/`DELETE` requests are not retried because they are not idempotent.
- **Fixed ensureRange() calculating lastOffset incorrectly causing multi-page windows to under-fetch data** (MediaStore and BooksStore). The lastOffset calculation used `$windowEnd = max($start, $end - 1)` but then computed `lastOffset = intdiv($windowEnd, $limit) * $limit` — when $end was the exact boundary of a page, this truncated the final page.
- **Search results now show poster images** (relative URLs resolved via baseUrl + resolveUrl())
- **Detail page hero images and child-episode thumbnails now render** (relative poster URLs resolved via `baseUrl` + `resolveUrl()`)
- **Photo library thumbnails now render** (`PhotoAlbumScreen` album covers and `PhotosScreen` per-album thumbnails). Both now apply `baseUrl` + `resolveUrl()` **before** the scheme check.
- **Library rails (Anime/TV) now show posters instead of stills** (`topLevel=1` in API request via `forLibrary()`).
- **Test coverage for the poster-URL fixes is now genuine.** `resolveUrl()` relative→absolute behavior is exercised against a real local HTTP server.
- **Admin forms keep an invalid submit open with an inline error instead of a toast.** candy-forms now gates submit on each field's validator.
- **Removed the dead `PlaybackInfo::$qualityLadder` field.** The quality picker is driven entirely by a real transcode job's `variants[]`. Corrected the `Rendition`/`PlaybackInfo`/`PlayerScreen` docblocks' endpoint attribution.

### Added — in-player quality selection

- **Quality picker overlay** in the player — press `v` to open a menu of **Auto** plus each ABR rung the active transcode advertises. ↑/↓ navigate, Enter pins the rung, Esc/`q` dismisses. The `v` key only appears when the item is actually being transcoded with a real ABR ladder.

### Added — Phase 8 (admin parity + Cast)

- **Admin menu**, exposed via the command-palette **Admin** action only when signed in as an admin. Every section is wired: Dashboard, Users, Plugins, Logs, Backup, Server Settings, Libraries, DLNA server, Remote Access, Live TV.
- **Cast** — `C` on a media detail screen discovers Chromecast / Roku / AirPlay / DLNA devices, sends the item, and drives a transport overlay (pause / resume / stop).

### Added — Phase 7 (theming + settings + polish)

- Three built-in themes — **Nocturne** (default), **Daylight**, **Midnight**.
- A **Settings** screen (palette-reachable) for theme and photo-slideshow interval, applied live.
- A persistent cross-screen **Now-Playing bar** so music and audiobook audio survives navigation.
- A palette-toggled diagnostic **metrics HUD** overlay and a read-only **Stats** screen.
- Animated **shimmer loading skeletons** for lists.
