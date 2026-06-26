# Changelog

All notable changes to **phlix-console-client** are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

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
