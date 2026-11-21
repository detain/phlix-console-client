# Phlix — Poster Wall Flip (concept #9)

A wall of tiny library poster cards — muted artwork fronts (horizon+sun,
diagonal prints, play-button features, each with a title strip) — flips
card-by-card in a diagonal wave sweeping left→right, top→bottom. Each card
squeezes horizontally to a bright spine (full → edge-on → back) and the dark
kraft backs together reveal the glowing coral→amber **Phlix** wordmark,
settling on the static final frame.

Grid: 12×5 poster tiles of 5×4 px on 1-px wall seams → 73×26 px rasterised
into 73×13 terminal cells with the house half-block (▀) idiom. The wordmark
is a 5×9 pixel font upscaled 2× and centred on the wall.

## Files

| File | What |
|------|------|
| `logo-poster-wall-flip-anim-tc.ansi` | **PRIMARY** — truecolor animated rendition (27 anim frames + static hold) |
| `logo-poster-wall-flip-tc.ansi` | static final frame (truecolor) |
| `logo-poster-wall-flip-tc.png` | raster preview of the final frame |
| `generate-poster-wall-flip.php` | the generator (self-contained; also `--anim` to play) |
| `logo-kit.php` / `logo-play.php` / `logo-preview.php` | copies of the candy-top house tools (originals untouched; this generator writes only inside this directory — no logos.jsonl side effect) |

## Regenerate / view

```bash
php generate-poster-wall-flip.php --write     # rewrites the two .ansi files (+ verifies)
php logo-play.php logo-poster-wall-flip-anim-tc.ansi   # play the animation (~2.8 s)
php logo-preview.php logo-poster-wall-flip-tc.ansi logo-poster-wall-flip-tc.png '#0D0B12' 8 16
```

## Measured stats

- Dimensions: **73 × 13** (columns × rows) — within the 80×14 budget
- Frames: **28 total per loop** (27 animation frames + the static hold;
  frame separator `ESC[13A CR` × 27; last frame byte-identical to the
  static file)
- Playback: ~2.8 s at logo-play's default 100 ms/frame
- Size: anim 562,878 B · static 22,786 B
- Colour: **truecolor confirmed** (`38;2;`/`48;2;` SGRs throughout;
  lkCheckDepth tc-purity and lkVerify uniform-width/line-reset gates pass)
- Final frame spells **Phlix** (legible wordmark on the settled poster wall)
