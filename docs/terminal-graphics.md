# Terminal Graphics

Phlix console client renders images in the terminal using several graphics
protocols, each with different capabilities and terminal support.

## Supported Protocols

| Protocol | Terminal Support | Animation | Transparency | Max Colors |
|----------|-----------------|-----------|--------------|------------|
| **Half-block** (ANSI) | All | No | No | 16.7M (24-bit) |
| **SIXEL** (DEC) | xterm, Mintty, mlterm, iTerm2 | Yes | Yes | 4096 |
| **iTerm2** | iTerm2 only | Yes | Yes | 16.7M (24-bit) |
| **Kitty** | Kitty, some terminals | Yes | Yes | 16.7M (24-bit) |

## How It Works

1. **Image source** — A poster arrives as a sugar-image `ImageSource`.
   `Phlix\Console\Media\MosaicFactory` maps the chosen render mode to a
   `SugarCraft\Image\Mosaic` backend, and `Phlix\Console\Media\PosterLoader`
   fetches, renders and disk-caches each poster through that mosaic in one
   upstream call.
2. **Protocol encoding** — `Mosaic::render()` emits the backend's native
   terminal output: `SixelRenderer`, `Iterm2Renderer` and `KittyRenderer`
   produce escape-sequence blobs for the graphics protocols, while the
   cell-based renderers (`HalfBlockRenderer`, `QuarterBlockRenderer`,
   `AsciiRenderer` — ANSI256 and TrueColor are tinted ASCII ramps) produce
   colored characters, one terminal line per cell row.
3. **Overlay vs inline** — Graphics-protocol output is an opaque blob
   (`Mosaic::isInline()` is false), so `PosterLoader` hands it to
   sugar-image's `ImageLayer`: the text layout reserves marker blocks and
   the runtime paints the real bytes on top. Cell-mode output is inline
   text that composes directly into rails and grids.

## Protocol Details

### SIXEL (DEC Standard Graphics)

SIXEL encodes images as a series of 6-pixel-tall "decks" (attribute blocks).
Each deck is transmitted as a bitmap with a color palette index. The sequence
is wrapped in a DCS (Device Control String) with ST (String Terminator).

```
ESC P p ... p ESC \
```

- **Strengths**: Widest terminal support among graphics protocols, built into
  DEC terminals since the 1980s.
- **Limitations**: 6-pixel row granularity, limited color depth on older
  emulators.

### iTerm2 Inline Images

iTerm2's protocol embeds base64-encoded image data directly in the terminal
stream using the DCS sequence with the `i` parameter:

```
ESC ]1337;File=name=...;size=...;inline=1:base64data ESC \
```

- **Strengths**: True 24-bit color, animation support, no external tools
  needed when images are pre-encoded.
- **Limitations**: iTerm2-specific — does not work in other terminals.

### Kitty Terminal Graphics Protocol

The Kitty protocol is a binary protocol designed for high-performance terminal
graphics. It supports incremental rendering, animation, and compositing.

```
ESC [ G ... ESC \
```

- **Strengths**: Most capable protocol — animation, transparency, partial
  updates, built-in caching.
- **Limitations**: Requires Kitty or a compatible terminal (some features
  work in other terminals via fallbacks).

## Architecture

```
                      ┌──────────────────────────────┐
                      │    bin/phlix — CLI argv      │
                      │  --mode=… / --mode … / bare  │
                      └──────────────┬───────────────┘
                                     │ mode string, parsed at
                                     │ container build (no screen yet)
                      ┌──────────────▼───────────────┐
                      │        MosaicFactory         │
                      │ Mosaic::forPosterGrid($mode) │
                      │  (mode → backend policy)     │
                      └──────────────┬───────────────┘
                                     │ builds
                      ┌──────────────▼───────────────┐
                      │   SugarCraft\Image\Mosaic    │
                      │ ImageSource → terminal bytes │
                      └──────────────┬───────────────┘
                                     │ encodes through
        ┌──────────────┬─────────────┼─────────────┬───────────────┐
        ▼              ▼             ▼             ▼               ▼
   SixelRenderer  Iterm2Renderer  KittyRenderer  HalfBlock /     AsciiRenderer
                                             QuarterBlock     (ANSI256/TrueColor
                                             Renderer           are tinted ramps)
```

The `Mosaic` is held by `PosterLoader`, which every poster consumer shares —
the rails and grids of `DetailScreen`, `PhotosScreen`, `BrowseScreen` and
friends. `PlayerScreen` sits *downstream* of this diagram, not above it:
`App` opens the player with
`PlayerScreen::productionFactory($this->posters->protocol(), $this->posters->cellSize())`,
so the video reel mirrors the poster mode and graphics-mode frames decode at
the terminal's measured cell pixel size.

## Testing

Graphics output is tested against the protocol bytes a terminal would
actually receive. The earlier hand-made "decoder" doubles were removed as
theater — they replayed canned frames and proved nothing about the wire
format:

- `tests/Graphics/PosterGraphicsStreamTest.php` renders real poster mosaics
  for each graphics mode (`sixel`, `kitty`, `iterm2`) and decodes the
  emitted escape sequences with sugar-testing's protocol streams
  (`SixelStream`, `KittyStream`, `Iterm2Stream`), proving the bytes are
  well-formed, speak the requested protocol, and carry the intended raster
  and cell geometry.
- `tests/Ui/PosterRenderTest.php` covers the full pipeline — MediaItem →
  PosterCard → PosterLoader → Mosaic — for each protocol/mode combination.

## Fallback Strategy

When a terminal does not support the requested graphics protocol, phlix
falls back to the best available ANSI-based cell mode:

```
graphics mode → halfblock → quarterblock → ansi256 → ascii
```

The `Capabilities` class detects terminal support at startup using DECRQSS
queries and OSC 10/110/111 responses.
