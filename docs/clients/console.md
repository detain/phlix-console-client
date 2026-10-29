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
- In-player track pickers for the audio, subtitle, and chapter tracks an item
  exposes, plus a quality selector during transcoded playback
- SyncPlay watch parties: create or join a room from the player — the room's
  play / pause / seek commands drive your playback, with a live sync overlay
- In-player sleep timer (15 / 30 / 45 / 60 / 90 / 120 min presets; playback
  pauses when the countdown expires)
- Music and audiobook playback with progress saving
- Full admin panel access (when signed in as admin)
- Command palette (Ctrl-K or `:`) for quick actions
- Global search (`/`)
- Chromecast / DLNA / AirPlay / Roku casting

## Installation

### Composer (for development)

Clone over HTTPS (no SSH key needed) and run from the checkout:

```sh
git clone https://github.com/detain/phlix-console-client.git
cd phlix-console-client
composer install
bin/phlix run    # needs a real TTY; first run asks for your server URL
```

### PHAR (for end users)

CI publishes a **rolling pre-built PHAR** of the tip of `master` on every push,
carried as an asset of the GitHub Release tagged `latest` — marked
**prerelease**. No stable, versioned release has been cut yet (no `v*.*.*` tag
exists), and GitHub's `/releases/latest` shortlink deliberately excludes
prereleases, so `releases/latest/download/...` URLs **404** today. Download
from the `latest` **tag** path instead:

```sh
# Download the rolling master-build PHAR (tag path — not releases/latest)
curl -fsSL https://github.com/detain/phlix-console-client/releases/download/latest/phlix.phar -o phlix.phar

# Make it executable
chmod +x phlix.phar

# Run it (first run prompts for server URL)
./phlix.phar run

# Or install to PATH
sudo mv phlix.phar /usr/local/bin/phlix
phlix run
```

**Verify the download** (recommended):

```sh
# Fetch the checksums file (the asset is named SHA256SUMS, no .txt)
curl -fsSL https://github.com/detain/phlix-console-client/releases/download/latest/SHA256SUMS -o SHA256SUMS

# Verify (Linux; on macOS use `shasum -a 256 -c SHA256SUMS`)
sha256sum -c SHA256SUMS
```

**PHAR requirements:**

- PHP ≥ 8.3 with ext-gd, ext-mbstring, ext-intl, ext-ffi, ext-json (composer
  enforces these at install; a PHAR skips that check, so at runtime they are
  on you). ext-curl additionally powers the `doctor` / `run --selftest`
  reachability probes; pcntl/posix are optional (Ctrl-Z suspend, test harness).
- ffmpeg + ffprobe (video frame extraction)
- ffplay or mpv (for audio playback)
- A terminal with sixel/kitty/iTerm2 support (or `--mode=halfblock` fallback)

### Building the PHAR from source

`humbug/box` is already a declared dev dependency and `scripts/build-phar.sh`
auto-detects `vendor/bin/box`, so no separate box install step is needed:

```sh
composer install
./scripts/build-phar.sh
# Output: build/phlix.phar
# Building needs phar.readonly=0 (CI sets it); box reports a clear error
# if your PHP has it enabled.
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
| `token.json` | Auth tokens (access + refresh, keyed by server id) |
| `posters/` | Poster image cache (tiled per render mode) |

#### Server entries (`config.json` → `servers[]`)

| Key | Default | Description |
| --- | --- | --- |
| `id` | uuid | Entry identifier |
| `label` | `Server` | Name shown in the server switcher |
| `url` | — | API base URL (e.g. `https://host:8096` — the HTTP worker) |
| `hub_id` | unset | Hub this server belongs to (relay/remote features) |
| `ws_port` | `8097` | Dedicated SyncPlay WebSocket port. The client dials `ws(s)://{host}:{ws_port}/syncplay/{room}` and carries the JWT in the `Sec-WebSocket-Protocol: bearer, <jwt>` handshake header (phlix-server 424c14d0 carrier law; the `?token=` query is retired); omit it to use the server's stock `:8097` WS worker — the `:8096` HTTP worker never upgrades WebSockets. |

### Environment variables

| Variable | Default | Description |
| --- | --- | --- |
| `PHLIX_SERVER_URL` | from config | Override the server URL (useful for CI/testing) |
| `PHLIX_POSTER_CACHE_ENTRIES` | `16384` | Max poster cache entries |
| `PHLIX_HUB_URL` | — | `watch`: hub base URL (preferred over `--hub`) |
| `PHLIX_HUB_SERVER_ID` | — | `watch`: hub-side server id (preferred over `--server-id`) |
| `PHLIX_HUB_TOKEN` | — | `watch`: hub user access token used to mint relay tokens (preferred over `--hub-token`) |
| `PHLIX_RELAY_TOKEN` | — | `watch`: ready-made relay token, skips minting (preferred over `--relay-token`) |

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

### Hub relay consumer (`watch`)

`bin/phlix watch` connects to the hub relay and dispatches delivered
`play_media` commands to the player — headless, no TTY required. It needs
the hub URL, the hub-side server id, and a token (a ready-made relay token,
or a hub user access token it exchanges for one on each connect).

Bearer/JWT secrets have a carrier ladder, safest first:

```sh
# 1. Environment (preferred — invisible to ps(1))
PHLIX_HUB_URL=https://hub.example PHLIX_HUB_SERVER_ID=srv-7 \
PHLIX_HUB_TOKEN="$TOKEN" bin/phlix watch

# 2. STDIN (for scripts/wrappers that already hold the token)
bin/phlix watch --hub https://hub.example --server-id srv-7 \
    --relay-token-stdin < /run/secrets/relay_token

# 3. Literal argv — DEPRECATED, works but warns on every use
bin/phlix watch --hub ... --server-id ... --hub-token "$TOKEN"
```

Why the ladder exists (CWE-214): a secret passed as a command-line
argument is visible in `ps(1)` / `/proc/<pid>/cmdline` to every local user
for the whole life of the process, and stays in shell history. The
`--hub-token` / `--relay-token` flags are kept for script compatibility —
they still work, but print a `warning:` line on stderr naming the env and
STDIN replacements. (`--hub-token-stdin` / `--relay-token-stdin` read the
whole of STDIN as the token, trailing newline trimmed.)

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
| `a` | Audio-track picker (when the item exposes audio tracks) |
| `S` | Subtitle-track picker (shift; when the item has subtitle tracks — lowercase `s` stays skip intro/outro) |
| `C` | Chapter picker (shift; when the item has chapters — lowercase `c` stays toggle captions) |
| `v` | Quality selector (only shown during transcoded playback) |
| `n` / `p` | Next / previous episode |
| `w` | "For You" recommendations (opens the recommendations screen) |
| `y` | SyncPlay modal (create or join a room; while naming a new room, `p` toggles public/private) |
| `z` | Sleep-timer picker (presets 15/30/45/60/90/120 min; playback pauses when the countdown expires) |
| `f` | Toggle player chrome |
| `q` or `Esc` | Back / close player |

Transport keys are inert until the stream is up — `q`/`Esc` always close the
player. While a picker or modal is open it captures every keystroke:
`↑`/`↓` move, `Enter` picks (or confirms), `q`/`Esc` dismisses (the SyncPlay
modal steps back to the room list before closing when you are mid-creation).

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

Delete `~/.config/phlix/token.json` and run `bin/phlix run` to re-authenticate.

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
