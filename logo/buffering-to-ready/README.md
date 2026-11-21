# Buffering → Ready — Phlix console ANSI logo (concept #10)

A media client's most hated screen, weaponised: a braille spinner and a
Phlix-amber progress bar crawl to **99%**, stall there with a few nervous
frames (`retry in 3s`, one dread-filled blink to 98%), flash a warm
**▸ READY** burst — then a white-hot edge sweeps the whole buffering UI
away and the **Phlix** wordmark with a play button assembles behind it,
settling to a clean static frame. The amber rail under the wordmark is the
progress bar's residue.

## Files

| file | what |
|---|---|
| `logo-buffering-to-ready-anim-tc.ansi` | PRIMARY — truecolor animated rendition (66 motion frames + final) |
| `logo-buffering-to-ready-tc.ansi` | truecolor static final frame |
| `logo-buffering-to-ready-tc.png` | raster preview of the final frame |
| `generate-buffering-to-ready.php` | the generator (hand-built cell grid + lkEncode/lkAnim) |
| `logo-kit.php`, `logo-play.php`, `logo-preview.php` | unmodified copies of the candy-top house tools |

## Regenerate & view

```bash
php generate-buffering-to-ready.php --write          # rewrites both .ansi files
php logo-preview.php logo-buffering-to-ready-tc.ansi logo-buffering-to-ready-tc.png
php logo-play.php logo-buffering-to-ready-anim-tc.ansi 60
```

## Measured stats

- Canvas: **48 × 10** columns×rows (limit 80×14) — uniform every frame (`lkVerify` passes per frame, `\e[0m` line resets).
- Frames: **66 motion + 1 final static = 67 sections** (66 `\e[<H>A\r` separators).
- Playback: **3.97 s** at 60 ms/frame (measured with logo-play; within 1.5–5 s).
- Size: anim 171,598 B · static 3,491 B.
- Truecolor confirmed: 4,398 `38;2` + 2,167 `48;2` SGR hits in the anim; depth purity (`lkCheckDepth tc`) passes.
- Palette: dark UI chrome (`#15171f` panel / `#12141b` page wash), muted grey spinner & text, Phlix-amber fill ramp (`#B4700F → #F5A623 → #FFC95E`), white-hot resolve edge (`#FFF2D6`). Half-block ▀/▄ + ▌/▐/█ quadrants + bg-fill ramps (house idioms).
