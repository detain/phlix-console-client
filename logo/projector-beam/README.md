# Phlix — Projector Beam (concept #2)

A cinema projector throws a warm, dust-filled light cone across a dark theater and
projects the wordmark **Phlix** onto the screen; the lamp ignites through stutters,
the image jitters and flickers like old film, then settles into a clean, legible end
frame with only a faint idle flicker left in the beam.

## Files

| File | What it is |
|------|------------|
| `logo-projector-beam-anim-tc.ansi` | **PRIMARY** — animated truecolor rendition (73 frames) |
| `logo-projector-beam-tc.ansi` | static final frame (truecolor) |
| `logo-projector-beam-tc.png` | raster preview of the final frame |
| `generate-projector-beam.php` | the generator (writes both `.ansi` files) |
| `logo-kit.php`, `logo-play.php`, `logo-preview.php` | copies of the house tools (unmodified) |

## Regenerate

```sh
php generate-projector-beam.php --write
```

Prints `static 76x13  truecolor ok  frames=72`, verifies both files with `lkVerify`
/ `lkCheckDepth`, and asserts the animation's last frame is byte-identical to the
static file. All writes stay inside this directory (no `logos.jsonl`, no path escapes).

## View

```sh
php logo-play.php logo-projector-beam-anim-tc.ansi          # animation in a terminal
php logo-play.php logo-projector-beam-anim-tc.ansi 60       # explicit 60 ms/frame
php logo-preview.php logo-projector-beam-tc.ansi out.png     # PNG of the final frame
php generate-projector-beam.php --frame=24                   # one mid-reel frame, stdout
php generate-projector-beam.php --anim                       # play straight from source
```

## How it is built

The whole scene is composed as a 76 × 26 pixel light buffer (1 px = 1 column,
2 px = 1 character row), then reduced to half-block `▀` cells by `lkHalfBlock` and
emitted with `lkEncode(..., 'tc')`. Layers, bottom-up: theater gradient and warm
floor spill → woven screen panel → beam cone from the lens (gaussian profile, throw
falloff, occluded by the opaque screen plane) → the projected title plate with a
blur halo and a left-to-right lighting grade → film grain → vignette → screen batten
→ projector body, twin reels and hot lens flare → drifting dust motes.

Motion (`pbMotion($t)`): lamp ignition with two stutters, a decaying `settle` term
that drives flicker amplitude, gate dropouts, ±1–2 px integer frame jitter (sampling
the stored plate, so the weave shifts with it), occasional vertical scratch pulses,
and a slow breath on the lamp. The static frame is the same scene at `t = null`:
fully settled, no jitter, no scratches.

Palette: warm beam whites/creams (`#FFEDD6`, `#FFF4DC`, `#D8A75E`) against dark
theater blues (`#0A0B12` → `#141421`, screen `#171A24`), house half-block shading
and background-ramp glow.

## Measured stats

| Metric | Value |
|--------|-------|
| Dimensions | 76 columns × 13 rows (limits: ≤ 80 × ≤ 14) |
| Frames | 73 total = 72 animated + final static |
| Playback | ≈ 4.4 s at 60 ms/frame (≤ 5 s) |
| `logo-projector-beam-anim-tc.ansi` | 1,984,588 bytes |
| `logo-projector-beam-tc.ansi` | 27,378 bytes |
| Colour depth | truecolor — `\e[38;2;r;g;b` / `\e[48;2;r;g;b` only (`lkCheckDepth` clean; 949 lines contain `38;2`) |
| Frame separator | `\e[13A\r` between every frame (72 occurrences), bookended by `\e[?25l` … `\e[?25h` |
| Verification | `lkVerify` passes for the static frame and the animation's last frame; visible widths uniform at 76; every line ends `\e[0m` |
