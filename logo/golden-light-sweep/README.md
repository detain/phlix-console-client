# Golden Light Sweep — Phlix console logo (concept #14)

Dark brushed-metal "Phlix" letters at rest; a diagonal beam of light sweeps
across the wordmark, and wherever it passes the metal gleams hot gold —
warm-white core, gold, amber — then cools back to gunmetal, with a small lens
flare (✺ core, streak, ghost dots) riding just ahead of the beam. The
animation settles on a clean static frame carrying a faint warm specular
highlight resting along the letter tops.

## Regenerate

```
php generate-golden-light-sweep.php --write
```

Writes both `.ansi` deliverables into this directory and runs `lkVerify` on
the static and every animation frame. `php generate-golden-light-sweep.php`
previews the static to stdout; `--frame N` prints one numbered frame.

## View / rasterize

```
php logo-play.php logo-golden-light-sweep-anim-tc.ansi
php logo-preview.php logo-golden-light-sweep-tc.ansi logo-golden-light-sweep-tc.png
```

(`logo-play.php`, `logo-preview.php`, `logo-kit.php` are copies of the
candy-top house tools; the generator never writes outside this directory.)

## Measured stats

| property | value |
|---|---|
| dims | 60 columns × 9 rows (limits: ≤80×14) |
| frames | 37 (4 rest + 26 sweep + 6 settle + final static) |
| frame separators `ESC[9A CR` | 36 |
| playback | ~2–4.8 s (logo-play caps total at 4.8 s) |
| truecolor | confirmed — 327 `38;2` sequences in the anim file, 8 in the static |
| anim size | 225,307 B |
| static size | 5,807 B |
| PNG preview | 2,212 B (`logo-golden-light-sweep-tc.png`) |
| verification | `lkVerify` ok on static + all 37 frames (uniform widths, `\e[0m` line resets); final frame legible as "Phlix" |

## Files

- `logo-golden-light-sweep-anim-tc.ansi` — PRIMARY animated truecolor logo
- `logo-golden-light-sweep-tc.ansi` — static final frame
- `logo-golden-light-sweep-tc.png` — raster preview of the static frame
- `generate-golden-light-sweep.php` — generator (per-cell metal bevel,
  proximity-falloff gold gleam around the tilted beam line, flare overlay,
  specular rest highlight)
- `logo-kit.php`, `logo-play.php`, `logo-preview.php` — house tool copies
