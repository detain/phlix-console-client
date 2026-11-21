# Phlix — Equalizer Skyline (logo concept #6)

Music bars bounce to a kick/snare/hats groove across a stereo-rack canvas, then the beat drains away column-by-column so the level meter settles — leaving the wordmark **Phlix** carved out of the remaining bars as a dark knockout, with a chisel-flash on each carve and a gentle idle bob in the outer wings.

## Regenerate

```bash
php generate-equalizer-skyline.php --write   # writes logo-equalizer-skyline-tc.ansi + logo-equalizer-skyline-anim-tc.ansi
```

## View

```bash
php logo-play.php logo-equalizer-skyline-anim-tc.ansi     # animated playback
php logo-preview.php logo-equalizer-skyline-tc.ansi logo-equalizer-skyline-tc.png '#0D1120'   # PNG of final frame
```

Debug aids: `--dump` (ASCII skeleton), `--mask` (per-pixel carve mask), `--heights` (per-column rest heights).

## Measured stats

| Property | Value |
|---|---|
| Canvas | **60 × 10** columns×rows (limits: ≤80 × ≤14 — pass) |
| Frames | **81** total (80 motion frames + final static; 80 cursor-up separators `\e[10A\r`) |
| Playback | 81 × ~55 ms ≈ 4.4 s (within 1.5–5 s) |
| Static size | 11,904 bytes — `logo-equalizer-skyline-tc.ansi` |
| Anim size | 746,869 bytes — `logo-equalizer-skyline-anim-tc.ansi` |
| Depth | TRUECOLOR confirmed: `grep -c '38;2'` = 710 (anim), 10 (static); `lkCheckDepth('tc')` clean |
| lkVerify | static 60×10 ok · anim last-frame 60×10 ok (uniform widths, `\e[0m` line resets) |

## Craft notes

- Half-block (`▀`/`▄`) shading for smooth bar tops; white peak-hold ticks ride the scale row above the rack.
- Palette restrained to audio-meter amber → coral → cool cyan vertical ramp (`#FF9E2E → #FF5D78 → #3AD2E8`) on a deep `#0A0F1D` knockout; slate rail + mounting tabs ground the rack.
- Letters are negative space (true knockout), cap-lit above and rooted below so the wordmark reads without outlines.
- Tools: `logo-kit.php` / `logo-play.php` / `logo-preview.php` copied unmodified from candy-top; this generator writes all outputs inside its own directory.
