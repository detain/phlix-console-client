# Marquee Lights — animated truecolor ANSI logo for phlix-console-client

Concept #13: a cinema marquee sign — round bulbs chase in a running-light
circuit around the border while the neon "Phlix" letters sputter and stutter
on one by one, a cool cyan accent tube strikes in beneath the wordmark, and
the sign settles to a steady glow with a slow bulb chase continuing as the
idle state.

## Regenerate

```sh
php generate-marquee-lights.php --write
```

(Writes `logo-marquee-lights-tc.ansi` and `logo-marquee-lights-anim-tc.ansi`
into this directory; no-arg run prints the static frame to stdout.)

## View / verify

```sh
php logo-play.php logo-marquee-lights-anim-tc.ansi        # play the animation
php logo-preview.php logo-marquee-lights-tc.ansi logo-marquee-lights-tc.png '#0A0A0F' 8 16
```

Suggested timing: ~70 ms per screen ≈ 3.9 s; logo-play's default cap gives
~4.8 s (56 screens).

## Measured stats

| Metric | Value |
|---|---|
| Dimensions | 64 cols × 13 rows (≤ 80×14), half-block pixel grid 64×26 |
| Screens | 56 (55 animation frames + identical final static frame) |
| Frame separators `\e[<H>A\r` | 55 (grep-verified) |
| Playback | ~3.9–4.8 s depending on delay |
| Anim size | 934,319 B · static 20,111 B |
| Truecolor | confirmed — `\e[38;2;` on 728 lines, `\e[48;2;` on 728 lines |
| Bulb glyph `●` | present on 392 lines (70-bulb ring, two states per bulb) |
| lkVerify | static 64×13 ok · last frame 64×13 ok · `lkCheckDepth` tc ok |
| Final frame | byte-identical to `logo-marquee-lights-tc.ansi` — "Phlix" legible |

## Design notes

- **Motion story:** frames 0–4 dark sign; frames 5–21 letters P·h·l·i·x
  ignite on staggered starts with hand-tuned stutter/flicker sequences
  (miss-fire, drop-out, catch, over-shoot, settle); frames 24–35 the cool
  accent underline tube draws on left→right; from frame 36 the bulb chase
  slows to a gentle perpetual idle.
- **Neon craft:** each stroke = white-hot core (#FFF6E2) → amber edge
  (#FF942A) over unlit glass (#241A20), with a tight additive halo ramp
  (exp falloff, gain 0.55) and a dimmer neighbour-bleed arm; accent tube
  in cool #E8FBFF core / #5CD9FF edge.
- **Bulbs:** 70 `●` cells ring the frame (30 top, 30 bottom, 5 per side
  column), each rendered lit/unlit via fg mix #3A2617→#FFF0C8 (γ 1.5) with
  a bg glow bleed into the #17110F channel band; a 4-step running-light
  chase LUT [1, .55, .18, .05] advances clockwise.
- Palette is deliberately warm amber/warm-white with the single cool cyan
  accent — no rainbow blob.

## Files

- `logo-marquee-lights-anim-tc.ansi` — PRIMARY animated truecolor logo
- `logo-marquee-lights-tc.ansi` — static final frame
- `logo-marquee-lights-tc.png` — raster preview (note: the previewer draws
  `●` as a 2 px square; in a real terminal it renders as a round bulb)
- `generate-marquee-lights.php` — this logo's generator
- `logo-kit.php`, `logo-play.php`, `logo-preview.php` — unmodified copies of
  the candy-top house tools (originals in
  `/home/sites/sugarcraft/candy-top/logo/tools/` were left read-only).
