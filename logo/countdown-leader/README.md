# Phlix ANSI logo — Concept #4: Countdown Leader

A vintage film-leader splash: a bone-white academy circle with crosshairs,
radial ticks and a red sweeping second hand that wipes a subtly lighter
sector behind it — one full revolution per number, 3-2-1 — then a white-hot
flash frame fading down onto the **Phlix** wordmark (lit bone letters with an
amber-warmed base, the leader's pivot surviving as the red i-dot, an amber
underline rule snapping out from the centre with a red bead).

Everything is painted on a 76×26 px grid (1 px = 1 column, 2 px = 1 row —
square in terminal space) and half-blocked with the house `lkHalfBlock`
idiom, so the ring and hand are smooth.

## Files

| File | What |
|------|------|
| `logo-countdown-leader-anim-tc.ansi` | **PRIMARY** — animated truecolor rendition |
| `logo-countdown-leader-tc.ansi` | static final frame (identical to the anim's last frame) |
| `logo-countdown-leader-tc.png` | raster preview (via `logo-preview.php`) |
| `generate-countdown-leader.php` | the generator |
| `logo-kit.php`, `logo-play.php`, `logo-preview.php` | copies of the candy-top house tools (unmodified; all writes stay in this directory) |

## Regenerate / view

```sh
php generate-countdown-leader.php          # verify + rewrite both .ansi files
php generate-countdown-leader.php --check  # verify only
php logo-play.php logo-countdown-leader-anim-tc.ansi   # play it
php logo-preview.php logo-countdown-leader-anim-tc.ansi out.png  # raster of final frame
php generate-countdown-leader.php --dump 7 && php logo-preview.php dump-7.ansi f7.png  # inspect one frame
```

## Measured stats (verified this session, PHP 8.3.37)

- **Dims:** 76 × 13 columns×rows (limits: ≤80 × ≤14) — `lkVerify` uniform widths, `\e[0m` per line
- **Frames:** 54 (53 animated + settling static; `lkAnim` cursor-up separators `\e[13A\r`, verified 53, hide/show-cursor wrapped)
- **Playback:** ~4.7 s at `logo-play.php` default (delay auto-clamped to 88 ms/frame); design cadence ≈ one 14-frame revolution per second
- **Bytes:** static 6,421 B · anim 459,786 B
- **Depth:** truecolor confirmed — 702/13 lines carry `38;2` SGRs, zero `;5;` (256/16) codes, `lkCheckDepth` clean on both files
- **Sequence:** hold 3 → sweep → jump-cut 2 (wipe resets) → sweep → jump-cut 1 → sweep → white-hot flash → 3 fade frames onto the logo → rule extends ×2 → static

## Palette (restrained vintage leader)

charcoal page `#16120F` · wiped sector `#241C14` · bone ring/numerals/letters
`#ECE1CD` · dim bone crosshairs `#6E6152` · leader red hand/i-dot `#C23A27`
(hot tip `#F1653F`) · Phlix amber rule/word-base `#DA9B2E`
