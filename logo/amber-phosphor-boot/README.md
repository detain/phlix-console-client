# Amber Phosphor Boot — phlix-console ANSI logo (concept #1)

An old amber-phosphor CRT powers on: a hot raster line strikes across the dark
tube, expands vertically, phosphor snow scatters and collapses, then each
letter of **Phlix** burns in over-exposed and flickering (beam wobble ±1 px)
before cooling to steady brand-amber glow — half-block scanlines run *through*
the letters, with a bg-ramp bloom, corner vignette and an engraved bezel
(`● P3 PHOSPHOR … 60 Hz`, power LED fading up at switch-on).

## Files

| file | role |
|------|------|
| `logo-amber-phosphor-boot-anim-tc.ansi` | **PRIMARY** — truecolor animated rendition |
| `logo-amber-phosphor-boot-tc.ansi` | static final frame (byte-identical to the anim's last frame) |
| `logo-amber-phosphor-boot-tc.png` | raster preview of the final frame |
| `generate-amber-phosphor-boot.php` | the generator (all design data inline) |
| `logo-kit.php`, `logo-play.php`, `logo-preview.php` | unmodified copies of the candy-top house tools |

## Regenerate / view

```bash
php generate-amber-phosphor-boot.php --write        # rewrite both .ansi files
php logo-play.php logo-amber-phosphor-boot-anim-tc.ansi 90
php logo-preview.php logo-amber-phosphor-boot-tc.ansi logo-amber-phosphor-boot-tc.png
```

## Measured stats

- Dimensions: **58 × 10** columns×rows (budget ≤ 80 × 14) — final frame; every one of the 49 frames verifies uniform.
- Frames: **48 animation frames + static** (48 `\e[<H>A\r` separators); playback at 90 ms ≈ **4.32 s** (measured by logo-play).
- Truecolor: **confirmed** — 490 fg `38;2;` + 490 bg `48;2;` SGR sequences in the anim file; `lkCheckDepth` passes (no 256/16 codes), cursor hide/show wrapped (`\e[?25l` … `\e[?25h`).
- Sizes: anim 508,266 B, static 13,455 B.
- `lkVerify`: all 49 frames uniform width, every line ends `\e[0m`; final frame byte-identical to the static file; "Phlix" legible (see PNG).

## Craft notes

- Palette is the amber phosphor (P3) family only: strokes ramp `#FFD25A → #FFB000 → #FF8C00`, raster line `#FFE0A0`, bloom tint `#FF9B00`, warm near-blacks `#070402/#150C04/#0D0803` — no rainbow.
- House idioms used: pixel grid → `lkHalfBlock()` (odd-row ×0.74 darkening becomes ▀/█ shading = scanlines inside the glyphs), per-letter distance-field **bg-ramp glow**, engraved box-glyph bezel, `lkAnim()` cursor-up framing ending on the static frame.
- Animation model: five T-windows (line / expand / snow / burn-in / settle) computed per pixel from one `amScreenCells()` function; the static frame is simply `T = 1.0` of the same model (all phase terms vanish), which is why final ≡ static by construction.
