# Molten Amber Fill — "Phlix" (seat L15)

The wordmark stands as empty dark-glass outlines; a molten amber tide spreads along the
base and rises letter by letter — an undulating sin-wave meniscus with hot crest, flow
lines and climbing bubbles — then a global cooling parameter stills the ripples, calms
the glow to polished resin, and one diagonal specular band snaps in like cured epoxy.
Amber family only: `#FFB000` core → `#E07800` → `#8A4A00` edge.

## Regenerate

```bash
php generate-molten-amber-fill.php --write
```

(No-arg run previews the static final frame to stdout.)

## View

```bash
php logo-play.php logo-molten-amber-fill-anim-tc.ansi          # animated playback
php logo-preview.php logo-molten-amber-fill-anim-tc.ansi logo-molten-amber-fill-tc.png
```

`logo-kit.php`, `logo-play.php`, `logo-preview.php` are local unmodified copies of the
candy-top house tools.

## Files

| file | role |
|---|---|
| `logo-molten-amber-fill-anim-tc.ansi` | PRIMARY — animated truecolor logo |
| `logo-molten-amber-fill-tc.ansi` | static final frame, truecolor |
| `logo-molten-amber-fill-tc.png` | raster preview of the final frame |
| `generate-molten-amber-fill.php` | generator (16-px-tall scene → half-block cells) |

## Measured stats

- Dimensions: **37 × 8** terminal columns × rows (≤ 80 × 14)
- Frames: **44** (43 animated + final static; 43 `ESC[8A\r` cursor-up separators present; ~4.4 s at 100 ms/frame)
- Truecolor: **confirmed** — 5,313 × `38;2` + 2,710 × `48;2` SGRs in the anim, 99 in the static; every line ends `\e[0m` (8/8); cursor hide/show wrapped
- Size: anim 161,722 B · static 3,319 B · PNG 1,433 B
- `lkVerify` passes on both files (uniform visible widths, resets); anim final frame geometry == static
- Final frame legibly spells "Phlix" (raster-checked)
