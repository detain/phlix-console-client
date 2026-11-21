# Channel Surf — Phlix animated ANSI logo (seat 11)

TV static bursts and rapid channel flips — muted SMPTE color bars, a blue test-card
grid, a teletext page — cut again and again until a rolling-horizon tear and RGB
ghosting resolve, the picture locks, and the wordmark **Phlix** settles in warm
amber CRT glow behind a slate bezel (CH 01, signal bars, PH-1 on-screen display).

## Regenerate

```sh
php generate-channel-surf.php --write
```

(Without `--write` it previews the animation to stdout. All outputs land in this
directory; no writes outside it.)

## View

```sh
php logo-play.php logo-channel-surf-anim-tc.ansi        # play the animation
php logo-preview.php logo-channel-surf-tc.ansi out.png  # rasterize the locked frame
```

## Measured stats

| Property | Value |
|---|---|
| Canvas | 72 columns × 12 rows (≤ 80 × 14) |
| Frames | 27 animation frames + final lock frame (28 total renders) |
| Playback | ~2.8 s at 100 ms/frame (logo-play caps at 4.8 s) |
| `logo-channel-surf-anim-tc.ansi` | 358,716 bytes |
| `logo-channel-surf-tc.ansi` | 10,586 bytes |
| Truecolor | confirmed — 6,584 × `38;2` + 10,954 × `48;2` SGR sequences, zero 8-bit/16-color codes |
| Frame separator | `\e[12A\r` present (27 occurrences) |
| Line hygiene | every line ends with `\e[0m`; all 28 frame parts uniformly 72×12 |
| Final frame | spells "Phlix" (verified on stripped text + PNG raster) |

## House idioms used

Half-block `▀` ink cells with per-row amber gradient shading + scanline darkened
backgrounds, seeded deterministic pseudo-random static (`lkNoise`), bezel framing,
`logo-kit.php` encode/verify/anim pipeline. Tool copies in this directory are
verbatim from `/home/sites/sugarcraft/candy-top/logo/tools/` and unmodified; the
originals were never touched.
