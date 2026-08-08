# Terminal Graphics

Phlix console client renders images in the terminal using several graphics
protocols, each with different capabilities and terminal support.

## Supported Protocols

| Protocol | Terminal Support | Animation | Transparency | Max Colors |
|----------|-----------------|-----------|--------------|------------|
| **Half-block** (ANSI) | All | No | No | 16/256 |
| **SIXEL** (DEC) | xterm, Mintty, mlterm, iTerm2 | Yes | Yes | 4096 |
| **iTerm2** | iTerm2 only | Yes | Yes | 16.7M (24-bit) |
| **Kitty** | Kitty, some terminals | Yes | Yes | 16.7M (24-bit) |

## How It Works

1. **Poster rendering** — A source image (poster) is decoded into an `RgbFrame`
   containing a cell-grid of RGB pixels.
2. **Protocol encoding** — Each graphics protocol (`SixelDecoder`,
   `Iterm2Decoder`, `KittyDecoder`) encodes the `RgbFrame` into its native
   terminal escape sequence format.
3. **Cell modes** — For ANSI-based modes (HalfBlock, QuarterBlock, ASCII,
   ANSI256, TrueColor), the frame is rendered as colored block characters.

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
                      ┌─────────────────────────────┐
                      │      PlayerScreen            │
                      │  (orchestrates rendering)  │
                      └──────────────┬──────────────┘
                                     │
                    ┌────────────────┼────────────────┐
                    ▼                ▼                ▼
            ┌──────────┐   ┌──────────────┐   ┌──────────┐
            │SixelDeco │   │Iterm2Deco    │   │KittyDeco │
            │der       │   │der          │   │der       │
            └────┬─────┘   └──────┬───────┘   └────┬─────┘
                 │                │               │
                 └────────────────┼───────────────┘
                                  ▼
                    ┌─────────────────────────────┐
                    │      RgbFrame               │
                    │  (cell grid of RGB pixels) │
                    └─────────────────────────────┘
```

## Testing

Graphics decoders are tested via test doubles in `tests/Graphics/`:

- `SixelDecoder` — Test double for SIXEL protocol decoding
- `Iterm2Decoder` — Test double for iTerm2 inline image decoding
- `KittyDecoder` — Test double for Kitty protocol decoding

Each decoder implements `SugarCraft\Reel\Decode\Decoder` and is tested for:

1. Opening with correct cell dimensions
2. Yielding RgbFrame objects
3. Proper close/reopen lifecycle
4. Null return after all frames exhausted

`PosterRenderTest` tests the full rendering pipeline from image source
to terminal output for each protocol/mode combination.

## Fallback Strategy

When a terminal does not support the requested graphics protocol, phlix
falls back to the best available ANSI-based cell mode:

```
graphics mode → halfblock → quarterblock → ansi256 → ascii
```

The `Capabilities` class detects terminal support at startup using DECRQSS
queries and OSC 10/110/111 responses.
