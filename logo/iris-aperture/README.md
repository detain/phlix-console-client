# Iris Aperture — animated truecolor ANSI logo (concept #18, phlix-console-client)

A camera iris opens from a tiny hexagonal pinhole: six overlapping gunmetal blades rotate
and retract outward, the aperture morphs hexagon → circle, and the warm amber **Phlix**
wordmark is revealed through the opening — a bright thin ring of light catching each blade
edge all the way out, ending on the clean static logo.

## Files

| File | What |
|------|------|
| `logo-iris-aperture-anim-tc.ansi` | PRIMARY — animated truecolor rendition (61 frames) |
| `logo-iris-aperture-tc.ansi` | static final frame, truecolor |
| `logo-iris-aperture-tc.png` | raster preview of the final frame |
| `generate-iris-aperture.php` | the generator (concept math lives here) |
| `logo-kit.php` / `logo-play.php` / `logo-preview.php` | unmodified copies of the candy-top house tools |

## Regenerate / view

```bash
php generate-iris-aperture.php                 # static frame to stdout
php generate-iris-aperture.php --write         # writes the .ansi deliverables (+ PNG via preview tool)
php logo-preview.php logo-iris-aperture-tc.ansi logo-iris-aperture-tc.png
php logo-play.php logo-iris-aperture-anim-tc.ansi   # play the animation
```

`--write` runs `lkVerify` + `lkCheckDepth('tc')` on every frame and fails loud on ragged
widths, missing `\e[0m` resets or non-truecolor SGR.

## How it is drawn

Pixel grid 52 × 24 square pixels → half-block (`▀`) cells → **52 × 12** terminal cells
(each cell carries a top and a bottom pixel, so the squashed-circle aspect of terminal
cells is handled in pixel space before downsampling). Per frame, per cell the polar
distance/angle from the iris pivot is tested against a blade boundary
`b(θ) = mix(hexagon, circle)`; inside `b` the aperture shows the wordmark (plus a faint
warm bloom halo), outside it a 11 px band of beveled gunmetal blade with a per-sector
bevel gradient, a specular streak, an overlap shadow at each trailing lip, and a 2.2 px
rim highlight only along the cutting edge. Timeline: 3 frames held closed, then a
perceptual radius curve (`openRadius`) that spends most of the run inside the letters and
finishes with the last blades sliding off the far corner; the pinhole sits on the stem of
**P**, so the closed iris already shows a spark of the letter.

## Measured stats

- Dims: **52 × 12** columns × rows (≤ 80 × 14 ✓)
- Frames: **61** (60 motion + final static), separator `\e[12A\r` verified present
- Playback: **4.78 s** via `logo-play.php` (within 1.5–5 s)
- Sizes: anim `463,916 B`, static `2,555 B`, PNG 416 × 192
- Truecolor: `grep -c '38;2'` → 709 on the anim file, 5 on the static; `lkCheckDepth`
  confirms every SGR uses `;2;` (24-bit), no 256-color or 16-color fallbacks
- Palette: amber wordmark (`#FFDF9E → #D96E1E`), gunmetal blades, cool-white/amber rim
  light — no rainbow
