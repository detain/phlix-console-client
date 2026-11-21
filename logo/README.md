# Phlix console — animated truecolor ANSI logos

Twenty logo concepts for the phlix-console-client boot screen: each `logo/<slug>/`
directory carries one animated **truecolor ANSI** wordmark (rendered in the
candy-top half-block house idiom), its static final frame, a PNG raster preview,
and the seat's own README with design notes and measured stats. Every animation
is 1.5–5 s, fits an 80×14 cell budget, uses only `\e[38;2;r;g;b` / `\e[48;2;…`
24-bit SGRs, and ends on a clean static frame (the last animation frame is
byte-identical to the `*-tc.ansi` static file).

## Play a logo

The `.ansi` files are self-contained terminal byte streams (cursor-up frame
separators, cursor hide/show bookends, per-line `\e[0m` resets). In any
truecolor-capable terminal, plain cat plays the animation — output speed is the
frame clock, and it lands on the static frame:

```sh
cat logo/terminal-prompt/logo-terminal-prompt-anim-tc.ansi
```

For paced playback (explicit ms/frame), use the house player from the kit it was
built against — `/home/sites/sugarcraft/candy-top/logo/tools/logo-play.php`:

```sh
php /home/sites/sugarcraft/candy-top/logo/tools/logo-play.php \
    logo/terminal-prompt/logo-terminal-prompt-anim-tc.ansi 60
```

To rasterize a static frame to PNG, use the kit's `logo-preview.php` the same way.

## Regenerate a logo

Each generator lives in `logo/tools/<slug>__generate-<slug>.php`. Generators
`require __DIR__ . '/logo-kit.php'` and write their deliverables to their **own
directory** (`__DIR__`), so a tools/ copy runs only where a `logo-kit.php`
sits beside it (upstream kit: `/home/sites/sugarcraft/candy-top/logo/tools/`).
To regenerate in place, copy the generator (and the kit) into the logo dir, or
point a scratch dir at them; the outputs land next to the script.

- 17 of the generators take `--write` to (re)write the `.ansi` deliverables; a
  bare run previews the static frame to stdout.
- `countdown-leader`, `particle-swarm-assemble`, and `terminal-prompt` write on
  every run (countdown-leader offers `--check` for verify-only).
- Many also support `--anim` (play from source), `--frame=N` / `--dump` (single
  frame to stdout). Per-concept usage is in each generator header and each
  `logo/<slug>/README.md`. Output is deterministic (seeded) — re-runs produce
  byte-identical files.

Seat copies of the stock kit tools (`logo-kit.php`, `logo-play.php`,
`logo-preview.php`) were verified byte-identical to upstream and deliberately
NOT duplicated into `logo/tools/` — only the 20 per-concept generators live there.

## The 20 concepts

| slug | dims (cols×rows) | anim frames | concept |
|------|------------------|----------------------|---------|
| `amber-phosphor-boot` | 58×10 | 48 | Amber CRT boots: a hot raster line expands, phosphor snow collapses, each letter of Phlix burns in and cools to steady glow behind a scanline bezel. |
| `projector-beam` | 76×13 | 72 | A cinema projector ignites a dust-filled beam across a dark theater and throws Phlix onto the screen; film jitter and scratches settle to a clean end frame. |
| `film-strip-reel-in` | 75×14 | 44 | A 35mm strip carrying one letter per frame whips in from the right with motion-blur ghosts, brakes with a rebound, and snaps into lock under a projector flash. |
| `countdown-leader` | 76×13 | 53 | Vintage academy countdown leader: sweeping red hand, 3-2-1, white-hot flash fading onto the Phlix wordmark with the leader's pivot surviving as the red i-dot. |
| `clapperboard-snap` | 52×11 | 38 | The slate claps shut, the screen shakes on impact, and the board flips edge-over to reveal Phlix on its back face. |
| `equalizer-skyline` | 60×10 | 80 | Music bars bounce to a groove, then drain column-by-column, leaving Phlix carved out of the level meter as a dark knockout. |
| `oscilloscope-signature` | 64×12 | 60 | A glowing amber scope beam strokes across a CRT face and signs "Phlix" as one continuous cursive with a decaying phosphor trail. |
| `vinyl-and-reel` | 78×14 | 41 | A grooved vinyl record and a film reel roll in spinning from either side to dock beside the Phlix wordmark — music meets movies. |
| `poster-wall-flip` | 73×13 | 27 | A wall of library poster cards flips in a diagonal wave, the kraft backs together revealing the coral→amber Phlix wordmark. |
| `buffering-to-ready` | 48×10 | 66 | A buffering UI crawls to 99% and stalls, flashes a warm READY burst, then a white-hot edge sweeps it away to reveal the assembled Phlix logo. |
| `channel-surf` | 72×12 | 27 | TV static, SMPTE bars, and test-card grids channel-surf until the picture locks: Phlix in warm amber CRT glow behind a slate bezel. |
| `adaptive-bitrate` | 78×12 | 22 | A 144p macroblock mess progressively resolves through 240p/360p/480p/720p/1080p to crisp 4K while the quality badge ticks up and locks amber "4K" Phlix. |
| `marquee-lights` | 64×13 | 55 | Cinema marquee: round bulbs chase the border circuit while neon Phlix letters sputter on one by one, settling to a steady glow with a slow idle chase. |
| `golden-light-sweep` | 60×9 | 36 | Dark brushed-metal Phlix letters; a diagonal beam sweeps across and wherever it passes the metal gleams hot gold, cooled back with a resting specular. |
| `molten-amber-fill` | 37×8 | 43 | Empty dark-glass Phlix outlines fill as a molten amber tide rises letter by letter with an undulating meniscus, then cools to cured resin. |
| `media-glyph-rain` | 44×14 | 82 | A seeded rain of media glyphs falls; drops that cross letter cells stick and build Phlix out of the symbols, then a merge wave settles them solid. |
| `particle-swarm-assemble` | 64×12 | 58 | A murmuration of amber dust swirls on an elliptical flow field and snaps magnetically into Phlix with spring overshoot; the bonus outro file disassembles it for app-quit. |
| `iris-aperture` | 52×12 | 60 | A camera iris opens from a hexagonal pinhole — six gunmetal blades rotating outward, hexagon morphing to circle — revealing amber Phlix with blade-edge rim light. |
| `rotating-3d-wordmark` | 58×14 | 54 | A 3D-extruded Phlix with lambert-shaded depth slices spins about its vertical axis, overshoots, and settles square with a specular glint pass and floor reflection; an outro file spins it away. |
| `terminal-prompt` | 58×11 | 29 | A real terminal session types `phlix`, Enter executes, the wordmark banner prints top-down in amber half-blocks, a green ✓ status line reveals, cursor blinks. |

Seat dirs for `particle-swarm-assemble` and `rotating-3d-wordmark` also ship a
`logo-<slug>-outro-tc.ansi` quit-screen animation; `clapperboard-snap` ships an
extra `-anim-tc.png` raster of its animation's last frame. Each seat's README.md
records the authoritative measured stats.

## Alternates held outside the repo

Two disclosed twin alternates from the wave remain in
`/tmp/opencode/phlix-ansi-logo-1120/` and were NOT collected:
`19-rotating-3d-wordmark-b/` and `20-terminal-prompt-b/`.
