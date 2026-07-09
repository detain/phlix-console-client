# Changelog

All notable changes to **phlix-console-client** are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Fixed

- **Admin forms keep an invalid submit open with an inline error instead of a
  toast.** candy-forms now gates submit on each field's validator (upstream
  `candy-forms: gate Form submit on validation`), so the Backup *Schedule* and
  Live TV *rename* / *series-rule* forms no longer push an invalid value through
  to a screen-level boundary guard — a blank name, a `0` interval, a negative
  priority, etc. keeps the form open showing the field's `! …` error and fires
  no request. Removed the now-dead guard-plus-toast workarounds (and the unused
  `buildRuleFormFrom` re-prefill helper that only existed to escape candy-forms'
  old post-submit wedge). Also fixed upstream in candy-forms: a blocked submit
  on a multi-field form now blurs the old field and focuses the erroring one
  (cursor no longer strands on the last field).
- **Removed the dead `PlaybackInfo::$qualityLadder` field.** It was populated
  from `GET /api/v1/media/{id}/playback`, which never sends `quality_ladder`
  (that lives on the distinct `/playback-info` route), and was read nowhere —
  the quality picker is driven entirely by a real transcode job's `variants[]`.
  Corrected the `Rendition`/`PlaybackInfo`/`PlayerScreen` docblocks' endpoint
  attribution accordingly, and noted that a sub-240p source yields a `{height}p`
  fallback rung id. (phlix quality-program I1 cross-repo review finding.)


### Added — in-player quality selection

- **Quality picker overlay** in the player — press `v` to open a small menu of
  **Auto** (server-driven ABR, the master multi-variant stream) plus each ABR
  rung the active transcode advertises (e.g. 1080p, 720p, 480p…), highest first.
  ↑/↓ navigate, Enter pins the highlighted rung, Esc/`q` dismisses. Picking a
  rung stops the current decoder, rebuilds playback from that rung's own signed
  playlist URL, and re-seeks to where you were; picking Auto returns playback to
  the server-driven master stream. The `v` key — and its hint in the player's
  bottom bar — only appears when the item is actually being transcoded with a
  real ABR ladder; direct-played and legacy/unscanned items have nothing to
  switch between, so the picker is silently unavailable there.

### Added — Phase 8 (admin parity + Cast)

- **Admin menu**, exposed via the command-palette **Admin** action only when signed
  in as an admin. Every section is wired:
  - **Dashboard** — now-playing sessions, storage usage, top users, top media,
    recent activity.
  - **Users** — list with a status filter (All / Pending / Active / Disabled) and
    per-row approve / disable / reject / delete / toggle-admin / reset-password
    (reset reveals the new password once).
  - **Plugins** — list with enable / disable / uninstall and install-from-URL.
  - **Logs** — file list with a single-file or merged "all logs" tail viewer.
  - **Backup** — list / create / delete / restore / upload-to-S3 plus a schedule
    editor.
  - **Server Settings** — per-key typed editing (inline bool toggle; int / float /
    string / JSON via a validated input).
  - **Libraries** — scan / rescan / match-metadata with a live scan-status readout.
  - **DLNA server** — status with start / stop.
  - **Remote Access** — Hub / subdomain / relay / port-forward status with toggles
    (interactive pairing wizard remains on the web admin).
  - **Live TV** — five tabbed sections (Tuners / Channels / Guide / Recordings /
    Series Rules) with list + simple actions (create / edit deferred to the web
    admin).
- **Cast** — `C` on a media detail screen discovers Chromecast / Roku / AirPlay /
  DLNA devices, sends the item, and drives a transport overlay (pause / resume /
  stop). Seek is intentionally omitted (no uniform position across the cast
  backends).

### Added — Phase 7 (theming + settings + polish)

- Three built-in themes — **Nocturne** (default), **Daylight**, **Midnight**.
- A **Settings** screen (palette-reachable) for theme and photo-slideshow interval,
  applied live.
- A persistent cross-screen **Now-Playing bar** so music and audiobook audio
  survives navigation.
- A palette-toggled diagnostic **metrics HUD** overlay and a read-only **Stats**
  screen.
- Animated **shimmer loading skeletons** for lists.
