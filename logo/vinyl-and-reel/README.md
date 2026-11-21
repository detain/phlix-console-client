# Phlix — Vinyl & Reel (concept #8)

A black grooved vinyl record with a warm amber label rolls in from the left while a
perforated film reel threads out a strip as it rolls in from the right — both spinning
as they travel — to dock beside the "Phlix" wordmark: music and movies meeting in the
middle. An amber glint sweeps the wordmark on landing; the settled pose idles with a
slow rotation at the flanks.

Rendered on a 78×14 cell canvas in the house half-block idiom (▀ shading over a 78×28
sub-pixel grid, so the 24px discs read as true circles), full ANSI truecolor.
Restraint palette: vinyl blacks with concentric groove rings under a fixed specular
crescent, amber label/brand accent, gunmetal reel (punched spoke windows, slotted rim,
spokes catching the light), cream-to-amber wordmark over a brown drop shadow.

## Files

| File | What |
|---|---|
| `logo-vinyl-and-reel-anim-tc.ansi` | PRIMARY — animated truecolor logo (42 frames, ~4.2s at 100ms) |
| `logo-vinyl-and-reel-tc.ansi` | static final frame, truecolor |
| `logo-vinyl-and-reel-tc.png` | raster preview of the final frame (via logo-preview.php) |
| `generate-vinyl-and-reel.php` | the generator |
| `logo-kit.php`, `logo-play.php`, `logo-preview.php` | verbatim copies of the candy-top house tools (unmodified) |

## Regenerate

```bash
php generate-vinyl-and-reel.php --write
```

## View

```bash
php logo-play.php logo-vinyl-and-reel-anim-tc.ansi      # animated playback
php logo-preview.php logo-vinyl-and-reel-tc.ansi out.png # raster eyeball
```

## Measured stats

- Canvas: **78 cols × 14 rows** (≤ 80×14), verified by `lkVerify` on every frame
- Animation: **41 motion frames + 1 settled static pose = 42 frames**, cursor-up
  separator `ESC[14A CR` between frames; ends on the static frame, `ESC[0m` on every line
- Playback ≈ 4.2 s at logo-play.php's default 100 ms/frame (auto-capped under 4.8 s)
- `logo-vinyl-and-reel-anim-tc.ansi`: 572,333 bytes · `logo-vinyl-and-reel-tc.ansi`: 15,111 bytes
- Truecolor confirmed: `grep -c '38;2'` on the primary = 526 lines carrying 24-bit SGR runs; every visible fg cell is `38;2`, every line ends `ESC[0m`
