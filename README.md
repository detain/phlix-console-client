# phlix-console-client

A full-window **terminal (TUI) client for Phlix** — browse libraries, poster
grids, carousels, media detail, series/season/episode drill-down, search, a
command palette, and an in-terminal video player. Posters and video render as
**sixel / kitty / iTerm2 / half-block ANSI** via the
[SugarCraft](https://sugarcraft.github.io/) stack.

> **Status: Phases 0–3 complete.** The build plan is
> [`../phlix_console_client.md`](../phlix_console_client.md). Working today: log
> in, browse your libraries as poster rails beside a sidebar, open a library into
> a virtualized poster grid (scroll, filter, sort, A–Z jump), open any poster
> into a **detail screen** (hero poster, metadata, synopsis), and drill
> **series → season → episode**, with a **breadcrumb trail** in the header. The
> in-terminal **player** (direct-play via ffmpeg) is Phase 4 — the detail screen
> already shows the Play entry-point. Search, the command palette, and
> music/books/photos land in later phases.

## Requirements

- **PHP ≥ 8.3** with `ext-gd` (image decode), `pcntl` + `posix` (TTY/raw mode).
- **ffmpeg** + **ffprobe** (video decode + frame grabs).
- **ffplay** or **mpv** (audio playback — Phase 4).
- A terminal with **sixel** or the **kitty**/**iTerm2** graphics protocol is
  recommended; **half-block** is the universal fallback.

## Install

The SugarCraft libraries are normal Composer dependencies, pulled from
Packagist (`sugarcraft/*`, currently tracking `dev-master`).

```sh
composer install
```

> **Temporary:** `sugarcraft/sugar-reel` and `sugarcraft/candy-focus` are not yet
> registered on Packagist (their repos live under `github.com/sugarcraft/`), so
> `composer.json` carries a `vcs` repository entry for each. Once they're
> submitted to Packagist, delete those `repositories` entries — nothing else
> changes. (`candy-shine`, `sugar-crumbs`, and `sugar-gallery` already resolve
> from Packagist.)

### Developing against unreleased library changes

To test the client against local, unmerged SugarCraft changes, add a path
repository **without committing it**:

```sh
composer config repositories.sugarcraft path '../../sugarcraft/*'
composer update 'sugarcraft/*'
# revert before committing:  git checkout composer.json
```

## Run it

```sh
# Launch the full-window app (needs a real TTY): first run asks for your server
# URL, then log in. Browse rails + sidebar → open a library → grid → open a
# poster → detail → drill series/season/episode. Esc walks back; Ctrl-C quits.
bin/phlix run
```

Keys: `↑↓←→` move · `⏎` open · `/` filter (in a grid) · A–Z jump · `p` play
(Phase 4) · `Tab` switch focus on the home screen · `Esc` back · `Ctrl-C` quit.

### Render diagnostics

```sh
# What can this terminal render?
bin/phlix doctor

# Render an image at 40 cells wide (best available protocol)
bin/phlix poster /path/to/poster.jpg 40
bin/phlix poster /path/to/poster.jpg 40 --mode=halfblock   # force a mode

# Decode the first 3 frames of a video to ANSI (ffmpeg)
bin/phlix frame /path/to/movie.mkv 3 60 20
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
