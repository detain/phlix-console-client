# Phlix — Terminal Prompt (logo #20)

A real terminal session as the logo: an amber `$` prompt types `phlix` behind a
block cursor, Enter executes it, the "Phlix" wordmark banner prints top-down in
truecolor amber half-block letters, a green `✓ connected to phlix server — 4
libraries, 12,841 items` status line reveals, and a fresh prompt cursor blinks
— the fastest design of the set (1.8 s), made for repeat launches.

## Files

| file | role |
|------|------|
| `logo-terminal-prompt-anim-tc.ansi` | **PRIMARY** — animated truecolor |
| `logo-terminal-prompt-tc.ansi` | static final frame (cursor-lit, representative) |
| `logo-terminal-prompt-tc.png` | raster preview (logo-preview.php) |
| `generate-terminal-prompt.php` | generator (builds + verifies + writes both .ansi) |
| `logo-kit.php`, `logo-preview.php`, `logo-play.php` | house tools, copied unmodified from `candy-top/logo/tools/` |

## Regenerate / view

```sh
php generate-terminal-prompt.php
php logo-play.php logo-terminal-prompt-anim-tc.ansi 60
php logo-preview.php logo-terminal-prompt-tc.ansi logo-terminal-prompt-tc.png
```

## Beat map (30 frames @ 60 ms = 1.8 s)

- f0–f6: typing — `$` → `$ phlix`, amber block cursor riding (≈0.42 s)
- f7: Enter — cursor flashes off on the executed line
- f8–f13: banner prints, one half-block row per frame; fresh `$ ` prompt appears below
- f14–f15: hold; f16: green `✓`; f17–f18: status text + counts reveal; f19: complete
- f20–f28 + static f29: idle cursor blink (off/on alternation; static frame holds cursor ON)

## Measured stats

- Dimensions: **58 × 11** (limits 80 × 14)
- Frames: **30** (29 animated + static final), playback **1.80 s** at 60 ms/frame
- Frame separator: `\e[11A\r` × 29 present; starts `\e[?25l`, ends `\e[?25h`
- Truecolor: 183 lines with `38;2` (anim), 9 (static); depth purity checked by `lkCheckDepth`
- Bytes: anim 79,601 · static 3,746 · PNG 2,382
- `lkVerify` passes for the static frame and every one of the 30 frames (uniform visible width, `\e[0m` line resets)

## Craft notes

- Banner: 11-row hand-cut pixel mask (cap P + x-height lowercase) → per-pixel
  vertical amber gradient (`#FFE08A→#D97F00`, warm gold tint toward the right,
  lit rim row) + `#4A2F06` drop shadow → `lkHalfBlock` (the house ▀/▄/█ idiom),
  flush left at the prompt column, never centered.
- Terminal typography kept plain: amber `#FFB000` prompt, near-white command,
  `#3FB950` check, `#8B949E`/`#C9D1D9` greys, solid block cursor.
