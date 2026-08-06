# Console Client Developer Guide

## Architecture Overview

The console client is a terminal TUI application built in PHP 8.3+. It uses the
[SugarCraft](https://sugarcraft.github.io/) component stack for rendering
(posters as sixel/kitty/iTerm2/half-block Unicode cells) and the
[SugarCraft/Reel](https://sugarcraft.github.io/) video player for in-terminal
video playback.

```
┌─────────────────────────────────────────────────────────────────┐
│                        bin/phlix                                 │
│              CLI entrypoint: doctor, poster, frame, run          │
└──────────────────┬──────────────────────────────────────────────┘
                   │ builds object graph
                   ▼
┌─────────────────────────────────────────────────────────────────┐
│                          App.php                                 │
│     Root command handler / message bus / screen stack manager    │
│     - Bootstraps the full DI container                           │
│     - Routes Msg::* messages to Screen handlers                  │
│     - Manages screen navigation (push/pop)                       │
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

## Directory Structure

```
src/
├── App.php                 # Root application + message bus
├── Capabilities.php        # Terminal capability detection
├── Route.php               # Route definitions (screen → URL mapping)
├── Api/
│   ├── ApiClient.php       # Main async REST client (auth, media, etc.)
│   ├── AuthError.php       # 401 auth error (triggers token refresh)
│   ├── ApiError.php        # Non-2xx API error
│   ├── AuthResult.php      # Login/register result (user + tokens)
│   ├── BrowserTransport.php # Default React-based HTTP transport
│   ├── Transport.php      # HTTP transport interface
│   ├── MediaQuery.php      # Media list query builder
│   ├── Admin/              # Admin API sub-client
│   ├── Cast/               # Cast (Chromecast/DLNA/AirPlay/Roku) client
│   ├── Dto/                # Typed Data Transfer Objects
│   │   ├── MediaItem.php, Library.php, Album.php, Book.php, etc.
│   └── SyncPlay/           # SyncPlay (group watch) client
├── Screen/                 # All TUI screens (~70 files)
│   ├── Breadcrumbed.php    # Trait: adds breadcrumb trail to a screen
│   ├── Loadable.php        # Trait: async data loading with shimmer
│   ├── Shimmering.php      # Trait: shimmer skeleton while loading
│   ├── Themed.php          # Trait: applies color theme to screen
│   ├── ThemedScreen.php    # Base screen with theming
│   ├── LoginScreen.php, RegisterScreen.php
│   ├── HomeScreen.php      # Library rails + sidebar (Tab to switch)
│   ├── BrowseScreen.php    # Generic library browser
│   ├── LibraryScreen.php   # Virtualized poster grid for one library
│   ├── DetailScreen.php    # Media detail: poster, metadata, cast, chapters
│   ├── PlayerScreen.php    # In-terminal video player
│   ├── SearchScreen.php    # Global search results grid
│   ├── MusicScreen.php, AlbumScreen.php
│   ├── AudiobooksScreen.php, AudiobookDetailScreen.php
│   ├── BooksScreen.php, BookDetailScreen.php
│   ├── PhotosScreen.php, PhotoAlbumScreen.php, PhotoViewerScreen.php
│   ├── SettingsScreen.php, StatsScreen.php
│   └── Admin*/             # ~20 admin screens
├── Config/
│   ├── Config.php          # App config (server URL, theme, prefs)
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
│   └── MediaStore.php      # Media items store
├── Audio/
│   ├── MusicSession.php    # Audio playback session (ffplay/mpv)
│   ├── AudiobookSession.php # Audiobook with position saving
│   └── NowPlayingSession.php # Persistent now-playing bar
├── Ui/
│   ├── FilterBar.php       # Sort/filter bar for grids
│   └── ...                 # Reusable UI components
├── I18n/
│   └── Lang.php            # i18n wrapper (Lang::t(), Lang::_())
├── Msg/                    # Message classes (internal events)
│   ├── LoginSucceededMsg.php, LoginFailedMsg.php
│   ├── OpenDetailMsg.php, PlayRequestedMsg.php, etc.
└── SugarCraft/             # Local patches/wrappers for SugarCraft libs
```

## Key Classes

### `App` (`src/App.php`)

The root application object. Bootstraps the DI container and runs the main
event loop. All screens register message handlers on the App. The App routes
user input events to the active screen.

**Key methods:**

- `App::boot(Config, AuthStore, ApiClient, LibrariesStore, MediaStore, PosterLoader): ?App` — Factory method that builds the full object graph.
- `app->run(): void` — Main event loop (from SugarCraft's `Program`).

### `ApiClient` (`src/Api/ApiClient.php`)

Async, typed REST client. All API calls go through here. Returns
`React\Promise\PromiseInterface` — never throws synchronously.

**Key features:**

- Attaches `Authorization: Bearer <token>` to all authed requests.
- Auto-refreshes and retries once on 401.
- Shares a single in-flight refresh across concurrent callers.
- Fires `onTokenChanged` callback on token changes.

### Screen base classes

| Class | Role |
| --- | --- |
| `ThemedScreen` | Base for all screens — applies the active color theme |
| `Loadable` (trait) | Mixin for async data loading with shimmer skeletons |
| `Shimmering` (trait) | Mixin for animated loading shimmer |
| `Breadcrumbed` (trait) | Adds breadcrumb navigation trail |

All screens implement a `view(): string` method that returns the rendered
frame (ANSI string). The App calls this on each tick and diffs against the
previous frame to minimize terminal redraws.

### `Config` (`src/Config/Config.php`)

App configuration. Persisted to `~/.config/phlix/config.json`.

**Key fields:**

- `serverUrl` — Phlix server base URL
- `theme` — `'nocturne'` | `'daylight'` | `'midnight'`
- `renderMode` — Preferred render mode

### `TokenStore` (`src/Config/TokenStore.php`)

File-based token persistence. Reads/writes `tokens.json`.

### `PosterLoader` (`src/Media/PosterLoader.php`)

Fetches poster images for a media item and tiles them using the active
render mode (half-block, sixel, etc.). Caches tiled images on disk per
mode, so switching modes re-tiles but a subsequent run is instant.

## How to Add a New Screen

### Step 1: Create the screen class

```php
// src/Screen/MyNewScreen.php
namespace Phlix\Console\Screen;

use Phlix\Console\Screen\ThemedScreen;

final class MyNewScreen extends ThemedScreen
{
    private ?string $data = null;

    public function __construct(
        private readonly ApiClient $api,
    ) {}

    // Optional: loading shimmer while data fetches
    use \Phlix\Console\Screen\Loadable;

    protected function load(): \React\Promise\PromiseInterface
    {
        return $this->api->someEndpoint()->then(function ($result) {
            $this->data = $result;
        });
    }

    public function view(): string
    {
        $body = $this->data !== null
            ? $this->renderBody()
            : $this->renderShimmer();

        return Chrome::frame('My New Screen', $body, 'Esc  back');
    }

    private function renderBody(): string
    {
        // ... render using SugarCraft\Chrome, Palette, etc.
    }
}
```

### Step 2: Register the message and handler in `App.php`

```php
// Add a msg class: src/Msg/OpenMyNewScreenMsg.php
// (copy an existing one as template)

// In App.php, add to $messageHandlers in boot():
Msgs\OpenMyNewScreenMsg::class => function (Msgs\OpenMyNewScreenMsg $msg) {
    $this->openScreen(new Screen\MyNewScreen($this->api));
},
```

### Step 3: Navigate to the screen

From any existing screen or handler:

```php
$this->dispatch(new Msgs\OpenMyNewScreenMsg());
```

### Step 4: Add keybindings

If the screen needs keyboard input, register a key handler:

```php
// In App.php or within the screen's handleInput() override
case 'k':
    $this->dispatch(new Msgs\OpenMyNewScreenMsg());
    break;
```

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

### Step 2: Create the DTO

```php
// src/Api/Dto/MyDto.php
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
            id: Coerce::str($data['id'] ?? null) ?? '',
            name: Coerce::str($data['name'] ?? null) ?? '',
        );
    }
}
```

## Testing Strategy

### Unit tests

```sh
vendor/bin/phpunit
# or: composer test
```

Tests live in `tests/phpunit/`. The poster and video-decode tests
self-skip when ffmpeg is unavailable.

### Static analysis

```sh
composer phpstan   # or: vendor/bin/phpstan analyse
```

PHPStan level 9 (strictest) is enforced in CI. No baseline, no suppressions.

### PHP CodeSniffer (PSR-12)

```sh
composer phpcs     # or: vendor/bin/phpcs
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

- **PSR-12** coding standard (enforced via PHP CodeSniffer)
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

### Trace message dispatch

Add `error_log()` in `App.php` message handlers temporarily.

### Check terminal capabilities

```sh
bin/phlix doctor
```

### See API requests

Set `PHLIX_DEBUG=1` (if supported) or temporarily log in `BrowserTransport.php`.

## Resources

- [SugarCraft docs](https://sugarcraft.github.io/)
- [ReactPHP](https://reactphp.org/) — async PHP primitives
- [PHPStan](https://phpstan.org/) — static analysis
- [PHPUnit](https://phpunit.de/) — testing
