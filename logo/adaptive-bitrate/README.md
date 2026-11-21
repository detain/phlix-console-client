# Phlix — Adaptive Bitrate (concept #12)

Animated truecolor ANSI wordmark for **phlix-console-client**: "Phlix" streams in like
a video player adapting to bandwidth — a 144p macroblock mess (codec-gray blocks, the
logo's own amber-orange hues leaking through, decode scanning in left-to-right)
progressively resolves through 240p/360p/480p/720p/1080p into crisp full-detail 4K,
while a corner badge ticks the quality label up through each rung and locks Phlix-amber
on "4K". Every stage is a block-average + color-quantize of the **same hi-res master
render**, so the refinement is one artwork resolving — not different drawings.

## Regenerate

```bash
php generate-adaptive-bitrate.php            # preview static frame to stdout + stats
php generate-adaptive-bitrate.php --write    # write both .ansi deliverables
```

## View

```bash
php logo-play.php logo-adaptive-bitrate-anim-tc.ansi      # play the animation
php logo-preview.php logo-adaptive-bitrate-tc.png \
    logo-adaptive-bitrate-tc.png '#101014' 10 20          # re-rasterize the final frame
cat logo-adaptive-bitrate-tc.ansi                         # static frame only
```

`logo-kit.php`, `logo-play.php`, `logo-preview.php` are pristine copies of the
candy-top house tools (originals untouched).

## Measured stats

| metric | value |
|---|---|
| Dimensions | **78 × 12** columns×rows (limits: ≤80, ≤14) |
| Frames | **23** total = 22 animated + 1 final static |
| Playback | ≈2.3 s at 100 ms/frame (window 1.5–5 s) |
| `logo-adaptive-bitrate-anim-tc.ansi` | 345,098 bytes |
| `logo-adaptive-bitrate-tc.ansi` | 14,545 bytes |
| Truecolor | confirmed — `\e[38;2;r;g;b` / `\e[48;2;r;g;b` present (270 lines in anim, 11 in static); frame separators `\e[<H>A\r` ×22; every line ends `\e[0m`; lkVerify/lkCheckDepth clean on both files |
| Master render | 78×24-px grid, half-block (▀) idiom, 2 px/cell |
| Palette | committed amber→orange gradient `#FFD47E→#FFB347→#FF8A2A→#F2592B`; codec-gray macroblocks `#22/#24/#31`; badge chip `#F5A623` (locked) / `#7A5218` (decoding) |
