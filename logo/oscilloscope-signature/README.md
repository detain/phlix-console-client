# Phlix — Oscilloscope Signature (concept #7)

A single glowing amber scope beam strokes across a dark CRT face and signs
"Phlix" as one continuous cursive — bowl, hairpins, crossing x, flourish curl —
leaving a white-hot phosphor trail behind the head that decays into the
settled trace; the final frame is the steady-glow logo on a faint graticule.

## Files

- `logo-oscilloscope-signature-anim-tc.ansi` — PRIMARY animated truecolor rendition
- `logo-oscilloscope-signature-tc.ansi` — static final frame (truecolor)
- `logo-oscilloscope-signature-tc.png` — raster preview
- `generate-oscilloscope-signature.php` — the generator (own design: continuous
  Catmull-Rom signature path, per-px arc-length paint-time field, exponential
  phosphor trail, half-block ▀ sub-pixel shading, beam head with bloom halo)
- `logo-kit.php`, `logo-play.php`, `logo-preview.php` — unmodified copies of the
  candy-top house tools

## Regenerate / view

```sh
php generate-oscilloscope-signature.php --write
php logo-play.php logo-oscilloscope-signature-anim-tc.ansi 55
php logo-preview.php logo-oscilloscope-signature-tc.ansi logo-oscilloscope-signature-tc.png '#141008' 8 16
```

## Measured stats

- Canvas: **64 × 12** columns × rows (≤ 80 × 14) — lkVerify passes static and
  every frame: uniform 64 visible columns, `\e[0m` line resets
- Frames: **61** playable (60 motion frames + the static final frame; frame
  separator `\e[12A\r` × 60; last frame byte-equals the static .ansi)
- Playback: ~3.4 s at 55 ms/frame (60 frames → pen write ≈86 % of timeline,
  then phosphor settle)
- Bytes: anim 997,947 · static 20,103 · PNG 5,327
- Truecolor confirmed: 24-bit `38;2`/`48;2` SGR throughout (`lkCheckDepth 'tc'`
  passes on static + every frame)
- Palette: monochromatic amber tube — screen `#0D0705→#020101` vignette,
  graticule ticks `#3A2208`, trace body `#FF9E2C`, bloom `#8F4E12`,
  hot core `#FFEDCB`, beam dot `#FFF7E4`
- Trace optics: thin filament (body ≤1.5 px radius), restrained bloom, core
  brightness tracks the phosphor decay g (fresh burn bright → settles to
  STEADY 0.66), subtle scanline + flicker noise
