# Clapperboard Snap — Phlix console logo (concept #5)

The slate claps shut, the screen shakes on the impact, and the board flips
edge-over to reveal the **Phlix** wordmark on its back face.

## Files

| File | What |
|------|------|
| `logo-clapperboard-snap-anim-tc.ansi` | PRIMARY — animated truecolor rendition (38 frames + settling final frame) |
| `logo-clapperboard-snap-tc.ansi` | static final frame (truecolor) |
| `logo-clapperboard-snap-tc.png` | raster preview of the final frame (via `logo-preview.php`) |
| `logo-clapperboard-snap-anim-tc.png` | raster preview of the animation's last frame |
| `generate-clapperboard-snap.php` | the generator (uses `logo-kit.php`) |
| `logo-kit.php` / `logo-play.php` / `logo-preview.php` | copied house tools (unmodified pipeline, local-only writes) |

## Regenerate

```bash
php generate-clapperboard-snap.php --write
```

Writes the two `.ansi` files into this directory only (the generator uses a
local `writeLogoLocal()` — no `logos.jsonl` or any side-effect outside the dir).

## View

```bash
php logo-play.php logo-clapperboard-snap-anim-tc.ansi        # animated (auto-caps total to <5s)
php logo-preview.php logo-clapperboard-snap-tc.ansi preview.png   # rasterize final frame to PNG
```

Debug aids baked into the generator:

```bash
php generate-clapperboard-snap.php            # prints the static frame + a few key poses
php generate-clapperboard-snap.php --poses=0,14,20,28,30,37   # ASCII audit of the story arc
php generate-clapperboard-snap.php --dump-back # bitmap of the logo (back) face
```

## How it works

Each frame is built as an `H_PX × W_PX` truecolor pixel world, then passed
through the house `lkHalfBlock → lkEncode('tc')` pipeline. All frames share the
same row count, so `lkAnim`'s `\e[<H>A\r` cursor-up redraws stay aligned.

The animation timeline (`animFrames()`):

1. **open** — striped clapper stick held up (ease-in hold, 6 frames)
2. **clap** — the stick swings down and shuts flat against the board (8 frames)
3. **flash** — white contact flash + sparks off the right tip (3 frames)
4. **shake** — impact rattle, the board jitters vertically and decays (6 frames)
5. **flip** — the board's face is scaled toward a card edge (`|cos θ|`), pinches
   to a single edge line, then re-opens onto the **back** face (12 frames)
6. **settle** — the Phlix wordmark on the lit slate, easing to full (3 frames)

The wordmark is a deliberately hand-laid 5-row font inflated on both axes, amber
over a near-black slate with a vertical shade and a chalk-white dot on the `i`.

## Measured stats

| metric | value |
|--------|-------|
| dimensions | **52 × 11** columns × rows (≤ 80 × ≤ 14 ✓) |
| animated frames | **38** + 1 final static frame |
| playback | ~3.5 s at 100 ms/frame (`logo-play` caps to <5 s) |
| anim size | 119,570 bytes |
| static size | 3,962 bytes |
| color depth | **truecolor** — `38;2;` fg + `48;2;` bg RGB triples confirmed; zero `38;5/48;5` 256-color leaks |
| shading idiom | half-block `▀`/`▄`/`█` cells, vertical-scale flip illusion |
| palette | slate near-black `#12141A`, edge grey `#3A404C`, chalk `#F2EEDF`, warm amber accent `#FF9A3C → #D9611E` |
| verify | `lkVerify` passes: every line ends `\e[0m`, uniform 52 visible columns across all rows |
