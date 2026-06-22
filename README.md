# phlix-console-client

A full-window **terminal (TUI) client for Phlix** — browse libraries, poster
grids, carousels, media detail, series/season/episode drill-down, search, a
command palette, and an in-terminal video player. Posters and video render as
**sixel / kitty / iTerm2 / half-block ANSI** via the
[SugarCraft](https://sugarcraft.github.io/) stack.

> **Status: Phase 0 (scaffold + render spike).** The build plan is
> [`../phlix_console_client.md`](../phlix_console_client.md). This phase proves
> the runtime, layout, and the poster/video rendering pipeline. Browse, library
> grids, detail, and the player land in later phases.

## Requirements

- **PHP ≥ 8.3** with `ext-gd` (image decode), `pcntl` + `posix` (TTY/raw mode).
- **ffmpeg** + **ffprobe** (video decode + frame grabs).
- **ffplay** or **mpv** (audio playback — Phase 4).
- A terminal with **sixel** or the **kitty**/**iTerm2** graphics protocol is
  recommended; **half-block** is the universal fallback.

## Install (local dev)

The SugarCraft libraries are resolved from the sibling monorepo checkout at
`../../sugarcraft/*` via symlinked Composer `path` repositories.

```sh
composer install
```

## Try the Phase 0 spike

```sh
# What can this terminal render?
bin/phlix doctor

# Render an image at 40 cells wide (best available protocol)
bin/phlix poster /path/to/poster.jpg 40
bin/phlix poster /path/to/poster.jpg 40 --mode=halfblock   # force a mode

# Decode the first 3 frames of a video to ANSI (ffmpeg)
bin/phlix frame /path/to/movie.mkv 3 60 20

# Launch the full-window shell (needs a real TTY)
bin/phlix run
```

`doctor`, `poster`, and `frame` are non-interactive and work over a pipe;
`run` needs an interactive terminal.

## Test

```sh
vendor/bin/phpunit
```

The video-decode test self-skips when `ffmpeg` is unavailable; the poster test
synthesizes its own image with `gd`, so the suite needs no fixtures.

## License

MIT
