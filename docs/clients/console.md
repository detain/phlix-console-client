# Phlix Console Client

A full-window terminal (TUI) client for Phlix — browse your media libraries,
view posters, explore series and episode detail, search, and play video
directly in your terminal.

## Overview

The console client connects to a Phlix server and renders posters and video
using terminal graphics protocols. It supports multiple render modes so it
works in both graphics-capable terminals (sixel, kitty, iTerm2) and any
terminal via Unicode/ANSI cell rendering.

**Key features:**

- Browse all library types: movies, TV shows, music, books, audiobooks, photos
- Poster grid with virtualized scrolling, filtering, and A–Z jump
- Series → season → episode drill-down with a breadcrumb trail
- Media detail screen with cast, chapters, and recommendations
- In-terminal video player with subtitle support and chapter markers
- Music and audiobook playback with progress saving
- Full admin panel access (when signed in as admin)
- Command palette (Ctrl-K or `:`) for quick actions
- Global search (`/`)
- Chromecast / DLNA / AirPlay / Roku casting

## Installation

### Composer (for development)

```sh
git clone git@github.com:detain/phlix-console-client.git
cd phlix-console-client
composer install
```

### PHAR (for end users)

Download a pre-built PHAR from the releases page:

```sh
# Download the latest release
curl -fsSL https://github.com/detain/phlix-console-client/releases/latest/download/phlix.phar -o phlix.phar

# Make it executable
chmod +x phlix.phar

# Run it (first run prompts for server URL)
./phlix.phar run

# Or install to PATH
sudo mv phlix.phar /usr/local/bin/phlix
phlix run
```

**PHAR requirements:**

- PHP ≥ 8.3 with ext-gd (image decoding)
- ffmpeg + ffprobe (video frame extraction)
- ffplay or mpv (for audio playback)
- A terminal with sixel/kitty/iTerm2 support (or `--mode=halfblock` fallback)

### Building the PHAR from source

```sh
composer require --dev humbug/box
./scripts/build-phar.sh
# Output: build/phlix.phar
```

## Configuration

### Config directory

The client stores configuration in:

- **Linux/macOS:** `~/.config/phlix/`
- **Other:** `$XDG_CONFIG_HOME/phlix/` if set, else `~/.config/phlix/`

Within that directory:

| File | Purpose |
| --- | --- |
| `config.json` | Server URL, theme, render mode preferences |
| `tokens.json` | Auth tokens (access + refresh) |
| `posters/` | Poster image cache (tiled per render mode) |

### Environment variables

| Variable | Default | Description |
| --- | --- | --- |
| `PHLIX_SERVER_URL` | from config | Override the server URL (useful for CI/testing) |
| `PHLIX_POSTER_CACHE_ENTRIES` | `16384` | Max poster cache entries |

## Usage

### Starting the client

```sh
# From source
bin/phlix run

# From PHAR
./phlix.phar run

# Force a specific render mode
bin/phlix run --mode=sixel
```

The first run asks for your Phlix server URL, then presents a login prompt.
On subsequent runs the stored tokens are used automatically.

### Render modes

Render modes control how posters and images are displayed:

**Cell modes** (tile as coloured text — work in any terminal):

| Mode | Description |
| --- | --- |
| `halfblock` (default) | 24-bit colour half-block characters (▀). Universal fallback. |
| `quarterblock` | Denser quarter-block characters (▘▝▖▗) |
| `ascii` | Monochrome character ramp |
| `ansi256` | 256-colour character ramp |
| `truecolor` | 24-bit-colour character ramp |

**Graphics modes** (real images — require terminal support):

| Mode | Description |
| --- | --- |
| `sixel` | Sixel protocol (most compatible graphics mode) |
| `kitty` | Kitty protocol |
| `iterm2` | iTerm2 inline images |

```sh
# List what your terminal supports
bin/phlix doctor

# Force halfblock mode explicitly
bin/phlix run --mode=halfblock

# Render a single image at 40 cells wide
bin/phlix poster /path/to/poster.jpg 40
```

### Keybindings

#### Navigation

| Key | Action |
| --- | --- |
| `↑↓←→` | Move focus / scroll |
| `Enter` | Open selected item |
| `Esc` | Go back / dismiss |
| `Tab` | Switch focus (e.g., sidebar ↔ poster grid on home) |
| `Ctrl-C` | Quit |
| `A–Z` | Jump to a letter in a grid (A–Z rail) |

#### Search and commands

| Key | Action |
| --- | --- |
| `/` | Global search (or filter within a grid) |
| `Ctrl-K` or `:` | Open command palette |

#### Media playback

| Key | Action |
| --- | --- |
| `p` | Play media item (opens the in-terminal player) |
| `Space` | Play / pause (in player) |
| `←` / `→` | Seek ±10 seconds |
| `0–9` | Seek to percentage (0 = 0%, 5 = 50%, etc.) |
| `[` / `]` | Adjust playback speed |
| `m` | Cycle render mode in player |
| `s` | Skip intro/outro (if markers are set) |
| `o` | Start over |
| `c` | Toggle captions |
| `v` | Quality selector (only shown during transcoded playback) |
| `n` / `p` | Next / previous episode |
| `f` | Toggle player chrome |
| `q` or `Esc` | Back / close player |

#### Media detail

| Key | Action |
| --- | --- |
| `C` | Cast to Chromecast/DLNA/AirPlay/Roku (on detail screen) |
| `Space` | Play / pause (for music/audiobook) |
| `r` | Resume audiobook from saved position |

### Screens

| Screen | Description |
| --- | --- |
| **Home** | Library rails + sidebar, tab between them |
| **Library** | Virtualized poster grid for a single library |
| **Detail** | Hero poster, metadata, synopsis, cast, chapters, recommendations |
| **Series** | Season list → episode list with breadcrumbs |
| **Music** | Album list → track table; Enter plays |
| **Audiobooks** | List → chapter table; Enter plays, `r` resumes |
| **Books** | Cover grid → detail with download URL |
| **Photos** | Album covers → thumbnail grid → fullscreen viewer |
| **Player** | In-terminal video player with scrubber and controls |
| **Search** | Global debounced search over virtualized results grid |
| **Command palette** | Fuzzy-ranked actions: search, jump, settings, stats, admin |
| **Admin** | Dashboard, users, plugins, logs, backups, server settings, libraries, DLNA, remote access, live TV |
| **Settings** | Theme selection, photo slideshow interval |
| **Stats** | Diagnostic metrics HUD |

## Troubleshooting

### "Composer autoloader not found"

Run `composer install` in the project directory.

### "needs a TTY" / blank screen on `phlix run`

`bin/phlix run` requires an interactive terminal. If you're running over SSH,
use `ssh -t user@host 'bin/phlix run'` or a terminal multiplexer (tmux, screen).

### No graphics / all text

Your terminal likely doesn't support the selected graphics protocol. Try:

```sh
bin/phlix doctor
# Shows which protocols your terminal supports
bin/phlix run --mode=halfblock
# Force text-cell mode
```

### Posters not showing in graphics mode

1. Check `bin/phlix doctor` — if it doesn't list sixel/kitty/iterm2 as
   supported, your terminal doesn't support those protocols.
2. Use `--mode=halfblock` as a fallback.
3. Ensure the `posters/` cache directory is writable.

### Video playback fails

- **HEVC/AV1 direct play:** Ensure ffmpeg is installed and the stream is
  compatible. The player falls back to server-side transcoding if direct play
  isn't possible.
- **"No playable file" errors:** Some media items lack a direct stream URL.
  The player attempts transcode fallback automatically.

### Token expired / session expired

Delete `~/.config/phlix/tokens.json` and run `bin/phlix run` to re-authenticate.

### Slow poster loading

The first run fetches and tiles all posters for the selected render mode.
Subsequent runs are cached in `~/.config/phlix/posters/`. To clear the cache:

```sh
rm -rf ~/.config/phlix/posters/
bin/phlix run
```

### PHP segfaults or memory errors

Increase PHP memory limit for very large libraries:

```sh
PHLIX_POSTER_CACHE_ENTRIES=8192 bin/phlix run
# or in php.ini: memory_limit = 512M
```

## Getting help

- **Issues:** https://github.com/detain/phlix-console-client/issues
- **Discussions:** https://github.com/detain/phlix-console-client/discussions
