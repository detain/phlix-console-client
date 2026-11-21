# Rotating 3D Wordmark — concept #19 (re-cut)

A 3D-extruded "Phlix" wordmark spins about its vertical axis into place: five
depth slices z-sorted far-to-near with lambert-shaded side faces, a warm
Phlix-amber key with cool fill, a specular glint that sweeps across the face as
it turns, an overshoot-and-settle at square, and a dim noise-dithered floor
reflection compressed toward a 1-bit amber rule.

## Files

| File | What |
|---|---|
| `logo-rotating-3d-wordmark-anim-tc.ansi` | PRIMARY animation — 55 frames (~3.4 s at 16 ms), spin (44) + idle glint pass (10) + settled static finale (1) |
| `logo-rotating-3d-wordmark-outro-tc.ansi` | Quit animation — spins away to edge-on, speckle-dissolves to nothing (26 frames + blank finale) |
| `logo-rotating-3d-wordmark-tc.ansi` | Static final frame (also the anim's tail frame, byte-identical) |
| `logo-rotating-3d-wordmark-tc.png` | Raster preview of the static frame (8x16 cells) |
| `generate-rotating-3d-wordmark.php` | Deterministic generator (seed 1937 — byte-identical output every run) |
| `logo-kit.php`, `logo-play.php`, `logo-preview.php` | House tool copies from `candy-top/logo/tools` (originals untouched) |

## Regenerate

```sh
php generate-rotating-3d-wordmark.php --write      # rewrites the three .ansi files + self-gate
php generate-rotating-3d-wordmark.php > /dev/null  # static frame to stdout (no writes)
```

Self-gate prints e.g. `58 x 14  truecolor ok  frames=55  tail==static:yes` to
stderr and fails loudly on any violation (truecolor depth, frame-count math,
tail identity, width/height budget).

## Play / preview

```sh
php logo-play.php logo-rotating-3d-wordmark-anim-tc.ansi
php logo-play.php logo-rotating-3d-wordmark-outro-tc.ansi
php logo-preview.php logo-rotating-3d-wordmark-tc.ansi out.png
```

## Measured stats

- Dims: 58 cols x 14 rows (wordmark band 9 cell-rows + floor rule + 8-row
  compressed reflection; within the 80 x 14 budget).
- Frames: anim 55 (54 `\e[<H>A\r` separators), outro 27.
- Playback ~3.4 s / ~1.6 s at 16 ms per frame (idle cadence via logo-play).
- Truecolor: `\e[38;2;r;g;b` / `\e[48;2;...` only, half-block (▀) house idiom,
  backdrop left transparent over #1a1b26-friendly dark.
- Determinism: two consecutive `--write` runs diff byte-identical (all three
  `.ansi` files); all randomness seeded (SEED=1937 + per-channel variants).
- Shading: front face per-column amber->cool fill mix + vertical bevel +
  top-edge white cap + lit crown strip; side faces stepped darker by depth
  (0.84^(z-1)) and angle; reflection dim-falloff 0.48*(1-r/8)^1.2 with
  dithered keep-probability — never a mirror copy.
