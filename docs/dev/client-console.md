# Console Client Developer Guide

## Architecture Overview

The console client is a terminal TUI application built in PHP 8.3+. It uses the
[SugarCraft](https://sugarcraft.github.io/) component stack for rendering
(posters as sixel/kitty/iTerm2/half-block Unicode cells) and the
[SugarCraft/Video](https://sugarcraft.github.io/) video player for in-terminal
video playback.

```
┌─────────────────────────────────────────────────────────────────┐
│                        bin/phlix                                 │
│              CLI entrypoint: doctor, poster, frame, watch, run   │
└──────────────────┬──────────────────────────────────────────────┘
                   │ builds object graph
                   ▼
┌─────────────────────────────────────────────────────────────────┐
│                          App.php                                 │
│     Root command handler / message bus / screen stack manager    │
│     - Bootstraps the entry screen from persisted config          │
│     - Routes each Msg in a private dispatch(); what it does not  │
│       claim itself goes to the top screen's update()             │
│     - Manages screen navigation (push/replace/pop)               │
└───────┬────────────────┬──────────────────────┬─────────────────┘
        │                │                      │
        ▼                ▼                      ▼
┌───────────────┐ ┌──────────────┐ ┌──────────────────────────────┐
│  AuthStore    │ │MediaStore    │ │LibrariesStore                │
│  (tokens)     │ │(media items) │ │(library list + metadata)    │
└───────┬───────┘ └──────┬───────┘ └──────────────┬───────────────┘
        │                │                      │
        ▼                ▼                      ▼
┌─────────────────────────────────────────────────────────────────┐
│                       ApiClient                                  │
│    Async, typed REST client for Phlix server                     │
│    - Bearer token auth with auto-refresh                         │
│    - Promise-based (React\Promise)                               │
│    - Typed DTOs for all responses                               │
└───────┬────────────────┬──────────────────────────────────────┘
        │                │
        ▼                ▼
┌───────────────┐ ┌──────────────┐  ┌─────────────────────────────┐
│ AdminClient   │ │ CastClient   │  │ HubClient                   │
│ (admin API)   │ │ (cast/devices)│  │ (multi-server hub)         │
└───────────────┘ └──────────────┘  └─────────────────────────────┘
```

The app follows the Elm architecture that SugarCraft's `Program` runtime
drives: one immutable model tree (the `App`), every input or async result is a
`Msg`, and `update()` returns the next model plus an optional `Cmd` (an async
effect). Screens are child models: `App` keeps a stack of frames
(`Route` + screen), hands every message it does not claim to the top screen's
`update()`, and composites overlays (command palette, toasts, now-playing bar,
shimmer ticks) above the top frame when it renders.

## Directory Structure

```
src/
├── App.php                 # Root application: screen stack + message routing
├── Capabilities.php        # Terminal capability detection
├── Route.php               # Enum of destinations the App can display
├── Api/
│   ├── ApiClient.php       # Main async REST client (auth, media, etc.)
│   ├── AuthError.php       # 401 auth error (triggers token refresh)
│   ├── ApiError.php        # Non-2xx API error
│   ├── AuthResult.php      # Login/register result (user + tokens)
│   ├── BrowserTransport.php # Default React-based HTTP transport
│   ├── Transport.php      # HTTP transport interface
│   ├── MediaQuery.php     # Media list query builder
│   ├── Admin/              # Admin API sub-client
│   ├── Cast/               # Cast (Chromecast/DLNA/AirPlay/Roku) client
│   ├── Hub/                # Multi-server hub relay client
│   ├── Dto/                # Typed Data Transfer Objects
│   │   ├── MediaItem.php, Library.php, Album.php, Book.php, Coerce.php, etc.
│   └── SyncPlay/           # SyncPlay (group watch) client
├── Screen/                 # All TUI screens (79 files) + their contracts
│   ├── Loadable.php        # Interface: screen is loading its first data
│   ├── Themed.php          # Interface: App may hand the theme to the screen
│   ├── ThemedScreen.php    # Trait: implements Themed (clone-mutate)
│   ├── Shimmering.php      # Interface: App may hand the shimmer phase over
│   ├── ShimmeringScreen.php # Trait: implements Shimmering (clone-mutate)
│   ├── Breadcrumbed.php    # Interface: screen joins the header crumb trail
│   ├── Teardownable.php    # Interface: screen releases external resources
│   ├── CapturesSlash.php   # Marker: screen keeps its own `/` key
│   ├── BrowseScreen.php    # Home: poster rails + sidebar (Tab / Shift-Tab focus)
│   ├── LibraryScreen.php   # Virtualized poster grid for one library
│   ├── LoginScreen.php, RegisterScreen.php, ServerScreen.php
│   ├── DetailScreen.php    # Media detail: poster, metadata, cast, "More Like This"
│   ├── PlayerScreen.php    # In-terminal video player
│   ├── SearchScreen.php    # Global search results grid
│   ├── MusicScreen.php, AlbumScreen.php
│   ├── AudiobooksScreen.php, AudiobookDetailScreen.php
│   ├── BooksScreen.php, BookDetailScreen.php
│   ├── PhotosScreen.php, PhotoAlbumScreen.php, PhotoViewerScreen.php
│   ├── SettingsScreen.php, StatsScreen.php
│   └── Admin*.php          # 23 admin screens (menu, users, plugins, logs, …)
├── Config/
│   ├── Config.php          # App config (servers, theme, prefs)
│   ├── TokenStore.php      # Persistent token storage (JSON file)
│   ├── TokenBundle.php     # Access + refresh token holder
│   └── ServerEntry.php     # Multi-server hub entry
├── Media/
│   ├── PosterLoader.php    # Fetches + tiles poster images per mode
│   ├── MosaicFactory.php   # Creates Mosaic renderers per mode
│   └── ...                 # Poster/spike renderers
├── Store/
│   ├── AuthStore.php       # Sign in/out, token refresh, session
│   ├── LibrariesStore.php  # Library list store
│   ├── MediaStore.php      # Media items store
│   └── ...                 # Per-area stores (Favorites, Books, Music, …)
├── Audio/
│   ├── MusicSession.php    # Audio playback session (ffplay/mpv)
│   ├── AudiobookSession.php # Audiobook with position saving
│   └── NowPlayingSession.php # Persistent now-playing bar
├── Ui/
│   ├── Chrome.php          # Shared frame: header, bordered body, hint line
│   ├── FilterBar.php       # Sort/filter bar for grids
│   ├── CommandPalette.php  # Ctrl-K / `:` palette modal
│   └── ...                 # Reusable UI components
├── I18n/
│   └── Lang.php            # i18n wrapper (Lang::t(), Lang::_())
├── Msg/                    # Message classes (internal events, ~280 files)
│   ├── LoginSucceededMsg.php, LoginFailedMsg.php
│   ├── OpenDetailMsg.php, PlayRequestedMsg.php, etc.
└── SugarCraft/
    └── Screen/             # Local patches/wrappers for SugarCraft libs
```

## Key Classes

### `App` (`src/App.php`)

The root application object: an immutable Elm model holding the screen stack,
the toast queue, the command palette, the theme, and the App-owned audio
session. The App — not the screens — owns navigation and routing.

**Key methods:**

- `App::boot(Config $config, AuthStore $auth, ApiClient $api, LibrariesStore $libraries, MediaStore $media, PosterLoader $posters, ?\Closure $audioFactory = null): self` — Factory that builds the full object graph and picks the entry screen (server setup when no server is configured, otherwise the restore/auth chain). Returns the App itself (never nullable).
- `app->update(Msg $msg): array{App, ?\Closure}` — The Elm update. Routes the message through the private `dispatch()` router: auth transitions, opens, ticks, back/quit, and global keys are claimed here; everything else (unhandled keys, async results) is delegated to the top screen's `update()`.
- The main event loop is SugarCraft's `Program`: `bin/phlix` runs `(new Program(App::boot(...)))->run()`. There is no `App::run()` method.

### `ApiClient` (`src/Api/ApiClient.php`)

Async, typed REST client. All API calls go through here. Returns
`React\Promise\PromiseInterface` — never throws synchronously.

**Key features:**

- Attaches `Authorization: Bearer <token>` to all authed requests.
- Auto-refreshes and retries once on 401.
- Shares a single in-flight refresh across concurrent callers.
- Fires `onTokenChanged` callback on token changes.

### Screen contracts

There is **no base screen class**. Every screen is a `final class` that
implements SugarCraft's `Model` interface (or the `Phlix\Console\Screen`
marker interfaces that extend it) and `use`s traits for shared behavior. The
established idiom for mutable view state is clone-mutate: `private` fields,
`with*()` copies; `readonly` for stable collaborators.

| Contract | Kind | Role |
| --- | --- | --- |
| `SugarCraft\Core\Model` | interface | Required: `init(): ?\Closure`, `update(Msg $msg): array{Model, ?\Closure}`, `view(): string\|View`, `subscriptions(): ?Subscriptions` |
| `SubscriptionCapable` | trait | Supplies `subscriptions()` — `use`d by every shipped screen |
| `Loadable` | interface | Extends `Model`; declares `isLoading(): bool` — while the top screen reports loading, the App runs a gated shimmer tick and stops the moment it finishes |
| `Themed` | interface | Extends `Model`; `withTheme(Theme): static` — the App hands the active theme to the top screen just before render |
| `ThemedScreen` | trait | Implements `Themed` once (clone-mutate, Nocturne default); screens get theming by `use`-ing it |
| `Shimmering` | interface | Extends `Model`; `withShimmerPhase(int): static` — the App drives the loading-skeleton phase |
| `ShimmeringScreen` | trait | Implements `Shimmering` (phase field + clone-mutate) |
| `Breadcrumbed` | interface | Extends `Model`; `crumbLabel()` + `withCrumbs(list<string>)` — joins the header trail (Home › Movies › …); auth screens skip it |
| `Teardownable` | interface | `teardown(): void`, idempotent — releases external resources (the player's ffmpeg/ffplay); the App calls it on pop/quit |
| `CapturesSlash` | marker | Screen keeps its own `/` key; the App does not hijack it into global search |

Every screen renders through a `view()` method returning the frame (an ANSI
string, or a `View` for cursor control). The runtime line-diffs each frame and
repaints only the changed rows (`Program::renderFrame`). Screens compose their
output through `Chrome::frame($title, $body, $hint, $cols, $rows, $trail, $theme)`
for the shared header/body-frame/hint layout.

### `Config` (`src/Config/Config.php`)

App configuration, persisted to `config.json` under `Config::dir()`
(`$XDG_CONFIG_HOME/phlix`, else `~/.config/phlix`).

**Key fields (readonly promoted properties):**

- `servers` — list of `ServerEntry` (multi-server; the legacy single-server
  shape is migrated on load)
- `activeServerId` — which server `run` connects to
- `theme` — `'nocturne'` | `'daylight'` | `'midnight'` (or an installed
  plugin theme slug; unknown falls back to Nocturne via `Theme::byName()`)
- `slideshowInterval` — photo/album slideshow seconds

Device-local keys (kept out of server-persisted settings):
`terminal_render_mode`, `colour_palette`, `cell_size`.

### `TokenStore` (`src/Config/TokenStore.php`)

File-based token persistence. Reads/writes `token.json`, keyed by server id;
bundles found in the legacy flat single-token format are migrated under the
`legacy` server id on load.

### `PosterLoader` (`src/Media/PosterLoader.php`)

Fetches poster images for a media item and tiles them using the active
render mode (half-block, sixel, etc.). Caches tiled images on disk per
mode, so switching modes re-tiles but a subsequent run is instant.

## How to Add a New Screen

A screen is (1) a `Model` class in `src/Screen/`, (2) an `Open…Msg` message
class in `src/Msg/`, and (3) one `dispatch()` arm plus one private `open…()`
helper in `App.php`. Screens never touch the App directly: they *return*
messages via `Cmd::send(...)`, and the runtime feeds them back into
`App::update()`.

### Step 1: Create the screen class

```php
<?php

// src/Screen/MyNewScreen.php — new file

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\MyDto;
use Phlix\Console\I18n\Lang;
use Phlix\Console\Msg\MyNewLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Ui\Chrome;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Model;
use SugarCraft\Core\SubscriptionCapable;

/**
 * Worked example modeled on src/Screen/RecommendationsScreen.php: fetch once
 * on init, render through the shared Chrome, Esc returns. A grid screen with a
 * loading skeleton would additionally `implements Loadable, Shimmering, Themed`
 * and `use ThemedScreen, ShimmeringScreen;` — see BooksScreen.php.
 */
final class MyNewScreen implements Model, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private bool $loading = false;
    private ?string $data = null;

    public function __construct(
        private readonly ApiClient $api,
        private readonly int $cols = 80,
        private readonly int $rows = 24,
    ) {
    }

    public function init(): \Closure
    {
        $this->loading = true;

        // A Cmd::promise's promise RESOLVES TO A MSG — the runtime delivers it
        // back through update(), which is how async results reach a screen.
        return Cmd::promise(fn (): PromiseInterface => $this->api->myEndpoint('example')
            ->then(static fn (MyDto $dto): Msg => new MyNewLoadedMsg($dto->name))
            ->catch(static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(Lang::t('auth.session_expired'))
                : ShowToastMsg::error('Failed: ' . $e->getMessage())));
    }

    /** @return array{self, ?\Closure} */
    public function update(Msg $msg): array
    {
        return match (true) {
            $msg instanceof KeyMsg => $this->handleKey($msg),
            $msg instanceof MyNewLoadedMsg => $this->onLoaded($msg->data),
            default => [$this, null],
        };
    }

    public function view(): string
    {
        $body = $this->data !== null
            ? "\n\n  {$this->data}\n"
            : "\n\n  Loading...\n";

        return Chrome::frame('My New Screen', $body, 'Esc  back', $this->cols, $this->rows, [], $this->theme());
    }

    /** @return array{self, null} */
    private function onLoaded(string $data): array
    {
        $this->loading = false;
        $this->data = $data;

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape) {
            // Esc does NOT pop the stack itself: the screen emits
            // NavigateBackMsg and the App's router pops (popScreen()).
            return [$this, Cmd::send(new NavigateBackMsg())];
        }

        return [$this, null];
    }
}
```

Notes on the contract (all derived from shipped code):

- `Loadable` is an **interface** declaring `isLoading(): bool`, not a trait —
  a screen cannot `use` it. Implement it only when the screen has a "first
  batch still fetching" state worth a shimmer; the App watches `isLoading()`
  and gates the animation tick itself (there is no `load()` hook — fetches
  are issued from `init()`/`update()` via `Cmd::promise`).
- `ThemedScreen` and `ShimmeringScreen` are **traits** that satisfy the
  `Themed`/`Shimmering` interfaces; the App supplies theme/phase to the top
  screen before each render via clone-mutate `with*()` copies.
- Mutable view state is `private` non-readonly and copied via `with*()`;
  injected collaborators are `readonly` promoted properties.

### Step 2: Register the message and the open handler in `App.php`

First the message classes in `src/Msg/` (plain value objects — copy an
existing one; `OpenStatsMsg` is the minimal shape):

```php
<?php

// src/Msg/OpenMyNewMsg.php — new file

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** Open the "My New" screen — the App pushes a MyNewScreen onto the stack. */
final readonly class OpenMyNewMsg implements Msg
{
}
```

```php
<?php

// src/Msg/MyNewLoadedMsg.php — new file

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** Async result consumed by MyNewScreen (the promise from its init()). */
final readonly class MyNewLoadedMsg implements Msg
{
    public function __construct(
        public string $data,
    ) {
    }
}
```

Name the destination on the `Route` enum (`src/Route.php` — a plain enum of
screens the App can display; there are no URLs):

```php
    case MyNew;
```

Then add the routing: one `instanceof` arm in the App's **private**
`dispatch()` router, and a private `open…()` helper that constructs the
screen with its dependencies, pushes the frame, and runs its `init()` cmd —
the exact shape every `open…()` in `App.php` has (see `openStats()`):

```php
// in dispatch():
if ($msg instanceof OpenMyNewMsg) {
    return $this->openMyNew();
}

// the opener:
/** @return array{App, ?\Closure} */
private function openMyNew(): array
{
    $screen = new MyNewScreen($this->api, $this->cols, $this->rows);

    return [$this->push(Route::MyNew, $screen), $screen->init()];
}
```

Back-navigation needs no work: the App's router already turns
`NavigateBackMsg` into `popScreen()` (which never empties the stack and calls
`teardown()` on the discarded frame if it is `Teardownable`).

### Step 3: Navigate to the screen

`dispatch()` is private to the App and unreachable from screens — a screen
opens the new screen by **returning a send command**, which the runtime feeds
back through `App::update()`:

```php
// inside the source screen's update()/handleKey():
return [$this, Cmd::send(new OpenMyNewMsg())];
```

Two other shipped producers of `Open…Msg`:

- the command palette — add `new PaletteAction('My New', new OpenMyNewMsg())`
  to the App's palette action list;
- App-side handlers — they call the private opener directly, e.g.
  `return $this->openMyNew();`.

### Step 4: Key bindings

Keys are handled in two places only:

- **Global keys** live in `App::dispatch()` and run BEFORE any screen sees
  them (Ctrl-C interrupt, Ctrl-K / `:` palette, `/` global search — skipped
  while the top screen `implements CapturesSlash`). Add a global key there.
- **Screen keys** live in the screen's `update()`/`handleKey()`: switch on
  `$msg->rune` for characters and `$msg->type` (`SugarCraft\Core\KeyType`)
  for named keys (Escape, arrows…). Return `Cmd::send(...)` to navigate or
  show a toast.

## Adding a New API Endpoint

### Step 1: Add the method to `ApiClient`

```php
/**
 * Fetch something.
 *
 * @return PromiseInterface<MyDto>
 */
public function myEndpoint(string $id): PromiseInterface
{
    return $this->authed('GET', '/api/v1/myendpoint/' . rawurlencode($id))
        ->then(static fn (array $data): MyDto => MyDto::fromArray(Coerce::map($data['item'] ?? null)));
}
```

(`authed()` is `ApiClient`'s private bearer-attaching, 401-refreshing request
helper; every typed endpoint method funnels through it.)

### Step 2: Create the DTO

```php
<?php

// src/Api/Dto/MyDto.php — new file

declare(strict_types=1);

namespace Phlix\Console\Api\Dto;

final class MyDto
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: Coerce::str($data['id'] ?? null),
            name: Coerce::str($data['name'] ?? null),
        );
    }
}
```

## Testing Strategy

```sh
vendor/bin/phpunit    # or: composer test
```

Tests live flat under `tests/` (`phpunit.xml.dist` points its suite at
`<directory>tests</directory>`) — screens in `tests/Screen/`, the shared
docs-truth pins in `tests/Docs/`. The poster and video-decode tests
self-skip when ffmpeg is unavailable.

### Static analysis

```sh
composer phpstan      # or: vendor/bin/phpstan analyse
```

PHPStan level 9 (strictest) is enforced in CI for `src/`. There is no baseline
file and no `ignoreErrors` in either config; `src/` carries 5 inline
`property.onlyWritten` suppressions, catalogued with per-site reasons in
README.md ("Static analysis") and pinned against the tree by
`tests/Docs/StaticAnalysisClaimsDocsTest.php`. `tests/` is analysed separately
via `phpstan-tests.neon`.

### PHP CodeSniffer (PSR-12)

```sh
composer cs           # src/ via phpcs.xml + tests/ via phpcs-tests.xml
composer cs:fix       # phpcbf over the same two standards
```

### End-to-end / TUI testing

The TUI itself is not fully automated in the test suite. Manual testing with
`bin/phlix run --selftest` checks the full object graph wiring.

### Selftest

```sh
bin/phlix run --selftest
```

Runs without a TTY: verifies config loading, DI container wiring, and HTTP
reachability against the configured server.

## Coding Standards

- **PSR-12** coding standard (enforced via PHP CodeSniffer: `phpcs.xml` for
  `src/`, `phpcs-tests.xml` for `tests/`)
- **PHPStan level 9** (strict types, no mixed, no nullable returns unless documented)
- All user-facing strings routed through `Lang::t()` / `Lang::_()` for i18n
- Docblocks on all public methods
- No `var_dump`, `print_r`, or debug output
- Promises are React\Promise — never `yield` (no async/await syntax)

## Debugging

### Render a single poster to see the raw ANSI output

```sh
bin/phlix poster /path/to/image.jpg 40 --mode=halfblock | cat -v
```

(`--mode` accepts `auto`, `halfblock`, `quarterblock`, `ascii`, `ansi256`,
`truecolor`, `sixel`, `kitty`, `iterm2`; for videos `frame --mode` accepts
the narrower `auto`, `halfblock`, `sixel`, `kitty`, `ascii`.)

### Trace message dispatch

Add `error_log()` in `App.php`'s `dispatch()` arms temporarily.

### Check terminal capabilities

```sh
bin/phlix doctor
```

### Configure the server non-interactively

There is no request-tracing env var; the shipped `PHLIX_*` variables are
`PHLIX_SERVER_URL` (server override for `run`) and
`PHLIX_POSTER_CACHE_ENTRIES` (disk-cache size). The `watch` relay entrypoint
additionally reads `PHLIX_HUB_URL`, `PHLIX_HUB_SERVER_ID`, `PHLIX_HUB_TOKEN`,
and `PHLIX_RELAY_TOKEN`. Inside `src/`, `PHLIX_POSTER_CONCURRENCY` caps
concurrent poster fetches (`PosterLoader::concurrencyLimit()`, default 6)
and `PHLIX_DOWNLOAD_DIR` sets the download target directory
(`DownloadService::resolveDownloadDir()`, default `~/Downloads`). To see
API traffic, log temporarily in `BrowserTransport.php`.

## Resources

- [SugarCraft docs](https://sugarcraft.github.io/)
- [ReactPHP](https://reactphp.org/) — async PHP primitives
- [PHPStan](https://phpstan.org/) — static analysis
- [PHPUnit](https://phpunit.de/) — testing
