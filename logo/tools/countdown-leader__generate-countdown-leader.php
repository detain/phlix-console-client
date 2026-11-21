<?php

declare(strict_types=1);

/**
 * generate-countdown-leader — "Phlix" as a vintage film-leader countdown.
 *
 * Concept #4: a 3-2-1 academy leader — bone-white ring, crosshairs, radial
 * ticks, a red sweeping second hand that wipes a subtly lighter sector behind
 * it, one revolution per number — then a white-hot flash frame that fades down
 * onto the Phlix wordmark (bone letters with an amber base, red i-dot, an
 * amber underline rule that snaps out from the centre).
 *
 * Everything is painted on a 76x26 px grid (1 px = 1 column, 2 px = 1 row —
 * square in terminal space), half-blocked with lkHalfBlock, encoded with the
 * house logo-kit pipeline at truecolor. All outputs stay in this directory.
 *
 * Usage:
 *   php generate-countdown-leader.php           preview + verify, write files
 *   php generate-countdown-leader.php --check   verify only, no writes
 */

require __DIR__ . '/logo-kit.php';

// ================================================================ DESIGN DATA

const W = 76;                       // px columns  → 76 cells wide  (≤ 80)
const H = 26;                       // px rows     → 13 cell rows   (≤ 14)
const CX = 38.0;                    // leader circle centre
const CY = 13.0;
const R = 10.4;                     // ring radius (px)

const BG        = '#16120F';        // warm charcoal page
const SECTOR    = '#241C14';        // wiped area behind the hand (subtle)
const SPROCKET  = '#251E17';        // filmgate perforations
const BONE      = '#ECE1CD';        // ring / numerals / letters
const BONE_DIM  = '#6E6152';        // crosshairs, minor ticks
const RED       = '#C23A27';        // hand, i-dot, rule centre
const RED_HOT   = '#F1653F';        // leading tip of the hand
const AMBER     = '#DA9B2F';        // rule + warm base of the wordmark
const WHITE     = '#FFFFFF';

/** Pixel-font numerals for the leader, 7 wide × 11 tall, '#' = ink.
 *  Baseline is row 10 in every numeral — they sit on one common line. */
const DIGITS = [
    '3' => [
        '.#####.',
        '######.',
        '.....##',
        '....###',
        '..####.',
        '..####.',
        '....###',
        '.....##',
        '....###',
        '##...##',
        '.#####.',
    ],
    '2' => [
        '.#####.',
        '######.',
        '.....##',
        '.....##',
        '....###',
        '...###.',
        '..###..',
        '.###...',
        '###....',
        '#######',
        '#######',
    ],
    '1' => [
        '...##..',
        '..###..',
        '.####..',
        '##.##..',
        '...##..',
        '...##..',
        '...##..',
        '...##..',
        '...##..',
        '#######',
        '#######',
    ],
];

/** "Phlix" wordmark: 13 tall, mixed case, '#' = ink (x-height rows 5..12). */
const LOGO_FONT = [
    'P' => [
        '.#####..',
        '######..',
        '##...##.',
        '##....##',
        '##....##',
        '##...##.',
        '######..',
        '.#####..',
        '##......',
        '##......',
        '##......',
        '##......',
        '###.....',
    ],
    'h' => [
        '##......',
        '##......',
        '##......',
        '##......',
        '##......',
        '##..###.',
        '##.#####',
        '###..###',
        '###..###',
        '##...###',
        '##...###',
        '##...###',
        '##...###',
    ],
    'l' => [
        '..###.',
        '..###.',
        '...##.',
        '...##.',
        '...##.',
        '...##.',
        '...##.',
        '...##.',
        '...##.',
        '...##.',
        '...##.',
        '...##.',
        '..####',
    ],
    'i' => [
        '..#.',
        '..#.',
        '....',
        '..#.',
        '..#.',
        '..#.',
        '..#.',
        '..#.',
        '..#.',
        '..#.',
        '..#.',
        '..#.',
        '..##',
    ],
    'x' => [
        '.......',
        '.......',
        '.......',
        '.......',
        '.......',
        '###.###',
        '###.###',
        '..###..',
        '.#####.',
        '.##.##.',
        '##...##',
        '##...##',
        '##...##',
    ],
];
const LOGO_TEXT = 'Phlix';
const LOGO_GAP = 2;                 // px between letters

// ================================================================ PRIMITIVES

/** Fresh charcoal page. */
function page(): array
{
    return array_fill(0, H, array_fill(0, W, lkHex(BG)));
}

/** Antialiased 1-px splat at (x, y) with intensity a — bilinear over 4 cells. */
function splat(array &$px, float $x, float $y, array $col, float $a = 1.0): void
{
    if ($a <= 0) {
        return;
    }
    $xi = (int) floor($x);
    $yi = (int) floor($y);
    $fx = $x - $xi;
    $fy = $y - $yi;
    foreach ([
        [$xi,     $yi,     (1 - $fx) * (1 - $fy)],
        [$xi + 1, $yi,     $fx * (1 - $fy)],
        [$xi,     $yi + 1, (1 - $fx) * $fy],
        [$xi + 1, $yi + 1, $fx * $fy],
    ] as [$gx, $gy, $w]) {
        if ($gx < 0 || $gx >= W || $gy < 0 || $gy >= H || $w <= 0) {
            continue;
        }
        $px[$gy][$gx] = lkMix($px[$gy][$gx], $col, min(1.0, $a * $w));
    }
}

/** Clockwise angle from 12 o'clock, degrees, of a point relative to centre. */
function clockAngle(float $dx, float $dy): float
{
    $a = rad2deg(atan2($dx, -$dy));
    return $a < 0 ? $a + 360 : $a;
}

// ================================================================ CHROME

/** Filmgate perforations; $hair adds the full-length leader crosshairs. */
function chrome(array &$px, bool $hair = true): void
{
    for ($x = 3; $x < W - 3; $x += 5) {
        for ($y = 2; $y <= 3; $y++) {
            $px[$y][$x] = lkMix($px[$y][$x], lkHex(SPROCKET), 1.0);
            if ($x + 1 < W) {
                $px[$y][$x + 1] = lkMix($px[$y][$x + 1], lkHex(SPROCKET), 1.0);
            }
        }
        for ($y = H - 4; $y <= H - 3; $y++) {
            $px[$y][$x] = lkMix($px[$y][$x], lkHex(SPROCKET), 1.0);
            if ($x + 1 < W) {
                $px[$y][$x + 1] = lkMix($px[$y][$x + 1], lkHex(SPROCKET), 1.0);
            }
        }
    }
    if (!$hair) {
        return;
    }
    $dim = lkHex(BONE_DIM);
    for ($y = 0; $y < H; $y++) {          // vertical hair (column 38)
        $px[$y][38] = lkMix($px[$y][38], $dim, 0.8);
    }
    for ($x = 0; $x < W; $x++) {          // horizontal hair (row 13)
        $px[13][$x] = lkMix($px[13][$x], $dim, 0.8);
    }
}

/** Ring, outside the wiped disc — house half-block idiom for smooth circles. */
function ring(array &$px): void
{
    $bone = lkHex(BONE);
    $x0 = (int) floor(CX - R - 2);
    $x1 = (int) ceil(CX + R + 2);
    $y0 = (int) floor(CY - R - 2);
    $y1 = (int) ceil(CY + R + 2);
    for ($y = max(0, $y0); $y <= min(H - 1, $y1); $y++) {
        for ($x = max(0, $x0); $x <= min(W - 1, $x1); $x++) {
            $d = hypot($x + 0.0 - CX, $y - CY);
            $a = 1.0 - abs($d - R) / 1.1;
            if ($a > 0) {
                $px[$y][$x] = lkMix($px[$y][$x], $bone, min(1.0, $a));
            }
        }
    }
}

/** Radial tick marks: minor every 30 deg (dim, short), cardinal ticks brighter. */
function ticks(array &$px): void
{
    for ($k = 0; $k < 12; $k++) {
        $ang = deg2rad($k * 30);
        $major = $k % 3 === 0;
        $len = $major ? 2.2 : 1.1;
        $col = lkMix(lkHex(BONE_DIM), lkHex(BONE), $major ? 0.9 : 0.15);
        $a = $major ? 1.0 : 0.8;
        for ($s = R + 1.1; $s <= R + 1.1 + $len; $s += 0.22) {
            splat($px, CX + $s * sin($ang), CY - $s * cos($ang), $col, $a);
        }
    }
}

// ================================================================ COUNTDOWN

/**
 * One leader frame: numeral, swept sector, red hand at $theta (deg, CW from 12).
 */
function frameCountdown(string $digit, float $theta): array
{
    $px = page();
    // wiped sector: inside the disc, clockwise from 12 to the hand
    if ($theta > 0.5) {
        $cov = lkHex(SECTOR);
        for ($y = (int) max(0, CY - R); $y <= (int) min(H - 1, CY + R); $y++) {
            for ($x = (int) max(0, CX - R); $x <= (int) min(W - 1, CX + R); $x++) {
                $dx = $x - CX;
                $dy = $y - CY;
                if (hypot($dx, $dy) < R - 0.9) {
                    $a = clockAngle($dx, $dy);
                    if ($a < $theta) {
                        $px[$y][$x] = $cov;
                    }
                }
            }
        }
    }
    chrome($px);
    ring($px);
    ticks($px);

    // red pivot (behind the numeral, under the hand)
    $red = lkHex(RED);
    for ($y = (int) CY - 1; $y <= (int) CY + 1; $y++) {
        for ($x = (int) round(CX) - 1; $x <= (int) round(CX) + 1; $x++) {
            if (hypot($x - CX, $y - CY) <= 1.5 && $y >= 0 && $y < H && $x >= 0 && $x < W) {
                $px[$y][$x] = lkMix($px[$y][$x], $red, 0.95);
            }
        }
    }

    // numeral, centred in the disc
    $mask = DIGITS[$digit];
    $mw = strlen($mask[0]);
    $mh = count($mask);
    $ox = CX - $mw / 2 - 0.5;
    $oy = CY - $mh / 2 - 0.5;
    $bone = lkHex(BONE);
    foreach ($mask as $rI => $row) {
        for ($c = 0; $c < $mw; $c++) {
            if ($row[$c] === '#') {
                $py = (int) round($oy + $rI);
                $pxx = (int) round($ox + $c);
                if ($py >= 0 && $py < H && $pxx >= 0 && $pxx < W) {
                    $px[$py][$pxx] = $bone;
                }
            }
        }
    }

    // sweeping hand, brighter toward the tip
    $ang = deg2rad($theta);
    $hot = lkHex(RED_HOT);
    for ($r = 1.6; $r <= R + 2.6; $r += 0.2) {
        $t = max(0.0, min(1.0, ($r - 1.6) / (R + 1)));
        $col = lkMix($red, $hot, $t);
        splat($px, CX + $r * sin($ang), CY - $r * cos($ang), $col, 1.0);
    }
    // white-hot bead riding the tip
    $tip = R + 2.6;
    splat($px, CX + $tip * sin($ang), CY - $tip * cos($ang), lkMix($hot, lkHex(WHITE), 0.55), 1.0);
    return $px;
}

// ================================================================ LOGO

/**
 * Final wordmark frame. $rule = underline extension 0..1; $white = flash
 * overlay 0..1 mixed over every pixel (white-hot leader-to-logo jump cut).
 */
function frameLogo(float $rule = 1.0, float $white = 0.0): array
{
    $px = page();
    chrome($px, false);

    // --- wordmark -----------------------------------------------------------
    $widths = [];
    $total = 0;
    foreach (str_split(LOGO_TEXT) as $ch) {
        $widths[$ch] = strlen(LOGO_FONT[$ch][0]);
        $total += $widths[$ch] + LOGO_GAP;
    }
    $total -= LOGO_GAP;
    $x = intdiv(W - $total, 2);
    $yTop = 3;
    $bone = lkHex(BONE);
    $amber = lkHex(AMBER);
    $maskStartX = [];
    foreach (str_split(LOGO_TEXT) as $ch) {
        $mask = LOGO_FONT[$ch];
        foreach ($mask as $rI => $row) {
            for ($c = 0, $cw = strlen($row); $c < $cw; $c++) {
                if ($row[$c] !== '#') {
                    continue;
                }
                $py = $yTop + $rI;
                $pxx = $x + $c;
                if ($py < 0 || $py >= H || $pxx < 0 || $pxx >= W) {
                    continue;
                }
                // lit bone top, amber-warmed base
                $t = $rI / (count($mask) - 1);
                $col = lkMix($bone, $amber, max(0.0, $t - 0.55) / 0.45 * 0.55);
                if ($ch === 'i' && $rI <= 1) {
                    $col = lkHex(RED);     // the i-dot is the leader's pivot
                }
                $px[$py][$pxx] = lkMix($px[$py][$pxx], $col, 1.0);
            }
        }
        $maskStartX[$ch] = $x;
        $x += $widths[$ch] + LOGO_GAP;
    }

    // --- amber rule, snapping out from the centre ---------------------------
    $ry = 19;
    $halfMax = 21.0;
    $half = $halfMax * max(0.0, min(1.0, $rule));
    for ($dx = -$half; $dx <= $half; $dx += 0.34) {
        $pxx = (int) round(W / 2 - 0.5 + $dx);
        if ($pxx >= 0 && $pxx < W) {
            $edge = min(1.0, ($half - abs($dx)) / 1.2);
            $px[$ry][$pxx] = lkMix($px[$ry][$pxx], $amber, 0.9 * $edge);
        }
    }
    // red centre bead on the rule + tiny crosshair ticks above/below it
    $red = lkHex(RED);
    $ccx = (int) round(W / 2 - 0.5);
    splat($px, $ccx, $ry, $red, 1.0);
    splat($px, $ccx, $ry - 1, $red, 0.8);
    splat($px, $ccx, $ry + 1, $red, 0.8);
    splat($px, $ccx - 1, $ry, $red, 0.8);
    splat($px, $ccx + 1, $ry, $red, 0.8);

    // --- flash overlay ------------------------------------------------------
    if ($white > 0) {
        $w = lkHex(WHITE);
        $k = min(1.0, $white);
        for ($y = 0; $y < H; $y++) {
            for ($x2 = 0; $x2 < W; $x2++) {
                $px[$y][$x2] = lkMix($px[$y][$x2], $w, $k);
            }
        }
    }
    return $px;
}

// ================================================================ SEQUENCE

/** @return list<array> pixel-grid frames (final static excluded) */
function sequence(): array
{
    $sweep = static function (string $d, int $n): array {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = frameCountdown($d, 360.0 * $i / $n);
        }
        return $out;
    };
    $frames = [];
    $frames[] = frameCountdown('3', 0);       // settle on the 3
    $frames = array_merge($frames, $sweep('3', 14));
    $frames[] = frameCountdown('2', 0);       // jump cut resets the wipe
    $frames[] = frameCountdown('2', 14);
    $frames = array_merge($frames, $sweep('2', 14));
    $frames[] = frameCountdown('1', 0);
    $frames[] = frameCountdown('1', 14);
    $frames = array_merge($frames, $sweep('1', 14));
    $frames[] = frameLogo(1.0, 1.0);          // white-hot flash
    $frames[] = frameLogo(1.0, 0.62);         // ...fading onto the logo
    $frames[] = frameLogo(1.0, 0.30);
    $frames[] = frameLogo(1.0, 0.10);
    $frames[] = frameLogo(0.45, 0.0);         // rule snaps out
    $frames[] = frameLogo(0.82, 0.0);
    return $frames;
}

// ================================================================ MAIN

$check = in_array('--check', $argv, true);

// debug: dump one animation frame as its own .ansi for logo-preview eyeballing
$dumpAt = array_search('--dump', $argv, true);
if ($dumpAt !== false) {
    $frames = array_map(static fn (array $p): string => lkEncode(lkHalfBlock($p), 'tc'), sequence());
    $n = (int) $argv[$dumpAt + 1];
    file_put_contents(__DIR__ . "/dump-$n.ansi", $frames[$n]);
    exit("dumped frame $n of " . count($frames) . "\n");
}

// static final frame
$staticTc = lkEncode(lkHalfBlock(frameLogo(1.0, 0.0)), 'tc');
[$w, $h] = lkVerify($staticTc, 'static');
lkCheckDepth($staticTc, 'tc', 'static');

// animation
$animFrames = array_map(
    static fn (array $px): string => lkEncode(lkHalfBlock($px), 'tc'),
    sequence()
);
$animTc = lkAnim($animFrames, $staticTc);
[$aw, $ah] = lkVerify(lkLastFrame($animTc), 'anim');
lkCheckDepth($animTc, 'tc', 'anim');
$nFrames = count($animFrames) + 1;

printf("static %dx%d  anim %dx%d  frames=%d  static=%dB anim=%dB\n", $w, $h, $aw, $ah, $nFrames, strlen($staticTc), strlen($animTc));

if (!$check) {
    file_put_contents(__DIR__ . '/logo-countdown-leader-tc.ansi', $staticTc);
    file_put_contents(__DIR__ . '/logo-countdown-leader-anim-tc.ansi', $animTc);
    fwrite(STDERR, "wrote logo-countdown-leader-tc.ansi + logo-countdown-leader-anim-tc.ansi\n");
}
