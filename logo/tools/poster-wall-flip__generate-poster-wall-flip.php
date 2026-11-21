<?php

declare(strict_types=1);

/**
 * generate-poster-wall-flip — "Poster Wall Flip" concept for Phlix.
 *
 * A wall of tiny library poster cards (muted artwork fronts: horizon+sun,
 * diagonal stripes, play-button features) flips card-by-card in a diagonal
 * wave sweeping left→right, top→bottom. Each card squeezes horizontally to a
 * bright spine (full → edge-on → back) and the dark kraft BACKS together
 * reveal the bright Phlix wordmark. The animation settles on the static
 * final frame: the glowing logo on the finished poster wall.
 *
 * Grid: 12×5 tiles of 5×4 px on 1-px wall seams → 73 cols × 26 px = 13 rows
 * (half-block cells, the house idiom). The wordmask is a 5×9 pixel font
 * upscaled 2× (18 px tall), centred on the wall.
 *
 * Usage:
 *   php generate-poster-wall-flip.php            preview static tc to stdout (+verify)
 *   php generate-poster-wall-flip.php --write    write logo-poster-wall-flip[-anim]-tc.ansi here
 *   php generate-poster-wall-flip.php --anim     play the tc animation (logo-play.php)
 *
 * Only truecolor deliverables are written (the campaign brief's primary);
 * all writes stay inside this directory — no logos.jsonl side effect.
 */

require __DIR__ . '/logo-kit.php';

const SLUG = 'poster-wall-flip';
const TEXT = 'Phlix';

// ---------------------------------------------------------------- wall grid
const COLS = 12;            // tiles across
const ROWS = 5;             // tiles down
const TW = 5;               // tile width  (px = cols)
const TH = 4;               // tile height (px; 4 px = 2 half-block rows)
const PITCH_X = TW + 1;     // 6: tile + 1-px seam
const PITCH_Y = TH + 1;     // 5
const PW = COLS * TW + COLS + 1;   // 73 columns
const PH = ROWS * TH + ROWS + 1;   // 26 px = 13 rows

// ---------------------------------------------------------------- wordmask
/** 5×9 pixel font (1-px strokes; upscaled ×2 → 2-px strokes, 18 px tall). */
const FONT = [
    'P' => ['####.', '#...#', '#...#', '#...#', '####.', '#....', '#....', '#....', '#....'],
    'h' => ['#....', '#....', '#.##.', '##.##', '#..##', '#..##', '#..##', '#..##', '#..##'],
    'l' => ['..#', '..#', '..#', '..#', '..#', '..#', '..#', '..#', '.##'],
    'i' => ['.#.', '...', '.#.', '.#.', '.#.', '.#.', '.#.', '.#.', '.#.'],
    'x' => ['.....', '.....', '#...#', '#...#', '.#.#.', '..#..', '.#.#.', '#...#', '#...#'],
];
const LETTER_GAP = 2;               // px between glyphs (pre-scale)

// Logo placement on the wall (centred block; 15 px slack → 7/7 cols inside the wall, 8 → 4/4 rows).
const MASK_W = 58;                  // (5+2+5+2+3+2+3+2+5)*2 — checked by inkMask()
const MASK_H = 18;
const LOGO_X0 = 7;
const MASK_Y0 = 4;

// ---------------------------------------------------------------- palettes
/** Muted poster-art inks (mustard, brick, teal, olive, rose, slate, sienna, green). */
const PALETTE = ['#B8933F', '#9E4F44', '#3E7B76', '#6E7B3F', '#A06A7D', '#4F6178', '#8A5734', '#4E7A57'];
const TITLE_BAR = '#E7DCBE';   // every poster's little title strip
const SUN = '#F2E8CF';
const SIL = '#211D26';         // dark silhouette on play-button posters
const WALL = '#131019';        // gap / seam / border
const SPINE = '#F4EEDF';       // card edge catching light mid-flip
const BACK_A = '#2A2433';      // kraft back, two tile tones
const BACK_B = '#251F2D';
const LOGO = ['#FF2E63', '#FF5A36', '#FF8A2B', '#FFC24B'];  // coral → amber brand ramp

// ---------------------------------------------------------------- timing
const T0 = 2.5;                 // frames of wall before the wave starts
const DUR = 9.0;                // frames per card flip
const N_FRAMES = 27;            // animation frames; last tiles settle ~f25 → hold + static

const DESCRIPTION = 'A wall of muted library poster cards flips in a diagonal wave; the kraft backs together reveal the glowing coral-amber Phlix wordmark.';

// ================================================================ wordmask

/** @return array<int, array<int, bool>> y(0..17) => x(0..61) => inked */
function inkMask(): array
{
    static $mask = null;
    if ($mask !== null) {
        return $mask;
    }
    $chars = str_split(TEXT);
    // build 9 base rows
    $base = array_fill(0, 9, '');
    foreach ($chars as $i => $ch) {
        $g = FONT[$ch];
        foreach ($g as $y => $line) {
            $base[$y] .= $line;
        }
        if ($i !== count($chars) - 1) {
            foreach ($base as $y => $_) {
                $base[$y] .= str_repeat('.', LETTER_GAP);
            }
        }
    }
    $w = strlen($base[0]);
    if ($w * 2 !== MASK_W) {
        throw new RuntimeException("mask width $w*2 != MASK_W " . MASK_W);
    }
    // upscale 2× nearest → 18 × 62
    $mask = [];
    for ($y = 0; $y < 9; $y++) {
        for ($sy = 0; $sy < 2; $sy++) {
            $r = [];
            for ($x = 0; $x < $w; $x++) {
                $on = $base[$y][$x] === '#';
                $r[] = $on;
                $r[] = $on;
            }
            $mask[2 * $y + $sy] = $r;
        }
    }
    return $mask;
}

// ================================================================ tile content

/** Deterministic per-tile values: palette index, art kind, brightness jitter. */
function tileSeed(int $col, int $row): array
{
    $n1 = lkNoise($col, $row, 41);
    $n2 = lkNoise($col, $row, 77);
    $n3 = lkNoise($col, $row, 131);
    return [
        (int) floor($n1 * count(PALETTE)),      // palette slot
        (int) floor($n2 * 3),                    // art kind 0..2
        0.92 + 0.16 * $n3,                       // slight per-print brightness
    ];
}

/** Front of a poster card: local (u,v) rgb — tiny deliberate artwork. */
function tileFront(int $col, int $row, int $u, int $v): array
{
    [$pi, $kind, $jitter] = tileSeed($col, $row);
    $base = lkScale(lkHex(PALETTE[$pi]), $jitter);
    if ($kind === 0) {                       // landscape: sky, sun, ground, title bar
        if ($v === 0) {
            $c = lkShade($base, 0.18);
            $d = ($u - 3.5) ** 2 + (($v - 0.6) * 2.2) ** 2;
            if ($d < 1.6) {
                $c = lkMix($c, lkHex(SUN), 0.85);
            }
        } elseif ($v === 1) {
            $c = lkShade($base, 0.06);
            $d = ($u - 3.5) ** 2 + (($v - 0.6) * 2.2) ** 2;
            if ($d < 1.6) {
                $c = lkMix($c, lkHex(SUN), 0.5);
            }
        } elseif ($v === 2) {
            $c = lkShade($base, -0.32);
        } else {
            $c = lkShade($base, -0.5);
        }
    } elseif ($kind === 1) {                 // diagonal stripe print
        $c = (($u + $v) % 3 === 0) ? lkShade($base, -0.42) : lkShade($base, $v === 0 ? 0.12 : -0.05);
    } else {                                  // featured: play-button card
        $c = lkShade($base, $v === 0 ? 0.14 : -0.06);
        if (($u === 2 && $v >= 1 && $v <= 2) || ($u === 3 && $v === 2)) {
            $c = lkHex(SIL);
        }
    }
    if ($v === 3 && $u >= 1 && $u <= 3) {    // every print gets a title strip
        $c = lkMix($c, lkHex(TITLE_BAR), 0.55);
    }
    return $c;
}

/** Back of a card at wall-global (xs, y): logo ink or kraft. */
function tileBack(int $xs, int $y, int $u, int $v): array
{
    $mx = $xs - LOGO_X0;
    $my = $y - MASK_Y0;
    $mask = inkMask();
    if ($my >= 0 && $my < MASK_H && $mx >= 0 && $mx < MASK_W && $mask[$my][$mx]) {
        $c = lkGradient(LOGO, $mx / (MASK_W - 1));
        return lkShade($c, 0.26 * (1 - 2 * $my / (MASK_H - 1)));   // lit top, deep base
    }
    $kraft = lkNoise($xs, $y, 7) > 0.62 ? lkHex(BACK_A) : lkHex(BACK_B);
    if ($u === 0 || $u === TW - 1) {
        $kraft = lkScale($kraft, 0.8);                              // card edge shading
    }
    return $kraft;
}

/** Wall (seam / border) pixel: quiet dark with faint grain + centre lift. */
function wallPixel(int $x, int $y): array
{
    $c = lkHex(WALL);
    $dx = ($x - (PW - 1) / 2) / (PW / 2);
    $dy = ($y - (PH - 1) / 2) / (PH / 2);
    $c = lkScale($c, 1.28 - 0.34 * min(1.0, $dx * $dx + $dy * $dy));
    if (lkNoise($x, $y, 5) > 0.93) {
        $c = lkShade($c, 0.28);
    }
    return $c;
}

// ================================================================ flip wave

/** Diagonal wave delay (frames): left→right faster, top→bottom adds. */
function tileDelay(int $col, int $row): float
{
    return $col * 0.9 + $row * 0.65 + 0.3 * lkNoise($col, $row, 21);
}

/** One wall pixel at animation time $t (null = settled backs = static). */
function wallPixelAt(int $x, int $y, ?float $t): array
{
    $gx = $x - 1;
    $gy = $y - 1;
    if ($gx < 0 || $gy < 0) {
        return wallPixel($x, $y);
    }
    $col = intdiv($gx, PITCH_X);
    $u = $gx % PITCH_X;
    $row = intdiv($gy, PITCH_Y);
    $v = $gy % PITCH_Y;
    if ($col >= COLS || $u >= TW || $row >= ROWS || $v >= TH) {
        return wallPixel($x, $y);
    }
    $p = $t === null
        ? 1.0
        : max(0.0, min(1.0, ($t - T0 - tileDelay($col, $row)) / DUR));
    if ($p <= 0.0) {
        return tileFront($col, $row, $u, $v);
    }
    if ($p >= 1.0) {
        return tileBack($x, $y, $u, $v);
    }
    $s = cos(M_PI * $p);                 // 1 → 0 → −1 : front→spine→back
    $a = abs($s);
    if ($a < 0.16) {
        return $u === intdiv(TW, 2) ? lkHex(SPINE) : wallPixel($x, $y);
    }
    if (abs($u - intdiv(TW, 2)) > $a * (TW - 1) / 2 + 1e-9) {
        return wallPixel($x, $y);        // squeezed-off area shows wall
    }
    $us = (int) round(intdiv(TW, 2) + ($u - intdiv(TW, 2)) / $s);   // mirrored sample
    $c = $s > 0
        ? tileFront($col, $row, $us, $v)
        : tileBack($col * PITCH_X + 1 + $us, $y, $us, $v);
    return lkShade($c, 0.2 * (1 - $a));  // sheen as the card turns
}

/** Pixel grid (PH rows × PW cols) at time $t. */
function pixels(?float $t): array
{
    $px = [];
    for ($y = 0; $y < PH; $y++) {
        $row = [];
        for ($x = 0; $x < PW; $x++) {
            $row[] = wallPixelAt($x, $y, $t);
        }
        $px[] = $row;
    }
    return $px;
}

function cells(?float $t = null): array
{
    return lkHalfBlock(pixels($t));
}

// ================================================================ CLI

$write = in_array('--write', $argv, true);
$play = in_array('--anim', $argv, true);
$dir = __DIR__;

$static = lkEncode(cells(null), 'tc');
[$w, $h] = lkVerify($static, 'tc-static');
lkCheckDepth($static, 'tc', 'tc-static');
echo $static, "\n";
fwrite(STDERR, "[tc] static {$w}x{$h} ok\n");

$frames = [];
for ($f = 0; $f < N_FRAMES; $f++) {
    $frames[] = lkEncode(cells((float) $f), 'tc');
}
$anim = lkAnim($frames, $static);
[$aw, $ah] = lkVerify(lkLastFrame($anim), 'tc-anim-final');
lkCheckDepth($anim, 'tc', 'tc-anim');
$total = count($frames) + 1;
fwrite(STDERR, "[tc] anim {$aw}x{$ah}, $total frames ok\n");

if ($write) {
    file_put_contents("$dir/logo-" . SLUG . "-tc.ansi", $static);
    file_put_contents("$dir/logo-" . SLUG . "-anim-tc.ansi", $anim);
    fwrite(STDERR, '  wrote logo-' . SLUG . "-tc.ansi (" . strlen($static) . " B)\n");
    fwrite(STDERR, '  wrote logo-' . SLUG . "-anim-tc.ansi (" . strlen($anim) . " B)\n");
    fwrite(STDERR, "  " . DESCRIPTION . "\n");
}

if ($play) {
    passthru('php ' . escapeshellarg("$dir/logo-play.php") . ' ' . escapeshellarg("$dir/logo-" . SLUG . '-anim-tc.ansi') . ' 90');
    echo "\n";
}
