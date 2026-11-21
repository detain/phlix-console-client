# Media Glyph Rain — `Phlix` animated truecolor ANSI logo (seat 16)

**Concept:** A seeded rain of media glyphs (♪ ♫ ▸ • ✚ ✦ ◦ ▪ ○ ×) falls through the canvas;
where a drop's path crosses a letter cell it catches and sticks — each letter of **Phlix** is
progressively built out of the very symbols that hit it, flashing as it completes — while missed
drops run off the bottom. A merge wave then settles the glyph-mosaic into solid half-block
polished letters (▀ caps over bright gold on shaded grounds), and the final logo stands with a
few stragglers still raining past it, glints flanking and a soft ▒ ground shadow.

## Regenerate

```bash
php generate-media-glyph-rain.php --write
```

Writes `logo-media-glyph-rain-tc.ansi` (static) and `logo-media-glyph-rain-anim-tc.ansi`
(animated, truecolor) into this directory. Deterministic: seeded (`SEED = 16`), byte-identical
across runs (verified with `cmp`).

## View

```bash
php logo-play.php logo-media-glyph-rain-anim-tc.ansi        # terminal playback
php logo-preview.php logo-media-glyph-rain-tc.ansi out.png  # PNG raster of the static frame
```

(Animation is ~4.6 s at 55 ms/frame: `php logo-play.php logo-media-glyph-rain-anim-tc.ansi 55`.)

## Measured stats

| Property | Value |
|---|---|
| Canvas | **44 × 14** columns × rows (limits: ≤80 × ≤14) |
| Frames | **83** (82 dynamic + final static; 82 × `\e[14A\r` separators) |
| Playback | ≈ 4.6 s @ 55 ms/frame |
| Sizes | anim 357,471 B · static 2,440 B · PNG raster included |
| Truecolor | confirmed — `\e[38;2;r;g;b` fg (1,031 sites in anim) and `\e[48;2;…` bg (half-block caps) |
| Verify | `lkVerify` passes on first/mid/final frames (uniform 44-wide lines, `\e[0m` resets) |
| Determinism | re-run `cmp`-identical |

## Files

- `logo-media-glyph-rain-anim-tc.ansi` — **primary** animated truecolor logo
- `logo-media-glyph-rain-tc.ansi` — static final frame
- `logo-media-glyph-rain-tc.png` — raster preview (glyph marks render small; block caps show the letters)
- `generate-media-glyph-rain.php` — generator (all writes confined to this dir)
- `logo-kit.php`, `logo-play.php`, `logo-preview.php` — verbatim copies of candy-top house tools (originals untouched)

## Craft notes

- Glyph pool restricted to `mb_strwidth == 1` / non-east-asian-wide codepoints so every line stays 44 cells.
- Restrained palette: cool greys + dim golds at 0.88 tone with depth shading for the cascade;
  amber→gold gradient with per-row shading for the wordmark; white-gold catch flare, per-letter
  completion brighten, merge-wave settle flash.
- Rain is drawn beneath the letter grid: past drops keep falling behind solid columns in the final frames.
