# Film Strip Reel-In — Phlix (phlix-console-client ANSI logo, concept #3)

A 35mm film strip carrying one letter per frame whips in from the right with horizontal motion-blur ghosts, brakes with a rebound and gate-rattle, and snaps into lock under a projector flash; a warm light-leak band sweeps the frame and burns off, leaving the final logo: sprocket-perforated film stock with "Phlix" silhouetted in glowing cream windows.

## Regenerate
```bash
php generate-film-strip-reel-in.php --write
```
Writes logo-film-strip-reel-in-tc.ansi (static), logo-film-strip-reel-in-anim-tc.ansi (animated) and rasterizes the PNG preview via logo-preview.php. Bare `php generate-film-strip-reel-in.php` prints the static frame to stdout; `--anim` plays the animation in place; `--frame=N` dumps one raw frame; `--roles` prints the per-frame kinematics table.

## View
```bash
php logo-play.php logo-film-strip-reel-in-anim-tc.ansi
```

## Stats (measured)
- Dimensions: 75 cols x 14 rows (lkVerify uniform widths, limits 80x14)
- Frames: 44 animated + settled final static = 45 segments (44 x "\\e[14A\\r" separators; final frame byte-identical to the static .ansi)
- Playback: ~4.5 s at logo-play pacing (delay min(100ms, 4800/45))
- Sizes: anim 874,108 B; static 21,328 B; PNG preview 4,287 B
- Truecolor confirmed: grep -c "38;2" -> 630 lines in the anim file, 14 in the static
- Palette: film-stock blacks, sepia/cream windows, ivory perforations, warm amber glow + light-leak accent; half-block (▀/▄) shading, grain via lkNoise

Tools: logo-kit.php / logo-play.php / logo-preview.php are unmodified copies of the candy-top house tools; all writes stay inside this directory (lkWriteLogo/jsonl side effect not used).
