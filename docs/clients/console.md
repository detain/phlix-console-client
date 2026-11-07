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
- Media detail screen with cast, a scrollable synopsis, and a "More Like This"
  rail of similar titles
- In-terminal video player with subtitle support and chapter markers
- In-player track pickers for the audio, subtitle, and chapter tracks an item
  exposes, plus a quality selector during transcoded playback
- SyncPlay watch parties: create or join a room from the player — the room's
  play / pause / seek commands drive your playback, with a live sync overlay
- In-player sleep timer (15 / 30 / 45 / 60 / 90 / 120 min presets; playback
  pauses when the countdown expires)
- Music and audiobook playback with progress saving
- Item downloads to disk from the media detail screen (`d`); book detail
  offers a copyable download URL to open in a browser or e-reader
- Full admin panel access (when signed in as admin)
- Command palette (Ctrl-K or `:`) for quick actions
- Global search (`/`)
- Chromecast / DLNA / AirPlay / Roku casting

## Where does this fit? (server, hub, relay)

Phlix deployments have two kinds of backends and one channel between them:

- **Server** — your media server: the machine that holds the library and
  serves authentication, metadata and playback. The console connects to a
  server directly for everything it shows.
- **Hub** — an optional coordination server that links several servers and
  federates them with other people's hubs. A hub stores no media. The
  hub-facing screens spell out their direction: **Shared With Me** is
  incoming library shares (what other hubs have shared toward you),
  **Federation Shares** is outgoing ones (what your hub has shared away),
  and **Invite Links** mints the tokens that grant other people access.
- **Relay** — the WebSocket tunnel a hub uses to reach your server without
  a public address. The console is on the executing end of it: `bin/phlix
  watch` runs on a server, listens on the relay, and plays what the hub
  delivers (see [Hub relay consumer (`watch`)](#hub-relay-consumer-watch)).

The player's **SyncPlay watch parties** are unrelated to all of this: they
talk to your server's own sync endpoint, not to a hub.

## Installation

> Front door first: the repository [README](../../README.md#install) carries the
> same install steps in the short form GitHub readers arrive at; this section is
> the manual's full version. The pages are not word-identical — they are
> maintained against the same scripts, so a changed install step changes on
> both pages in the same commit.

### Composer (for development)

Clone over HTTPS (no SSH key needed) and run from the checkout:

```sh
git clone https://github.com/detain/phlix-console-client.git
cd phlix-console-client
composer install
bin/phlix run    # needs a real TTY; first run asks for your server URL
```

(Examples below run `bin/phlix …`, the checkout layout; a PATH-installed PHAR
takes the same commands as `phlix …`.)

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
  on you). `ext-ffi` is a hard runtime dependency: sugar-pty drives the
  terminal through it, so without it the client dies at boot. ext-curl additionally powers the `doctor` / `run --selftest`
  reachability probes and `watch`'s hub relay-token mint (without it `watch`
  needs an explicit `--relay-token`); pcntl/posix are optional (Ctrl-Z
  suspend, test harness).
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

- `$XDG_CONFIG_HOME/phlix/` whenever `XDG_CONFIG_HOME` is set — on every platform
- otherwise `~/.config/phlix/` — on Windows `~` resolves to `%USERPROFILE%` when
  `HOME` is unset, and to the system temp directory when neither variable is set

Within that directory:

| File | Purpose |
| --- | --- |
| `config.json` | Server entries, active server, theme, slideshow interval — render mode is NOT stored here; it is chosen per invocation with `--mode` (see Render modes) |
| `token.json` | Auth tokens (access + refresh, keyed by server id) |
| `posters/` | Poster image cache (tiled per render mode) |

#### Server entries (`config.json` → `servers[]`)

| Key | Default | Description |
| --- | --- | --- |
| `id` | uuid | Entry identifier |
| `label` | `Server` | Name shown in the server switcher |
| `url` | — | API base URL (e.g. `https://host:8096` — the HTTP worker) |
| `hub_id` | unset | Hub this server belongs to (relay/remote features) |
| `ws_port` | `8097` | Dedicated SyncPlay WebSocket port: the client dials `ws(s)://{host}:{ws_port}/syncplay/{room}` and carries the JWT in the `Sec-WebSocket-Protocol: bearer, <jwt>` handshake header (never in the URL). Keep the stock `8097` — the `:8096` HTTP worker never upgrades WebSockets. |

#### Insecure (`http://`) servers

An `http://` URL stays accepted — LAN and dev servers are a real user's
reality — but it is never silent: the setup and add-server forms warn while you
type one, saving one fires a warning toast, saved entries carry a `⚠` mark in
the server switcher, and the Login/Sign-up screens repeat the exposure where the
password is about to be typed. While an `http://` server is active, every
ordinary frame carries an `⚠ insecure http` banner on its top border. Login
passwords and session tokens on such a connection travel unencrypted; prefer
`https://` anywhere the wire is not wholly under your control.

### Environment variables

Every `PHLIX_*` variable is read from the process environment (`getenv`) and,
where it exists, takes precedence over the stored `config.json` value. The
diagnostics commands probe over HTTP with fixed timeouts (10 s in `doctor`,
20 s in `run --selftest`).

| Variable | Default | Description |
| --- | --- | --- |
| `PHLIX_SERVER_URL` | from config | Override the server URL (useful for CI/testing) |
| `PHLIX_POSTER_CACHE_ENTRIES` | `16384` | Max poster cache entries |
| `PHLIX_POSTER_CONCURRENCY` | `6` | Max posters fetched simultaneously |
| `PHLIX_DOWNLOAD_DIR` | `~/Downloads` | Where downloads are saved (`~`, `$VAR` and `${VAR}` are expanded) |
| `PHLIX_HUB_URL` | — | `watch`: hub base URL (preferred over `--hub`) |
| `PHLIX_HUB_SERVER_ID` | — | `watch`: hub-side server id — the id the hub assigns your server when it pairs (list yours with `GET /api/v1/me/servers` on the hub; preferred over `--server-id`) |
| `PHLIX_HUB_TOKEN` | — | `watch`: hub user access token used to mint relay tokens (preferred over `--hub-token`) |
| `PHLIX_RELAY_TOKEN` | — | `watch`: ready-made relay token, skips minting (preferred over `--relay-token`) |

## Languages

The interface ships seven catalogs: English (`en`), German (`de`), Spanish
(`es`), French (`fr`), Italian (`it`), Japanese (`ja`) and Brazilian
Portuguese (`pt-br`). The locale is picked at launch from the environment
chain `LC_ALL` → `LC_MESSAGES` → `LANG`, so

```bash
LC_ALL=de_DE.UTF-8 phlix run
```

runs the German UI. Region and encoding suffixes are normalized
(`pt_BR.UTF-8` → `pt-br`), anything unsupported falls back to English, and a
locale that lags the English catalog degrades per key to English rather than
rendering blanks.

Coverage is **partial**, and the split is by surface, not by language:
the catalogs carry the media **detail** screens, the player hint line, the
grid filter bar, the "For You" recommendations, the SyncPlay error sentences
and the shared error/session arms (an expired sign-in localizes everywhere it
can strike). The browsable and playable *chrome* — the library grid, search,
playlists, favorites, watch history — plus the sign-in and registration
forms, the settings screens, server management, album and photo screens, the
hub-sharing screens and the typed-confirm prompts render English
hard-coded strings; on those screens only the shared expiry/error sentence
localizes. The authoritative per-screen list lives in [the i18n
guide](../i18n.md) under “Remaining Screens”.

The contributor-side story (adding screens, adding locales)
is in [the i18n guide](../i18n.md).

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

The first run asks for your Phlix server URL, then presents a sign-in prompt.
On subsequent runs the stored tokens are used automatically. If you don't have
an account on that server yet, press `s` at the sign-in prompt to open the
**Register** form and sign up in place (username, email, password — sent to
`POST /api/v1/auth/register`); a server that requires approval reports your
account as pending, and one with signups disabled says so.

### Command inventory

| Command | What it does |
| --- | --- |
| `run [--mode=NAME] [--selftest]` | Launch the full-window app (needs a TTY). `--selftest` runs the wiring ladder headless — boot, config, container, renderer — and exits. |
| `watch [--hub URL] [--server-id ID] [--once] [--timeout SECS]` | Consume hub-relay `play_media` deliveries, headless (see below). |
| `doctor` | Print terminal, extension, config and server diagnostics; also names the token and poster-cache paths to remove. |
| `poster <image> [w] [h]` | Render one image at a cell size and exit — graphics-protocol probe. |
| `frame <video\|gif> [n] [cols] [rows]` | Decode N video frames to ANSI and exit. |
| `version` (`-V` / `--version`) | Print the client version stamp. |
| `help` (`-h` / `--help`) | Print the full usage text. |

Exit codes: `0` success, `1` runtime failure (unreachable server, bad
token, playback error), `2` usage error (unknown command or mode — `help`
and `version` never exit 2, they are the recovery path).

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

`--once` is the one-shot form of the same consumer: `watch` waits for a single
`play_media` command, dispatches it, and exits; `--timeout SECS` (default 30)
bounds that wait. Exit codes: 0 = a command was dispatched, 1 = the run ended
without one (the connection failed, or a `--once` wait timed out), 2 = usage
error (bad flag or value).

### Render modes

Render modes control how posters and images are displayed. The mode is a
per-invocation choice — the `--mode` flag at launch (see Usage). It is never
stored in `config.json` and does not persist across launches:

**Cell modes** (tile as coloured text — work in any terminal that speaks UTF-8):

| Mode | Description |
| --- | --- |
| `halfblock` | 24-bit colour half-block characters (▀). Last-resort fallback when no graphics protocol answers. |
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

The tables list canonical names. `auto` — also the default when no mode is
given — means different things per command: `run` and `poster` resolve it to
the universal half-block cell renderer with **no terminal probe** (the safe
default; graphics protocols are an explicit `--mode=sixel`/`--mode=kitty`/
`--mode=iterm2` away), while `frame` probes the terminal through sugar-video
and picks the best mode it detects. `run` and `poster` additionally resolve
the sugar-image aliases `half`/`ansi` (≡ `halfblock`), `quarter`
(≡ `quarterblock`) and `chafa`; `frame` renders through sugar-video's own
vocabulary and takes the nine canonical names only — it rejects those four
aliases in `--mode` form (exit 2), and a bare trailing name outside its set
is simply not treated as a mode (it falls to the defaults silently). Forms:
`--mode=NAME`, `--mode NAME`, or a bare `NAME` as the final argument to
`run`/`poster`/`frame` — the bare form counts only names that command accepts.

A note on the character set: “universal” above means colour and protocol
reach, not glyph reach. The block-drawing ramp (▀ ▘▝▖▗ ░) and the UI's
punctuation glyphs (`⏎` for Enter, `›` in breadcrumbs, the middle-elision
` … `, rating/danger marks) are all non-ASCII and need a UTF-8 locale to
render; in `LANG=C` or a legacy code-page terminal they arrive as mojibake
or boxes. Set a UTF-8 locale (`LANG=en_US.UTF-8` is enough on virtually
every modern system). `--mode=ascii` switches the **poster ramp** to pure
ASCII, but the interface glyphs themselves are not mode-switched — they
follow the terminal's character set.

```sh
# List what your terminal supports
bin/phlix doctor

# Force halfblock mode explicitly
bin/phlix run --mode=halfblock

# Render a single image at 40 cells wide
bin/phlix poster /path/to/poster.jpg 40
```

### Accessibility and basic terminals

The client reads two standard environment variables before any colour is
emitted, and honours them across every styled surface (header brand, status
hints, rating stars, badges, danger marks, cast-avatar fields):

| Signal | Effect |
| --- | --- |
| `NO_COLOR` set to **any non-empty value** | Colour drops out entirely — text and layout unchanged. A literal `NO_COLOR=0` still disables (the convention is presence-based; an empty value does **not** disable). |
| `TERM` unset, empty, `dumb`, or `unknown` | Same suppression — for line-mode terminals (`ed`-style linters, some screen sessions), shells that never advertise a terminal type (minimal containers, tmux/screen misconfigs — the ncurses convention treats an unset `TERM` as the most basic terminal), and ncurses' own `unknown` fallback. |

Suppression is colour-only by design: the structural attributes — **bold**
emphasis, faint avatar initials, reverse-video selection cursor — survive, so
what is selected and what is emphasized stays visible without any palette.
The default theme (Nocturne) keeps all **chrome** colourless — the header
brand and status hints emit plain text — while **content widgets** (rating
stars, recommendation badges, card titles, the clear-rating mark, cast-avatar
backgrounds) carry the historical palette. Under `NO_COLOR` or a basic `TERM`
those widget colours drop to attribute-only output too (the bold emphasis on
stars, badges and the danger mark survives), so a screen reader or a
line-mode terminal still sees the same text and structure as everywhere else.

- Live window resizing is handled: the terminal's size-change signal re-flows
  every screen in the stack — including the ones you have navigated away
  from, the command palette, and open overlays (rating picker, track lists,
  SyncPlay modal) — so a resized window never needs a restart. When a poster
  grid grows, the newly revealed cells are fetched immediately rather than
  sitting blank until the next keypress.
- Small and basic setups:

- The interface is designed for at least **80×24**. Below that nothing
  crashes: the body area is `rows − 8` lines deep (floored at zero) and the
  status hint line is fitted by whole-word middle elision (` … ` marks the
  gap) while each hint's safety key — `q  quit`, `Esc  back` — stays anchored
  at the end.
- Posters never need colour or graphics protocols: `bin/phlix doctor` reports
  what your terminal supports, and `bin/phlix run --mode=ascii` renders
  posters as a monochrome character ramp on any terminal.
- Themes are chosen on the **Settings** screen and follow your account; an
  unknown stored value falls back to Nocturne rather than failing. The two
  non-default themes exist for terminals the default palette cannot serve:
  **Daylight** remaps every role that is illegible on a light background
  (Nocturne's pale text and badges vanish there), and **Midnight** lifts the
  recommendation badge onto its accent because the blue `#0066cc` disappears
  on a dark field.
- Loading grids animate: a shimmer band sweeps the skeleton at about 8
  frames per second. The heartbeat is armed only while the top screen is
  actually loading and stops the moment it is not, so an idle screen is
  perfectly still. The sweep is a position animation, not a colour one:
  `NO_COLOR` removes its palette but the band still moves while a list
  loads, and there is no static-skeleton opt-out yet.

### Keybindings

Paste works everywhere text can be typed: the client arms bracketed-paste
mode, so the terminal hands over the whole blob in one envelope and the
client replays it as keystrokes into the focused input. Invisible control
characters (anything Unicode classes as `Other`/`Control`) are dropped in
the replay — a pasted secret with a stray `\r`/ANSI remnant cannot smuggle
commands through an input field. Newlines and tabs in the blob are dropped
too — a multi-line clipboard flattens into one continuous run of keystrokes
and will never press Enter for you.

#### Navigation

| Key | Action |
| --- | --- |
| `↑↓←→` | Move focus / scroll |
| `PgUp` / `PgDn` | Page up / down a poster grid (library, search, photos, albums, detail rails, admin logs) |
| `Home` / `End` | Jump to the first / last item in a grid |
| `Enter` | Open selected item |
| `Esc` | Go back / dismiss |
| `Tab` | Switch focus (e.g., sidebar ↔ poster grid on home) |
| `Ctrl-C` | Quit |
| `A–Z` | Jump to a letter in a grid (A–Z rail — live only while the grid is sorted by name; under another sort the press answers with a notice why) |
| `R` | Refresh — re-fetch the home rails (on the home screen only `R` works; a library grid answers `r` too) |
| `s` | Shuffle-play the focused card (home rails, library grids, media detail) |
| `c` | Clear the grid filter when one is set; otherwise jump to the letter C (like any letter key) (library grids) |
| `u` / `n` | Jump to the Up Next rail (home; both letter cases accepted) |
| `[` / `]` | Page the Most Watched rail (home, while it is focused) |
| `q` | Quit the home browse view |

#### Search and commands

| Key | Action |
| --- | --- |
| `/` | Global search (or filter within a grid) |
| Filter bar keys | While the library filter bar is open: `Tab` cycles to the next field (shift-`Tab` back), `←`/`→` change the focused field's value, `c` clears all filters, `Esc` closes the bar (applied filters stay) |
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
| `p` | Play this title in the in-terminal player |
| `C` | Cast to Chromecast/DLNA/AirPlay/Roku (on detail screen) |
| `r` | Rate — opens the star picker (over a failed load this key is instead the retry the error frame shows; the full `r`/`F`/`w`/`l`/`j` loop is in Watching & ratings below) |
| `u` | Search external subtitles for this title (candidates reported as a toast) |
| `s` | Shuffle-play from this title (the footer spells it `s shuffle`; home rails and grids carry the same key) |
| `d` | Download this title to disk (saved to the download directory — see Downloads below) |

#### Watching & ratings

The core daily loop — rate, favorite, mark watched, thumbs — lives in the
detail screen footer (`detail.hint`) and the home hints. All of it is written
to your account on the server; nothing stays device-local.

| Key | Action |
| --- | --- |
| `r` | Rate — opens the star picker: `←`/`→` or `1`–`6` choose (`1` clears, `2`–`6` give 1–5 stars = ratings 2/4/6/8/10), `Enter` confirms, `Esc` cancels |
| `F` | Toggle favorite (detail screen; the footer spells it shift-case) |
| `w` | Toggle watched — the manual completion mark (on the detail screen; in the player `w` opens "For You" recommendations) |
| `l` | Thumbs up — like level +2; pressing again at +2 clears it to 0 |
| `j` | Thumbs down — like level −2; pressing again at −2 clears it to 0 |
| `a` | Home rails — cycle the watch filter All → Watched → Unwatched → ♥ Favorites; the current mode shows in the rails hint as `filter(…)` |
| `H` | Home sidebar — open watch history (both letter cases accepted) |
| `P` | Home sidebar — open playlists (both letter cases accepted) |

The detail-screen toggles and the rating are optimistic: the screen flips
immediately and reverts if the server round-trip fails. What the server
stores per user: `POST`/`DELETE /api/v1/media/{id}/favorite`,
`POST /api/v1/media/{id}/watched` and `/unwatched`,
`PUT /api/v1/media/{id}/like` (a signed level from −2 to +2), and
`POST /api/v1/media/{id}/ratings` (clear via `DELETE` on the singular
`/rating`).

Where it surfaces back: the home **Continue Watching** and **Up Next** rails
read your own positions (the player reports progress while you watch); the
♥ Favorites rail the `a` filter swaps in is served from
`GET /api/v1/users/me/favorites`; the history screen that `H` opens reads
`GET /api/v1/users/me/recently-watched`. **Most Watched** is a server-wide
trending rail, not a personal one. The "For You" recommendations (`GET
/api/v1/me/recommendations`) build from your watching — with nothing to
show yet, the screen says so.

#### Screen-specific keys

Navigation, search, palette and playback keys are covered above; these are the
keys each individual screen adds, consolidated from the handlers (the
[Screens](#screens) table keeps the prose descriptions):

| Screen | Keys |
| --- | --- |
| All screens | `Ctrl-Z` suspends the client to the background; resume it with `fg` — needs the optional `pcntl`/`posix` extensions (see **PHAR requirements** above; a lean PHP build without them has no suspend) |
| Photos → viewer | `←`/`→` previous/next photo (no wrap; manual paging resets the slideshow countdown), `i` EXIF panel on/off, `s` start/stop the slideshow, `Esc` back |
| Cast — device list | `↑`/`↓` select, `Enter` start casting, `r` rescan for devices, `q`/`Esc` leave |
| Cast — transport (session live) | `Space` play/pause, `r` refresh status, `x` stop the session (when the device allows stopping), `←`/`→` seek ∓10 s (`Shift`+arrow: ∓60 s, when the device allows seeking), `Esc` back to the device list |
| Music / Audiobooks | `↑`/`↓` select, `Enter` play; on the album track table `Space` pauses/resumes and `n`/`p` skip to the next/previous track; in Audiobooks `Space` pauses/resumes and `r` resumes the last chapter from your saved spot; from anywhere else, pause/resume and stop are palette actions (the palette carries no next/previous) |
| Collections / Playlists | `↑`/`↓` (or `j`/`k`) select, `Enter` open, `d` delete (type the word `delete` to confirm); `a`/`r` answer with a notice — see the Screens table |
| Servers | `↑`/`↓` select, `Enter` switch to it, `a` add one manually, `h` reload the list from the saved config, `r` remove |
| Settings | Move between the fields per the on-screen hints; `s` opens **Servers**, `l` signs out (both letter cases accepted) |
| Live TV (admin) | `r` refresh anywhere; in the Tuners section `s` rescans tuner hardware; on the Guide `R` records the selected program and `S` records its whole series (only offered when the program carries a series id) |
| Recommendations | `↑`/`↓` (or `j`/`k`) navigate, `Enter` opens, `x`/`X` dismiss a suggestion, `q`/`Q`/`Esc` back |

### Downloads

On the media detail screen, `d` downloads the focused item to disk: the client
requests a signed URL from the server and saves the file into the download
directory, with progress and completion reported as toasts. Downloads are
private by construction — the directory is created `0700` and files land `0600`
(a directory that already exists keeps its own permissions) — a hostile or
broken response is bounded by a 64 GiB ceiling, and partial files are cleaned up
when a transfer fails. The save location is `PHLIX_DOWNLOAD_DIR` (see the
environment table above; defaults to `~/Downloads`).

Books are not downloaded in-terminal: the book detail screen shows the signed
download URL as copyable text with a hint to open it in a browser or e-reader.

### Screens

While a music or audiobook session is live, the bottom row of every screen is
taken by a persistent **Now-Playing bar** — track or chapter title, artist or
album, position — and the audio keeps playing (and stays controllable) as you
navigate. The bar itself is display-only: transport (Resume playback / Pause
playback / Stop playback) rides the command palette, which is why it works
from any screen.

Every screen renders into the same shell — a borderless header line, a
bordered content region, and a bottom status row:

```text
 Phlix  ·  Home › Movies › The Matrix      ← header (brand · title/breadcrumb)
┌────────────────────────────────────────┐
│                                        │
│   the screen's body — rails, poster    │   ← bordered content region
│   grid, table, hero art, …             │     (`rows − 8` lines deep)
│                                        │
└────────────────────────────────────────┘
 p play · / search · Esc back             ← status hint row (replaced by the
                                             Now-Playing bar while audio plays)
```

The breadcrumb replaces the plain title as you drill in (truncating from the
left so the deepest, most relevant crumb survives), toasts float over the body
without moving it, and a screen's own keys are consolidated under
[Screen-specific keys](#screen-specific-keys) in Keybindings.

| Screen | Description |
| --- | --- |
| **Home** | Library rails + sidebar, tab between them |
| **Library** | Virtualized poster grid for a single library |
| **Detail** | Hero poster, metadata, genres, crew, cast, and a scrollable synopsis; a "More Like This" rail of similar titles loads in the background and appears when the server has them |
| **Series** | Not a screen of its own — `Enter` on a series opens nested **Detail** screens in container mode: a seasons grid, then each season's episodes grid, with breadcrumbs tracking the drill-down |
| **Music** | Album list → track table; Enter plays |
| **Music Artists** | Artist list → that artist's albums; Enter opens (palette → Music Artists) |
| **Audiobooks** | List → chapter table; Enter plays, `r` resumes |
| **Books** | Cover grid → detail with download URL |
| **Photos** | Album covers → thumbnail grid → fullscreen viewer |
| **Player** | In-terminal video player with scrubber and controls |
| **Cast** | Casting target list — Chromecast/DLNA/AirPlay/Roku — opened with `C` on a detail screen (works on streamable items) |
| **Search** | Global debounced search over virtualized results grid |
| **Command palette** | Fuzzy-ranked actions: Search, Home, Settings, Stats, Watch History, Favorites, Photos, Music Artists, Playlists, Collections, Servers, Shared With Me, Invite Links, Federation Shares, Show metrics / Hide metrics, Sign out, Quit — plus a `Go to <library>` jump per library, Admin when signed in as an admin, and Resume playback / Pause playback and Stop playback while a music or audiobook session is live |
| **Admin** | Admin menu over the server: dashboard, users, auth providers, backups, plugins (install / catalog / edit / updates), logs, metrics, server settings, transcoding, libraries, filesystem, DLNA, port-forward candidates, server restart, live TV, parental profiles and controls, watch history, webhooks, duplicates |
| **Settings** | Theme selection, photo slideshow interval; `s` opens **Servers**, `l` signs out |
| **Stats** | Read-only server statistics screen — counts and health reported by the server via the admin API; the live metrics HUD is separate, toggled by the palette's Show/Hide metrics actions |
| **Server Setup** | The first-run server-URL form — `Enter` connects, `Esc` quits |
| **Login** | Sign-in form — `Tab` moves fields, `Enter` signs in, `s` opens **Register**, `Esc` quits |
| **Register** | Sign-up form (username, email, password); on success you are signed straight in |
| **Collections** | Your collections; `Enter` opens one, `d` deletes it (typed `delete` confirms); `a`/`r` are notice-only — adding and removing items is not a console feature; it happens on the server's web portal |
| **Playlists** | Your playlists — the same server-side list as Collections (`GET /api/v1/collections`), with the same keys including the notice-only `a`/`r` pair |
| **Favorites** | Everything you favorited (`F` toggles on a detail screen); `Enter` opens |
| **Recently Watched** | Your watch history — `H` on the home sidebar or the palette's Watch History action; `Enter` opens the recorded item's detail screen |
| **Recommendations** | "For You" — personal suggestions built from your watching (`GET /api/v1/me/recommendations`); opened with `w` in the player. Navigate with `↑`/`↓` (or `j`/`k`), `Enter` opens, `x`/`X` dismisses a suggestion, `q`/`Q`/`Esc` goes back |
| **Servers** | Multi-server switcher — `Enter` switches the active server, `a` adds one manually (inline URL + label form; blank label falls back to the host), `h` refreshes the list from the saved config, `r` removes; opened from the palette or Settings |
| **Shared With Me** | Incoming federation share offers (`GET /api/v1/me/federation/library-shares/incoming`) — `↑`/`↓` (or `j`/`k`) move the cursor, `a` accepts and `r` rejects the selected offer (each answer toasts and re-reads the list from the hub), `q`/`Q`/`Esc` goes back |
| **Invite Links** | Hub invite links — lists the links you created with permission/usage counters (`↑↓` cursor); `c` mints one for the hub's single registered server (a hub with zero or several registered servers stops with a clear message — mint those from the web portal), `r` revokes the selected link; the plaintext token/URL appears only in the create response |
| **Federation Shares** | Outgoing federation shares (`GET /api/v1/me/federation/library-shares/outgoing`) — libraries your hub has shared to peers, listed with peer, permission and status; `↑`/`↓` (or `j`/`k`) move the cursor, `r` revokes the selected share (the hub deletes it, then the list re-reads), `q`/`Q`/`Esc` goes back |

## Troubleshooting

### "Composer autoloader not found"

Run `composer install` in the project directory.

### "needs a TTY" / blank screen on `phlix run`

`bin/phlix run` requires an interactive terminal. If you're running over SSH,
use `ssh -t user@host 'bin/phlix run'` or a terminal multiplexer (tmux, screen).

### Running inside a container

The repo ships no Dockerfile — the client is a plain PHP CLI (or the `latest`
PHAR) and needs nothing but PHP 8.3+ with the required extensions. If you run
it inside a container yourself: `watch` is the container-native mode — headless
by design, no TTY, and tokens travel via environment variables (see the bearer
ladder above), so it works under `docker run -i` and as an entrypoint. The
full TUI (`run`) needs an interactive terminal attached
(`docker run -it`), a UTF-8 locale set explicitly (`LANG=C.UTF-8` is the usual
choice in slim images — see the character-set note under Render modes), and
realistic expectations: the graphics protocols (sixel/kitty/iTerm2) rarely
survive the `docker exec`/SSH emulation chain, so the default `halfblock` cell
mode is the right choice there. Persist your config and poster cache by
mounting a volume and pointing `XDG_CONFIG_HOME` at it.

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

`phlix doctor` prints the exact `Token path` in use — delete that file and
run `bin/phlix run` to re-authenticate. (Do not hardcode `~/.config/phlix/`:
the directory follows `XDG_CONFIG_HOME` — see Configuration above — so a
fixed path deletes nothing, or worse, the wrong tree.)

### Slow poster loading

The first run fetches and tiles all posters for the selected render mode.
Subsequent runs are cached in the poster cache directory — `phlix doctor`
prints its exact, already-expanded location on a line of its own
(`Poster cache directory: …`; by default `~/.config/phlix/posters/`). To clear
the cache, parse that line directly:

```sh
rm -rf "$(bin/phlix doctor | sed -n 's/^Poster cache directory: //p')"
bin/phlix run
```

### PHP segfaults or memory errors

On very large libraries the client can hit PHP's memory limit (or the poster
cache grows unwieldy). Two levers, and they are different levers: cap the
poster cache to shrink what gets held, and raise the memory limit to give PHP
more room:

```sh
PHLIX_POSTER_CACHE_ENTRIES=8192 bin/phlix run   # half the default 16384 cached tiles
php -d memory_limit=512M bin/phlix run          # raise PHP's memory ceiling
```

Or persist the limit in your `php.ini`: `memory_limit = 512M`.

## Getting help

- **Issues:** https://github.com/detain/phlix-console-client/issues
- **Discussions:** https://github.com/detain/phlix-console-client/discussions
