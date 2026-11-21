# Particle Swarm Assemble — logo seat 17

**Concept.** A murmuration of amber dust swirls on an elliptical flow field, then gets
magnetically snapped into the wordmark "Phlix" — particles overshoot and settle with a
spring feel. The bonus **outro** (`-outro-tc.ansi`, for app-quit) reverses the mechanics:
the letters shake, squeeze, burst back into the flock, and dissolve toward black.

## Files

| File | Role |
|---|---|
| `logo-particle-swarm-assemble-anim-tc.ansi` | PRIMARY intro: swarm → snap → settle → static Phlix |
| `logo-particle-swarm-assemble-outro-tc.ansi` | BONUS outro: Phlix → burst → swirl → fade to blank |
| `logo-particle-swarm-assemble-tc.ansi` | Static assembled wordmark (truecolor) |
| `logo-particle-swarm-assemble-tc.png` | Raster preview of the final frame |
| `generate-particle-swarm-assemble.php` | Generator (writes all four assets, self-verifying) |
| `logo-kit.php`, `logo-play.php`, `logo-preview.php` | Unmodified copies of the candy-top house tools |

## Measured stats (verified with grep/awk/php, not assumed)

| Asset | Dims | Frames | Bytes | Color |
|---|---|---|---|---|
| intro anim | 64×12 | 59 (58 `ESC[12A\r` seps + appended static) | 256,151 | truecolor `38;2` on 562 lines |
| outro anim | 64×12 | 59 (58 seps + appended blank) | 219,333 | truecolor `38;2` on 485 lines |
| static | 64×12 | 1 | 5,099 | truecolor `38;2` on 7 lines |

Playback at 80 ms/frame ≈ 4.7 s (within the 1.5–5 s window; `logo-play.php` auto-caps
total under 4.8 s). Width ≤ 80, height ≤ 14. Every line ends `\e[0m`; widths uniform
64 (lkVerify). Motion is fully deterministic: seed 9017 LCG — rebuild produces
byte-identical files (md5-proven).

## Regenerate / view

```sh
php generate-particle-swarm-assemble.php          # rebuilds all .ansi + stats
php logo-play.php logo-particle-swarm-assemble-anim-tc.ansi 70   # play intro
php logo-play.php logo-particle-swarm-assemble-outro-tc.ansi 70  # play outro (app quit)
php logo-preview.php logo-particle-swarm-assemble-tc.ansi logo-particle-swarm-assemble-tc.png
php generate-particle-swarm-assemble.php --dump   # print static frame
php generate-particle-swarm-assemble.php --frame=20            # inspect intro frame 20
php generate-particle-swarm-assemble.php --outro --frame=10    # inspect outro frame 10
```

## Craft notes

- **186 particles**, one per inked pixel of the wordmark (64×24 pixel grid, 2 half-blocks
  per char cell → 64×12). Sorted far→near for painter's order.
- **Depth:** z ∈ [0.1,1] drives orbit radius (far = wide ellipse), flight brightness
  (far = dim), and size (near motes draw 2 px tall = full cell).
- **Swirl:** elliptical vortex with angular-velocity shear (inner motes faster), radius
  breathing, drifting centre → curved flow, not rotation of a rigid cloud.
- **Snap:** per-particle staggered start (coiled so P releases first, x last),
  `easeOutBack` convergence with a radial overshoot term plus a tangential streak along
  the release-point orbit tangent → arrival reads as a spring; 1–2 frame white-hot glint
  on landing.
- **Outro:** hold → 1-frame pre-shake jitter → `easeInBack` squeeze-and-burst toward a
  *time-lead* orbit position (seamless handoff into free swirl) → flicker-dissolve to
  black; last frame is intentionally blank for quit-screen use.
- **Palette:** amber only (`#FFD9A3 → #C9691A` gradient + `#48220A` drop shadow), grain
  via `lkNoise`. House half-block ▀/▄/█ two-tone idiom. No rainbow.
