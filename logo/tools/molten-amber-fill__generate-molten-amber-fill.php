<?php

declare(strict_types=1);

/**
 * generate-molten-amber-fill — "Phlix" poured from liquid amber.
 *
 * Concept #15: Molten Amber Fill. The wordmark stands as empty dark-glass
 * flask outlines; a molten amber tide spreads along the base, then rises
 * letter by letter — a sin-wave meniscus undulating frame to frame, hot
 * flow lines streaking through the melt, bubbles climbing just under the
 * surface. When every stroke is full, a global cooling parameter damps the
 * ripples to frozen stillness, the emissive glow calms to polished resin,
 * and one diagonal specular band snaps in across the letters like cured
 * epoxy. Amber family only: #FFB000 core → #E07800 → #8A4A00 edge.
 *
 * Uses the local copy of the house toolkit (logo-kit.php). Half-block
 * shading idiom, truecolor output, ~4.4 s playback at 100 ms/frame.
 *
 * Usage:
 *   php generate-molten-amber-fill.php            preview tc to stdout (+verify)
 *   php generate-molten-amber-fill.php --write    write the .ansi deliverables
 *   php logo-play.php logo-molten-amber-fill-anim-tc.ansi
 *   php logo-preview.php logo-molten-amber-fill-anim-tc.ansi logo-molten-amber-fill-tc.png
 */

require __DIR__ . '/logo-kit.php';

// ================================================================ DESIGN DATA

const SLUG = 'molten-amber-fill';
const TEXT = 'Phlix';

/** Bold pixel font: 13 rows, stroke width 2. Cap/ascender y0, x-height y6, baseline y12. */
const FONT = [
    'P' => [
        '######.',
        '######.',
        '##...##',
        '##...##',
        '##...##',
        '##...##',
        '######.',
        '######.',
        '##.....',
        '##.....',
        '##.....',
        '##.....',
        '##.....',
    ],
    'h' => [
        '##.....',
        '##.....',
        '##.....',
        '##.....',
        '##.....',
        '##..##.',
        '##.####',
        '##...##',
        '##...##',
        '##...##',
        '##...##',
        '##...##',
        '##...##',
    ],
    'l' => [
        '##.', '##.', '##.', '##.', '##.', '##.', '##.',
        '##.', '##.', '##.', '##.', '##.', '##.',
    ],
    'i' => [
        '##.',
        '##.',
        '...',
        '...',
        '...',
        '...',
        '##.',
        '##.',
        '##.',
        '##.',
        '##.',
        '##.',
        '##.',
    ],
    'x' => [
        '.......',
        '.......',
        '.......',
        '.......',
        '.......',
        '.......',
        '##...##',
        '.##.##.',
        '..###..',
        '.##.##.',
        '##...##',
        '##...##',
        '##...##',
    ],
];

const LETTER_GAP = 2;
const MARGIN = 1;
const TOP_PAD = 1;              // font occupies pixel rows 1..13
const POOL_TOP = 14;            // molten base tide, rows 14-15
const H_PX = 16;                // 8 terminal rows after half-blocking

/** Amber family — this concept IS the brand colour. */
const CORE = '#FFB000';         // bright where thick
const MID = '#E07800';
const DEEP = '#8A4A00';         // dark near edges / depth
const HOT = '#FFD36B';          // meniscus crest while molten
const HOTLINE = '#FFC24A';      // hot flow lines
const BUB = '#FFE7AE';          // bubbles
const SPEC = '#FFEFB8';         // cured-resin specular
const GLASS = '#3C2A16';        // empty dark-glass flask edge
const GLASS_HI = '#5A421F';     // rim glint on the empty glass

/** Per-letter pour schedule (fractions of playback) + phase windows. */
const STARTS = [0.00, 0.07, 0.13, 0.18, 0.23];
const RISE_DUR = 0.30;
const COOL0 = 0.56;
const COOL1 = 0.92;
const SPEC0 = 0.82;             // specular snaps in over cool ∈ [SPEC0, 1]
const TIDE_DUR = 0.12;          // base pool spreads left→right

const FRAMES = 44;              // incl. the final static frame (~4.4 s @100 ms)
const SPEC_D0 = 24.6;           // specular band: x + 0.75·y ≈ SPEC_D0
const DESCRIPTION = 'Phlix poured as molten amber: dark-glass letter outlines fill with a rising, undulating amber tide (hot meniscus, flow lines, bubbles), then cool and set into polished resin with one specular band.';

// ================================================================ LAYOUT MASKS

/**
 * Static scene data derived once from the font: ink map on the padded pixel
 * canvas, edge mask, per-column ink top/bottom + owning letter.
 * @return array{0:int,1:list<string>,2:list<list<bool>>,3:list<list<bool>>,4:array<int,int>,5:array<int,int>,6:array<int,int>}
 */
function scene(): array
{
    static $s = null;
    if ($s !== null) {
        return $s;
    }
    [$mask, $ranges] = lkLayout(FONT, TEXT, LETTER_GAP, 3, MARGIN);
    $w = strlen($mask[0]);
    $h = H_PX;
    $ink = array_fill(0, $h, array_fill(0, $w, false));
    $edge = array_fill(0, $h, array_fill(0, $w, false));
    $colTop = array_fill(0, $w, -1);
    $colBot = array_fill(0, $w, -1);
    $colLetter = array_fill(0, $w, -1);
    foreach ($ranges as $li => [$x0, $x1]) {
        for ($x = $x0; $x < $x1; $x++) {
            $colLetter[$x] = $li;
        }
    }
    for ($y = 0; $y < count($mask); $y++) {
        for ($x = 0; $x < $w; $x++) {
            if ($mask[$y][$x] === '#') {
                $ink[$y + TOP_PAD][$x] = true;
                $colTop[$x] = $colTop[$x] < 0 ? $y + TOP_PAD : min($colTop[$x], $y + TOP_PAD);
                $colBot[$x] = max($colBot[$x], $y + TOP_PAD);
            }
        }
    }
    $at = static fn (int $x, int $y): bool => ($ink[$y][$x] ?? false);
    for ($y = TOP_PAD; $y < TOP_PAD + 13; $y++) {
        for ($x = 0; $x < $w; $x++) {
            if ($at($x, $y) && !($at($x - 1, $y) && $at($x + 1, $y) && $at($x, $y - 1) && $at($x, $y + 1))) {
                $edge[$y][$x] = true;
            }
        }
    }
    return $s = [$w, $mask, $ink, $edge, $colTop, $colBot, $colLetter];
}

// ================================================================ TIDE / COOLING

function clamp01(float $v): float
{
    return $v < 0.0 ? 0.0 : ($v > 1.0 ? 1.0 : $v);
}

/** Global cooling 0→1: glow dies, ripples freeze, resin sets. */
function coolOf(float $p): float
{
    return clamp01(($p - COOL0) / (COOL1 - COOL0));
}

/** Fractional meniscus row (pixel space) for one ink column at playback p. */
function surfaceOf(int $x, float $p, array $colTop, array $colBot, array $colLetter): float
{
    $li = $colLetter[$x];
    $prog = $li < 0 ? 1.0 : clamp01(($p - STARTS[$li]) / RISE_DUR);
    if ($prog <= 0.0) {
        return $colBot[$x] + 9.0;              // empty — far below the column
    }
    $cool = coolOf($p);
    $still = clamp01(($prog - 0.75) / 0.25);   // last quarter of the rise calms
    $amp = 1.30 * (1.0 - $cool * $cool) * (1.0 - 0.45 * $still);
    $top = $colTop[$x];
    $bot = $colBot[$x];
    return $bot - $prog * ($bot - $top) + $amp * sin(0.55 * $x + 22.0 * $p);
}

// ================================================================ PIXEL SHADING

/**
 * Colour of one pixel at playback p, or null = transparent.
 * Returns [r,g,b] ints. $depth is the half-block row (unused here; tc only).
 */
function pixelColour(int $x, int $y, float $p): ?array
{
    [, , $ink, $edge, $colTop, $colBot, $colLetter] = scene();
    $cool = coolOf($p);

    // ---- base molten pool (the shared tide), rows POOL_TOP..POOL_TOP+1
    if ($y >= POOL_TOP) {
        $front = MARGIN + (scene0() - 2 * MARGIN - 1) * clamp01($p / TIDE_DUR);
        $front += 1.4 * (1.0 - $cool) * sin($x * 0.5 + 22.0 * $p);
        if ($x > $front) {
            return null;
        }
        $isTop = $y === POOL_TOP;
        $base = lkHex($isTop ? CORE : DEEP);
        if ($isTop) {
            $wave = sin($x * 0.8 + 26.0 * $p);
            $base = lkMix($base, lkHex(HOTLINE), clamp01(($wave - 0.35) / 0.65) * (1.0 - $cool) * 0.8);
            $head = clamp01(1.0 - ($front - $x) / 3.0);
            $base = lkMix($base, lkHex(HOT), $head * (1.0 - $cool) * 0.9);
            $base = lkMix($base, lkHex(MID), $cool * 0.45);  // pool sets slightly darker on top
        }
        return $base;
    }
    if ($y < TOP_PAD || !$ink[$y][$x]) {
        return null;
    }

    // ---- ink column: liquid filled iff at-or-below the meniscus
    $surf = surfaceOf($x, $p, $colTop, $colBot, $colLetter);
    $isEdge = $edge[$y][$x];

    if ($y < $surf) {
        // empty dark glass (flask edge) — subtle rim glint at open tops
        $c = lkHex(GLASS);
        if (!$ink[$y - 1][$x] && $surf < $y + 20) {
            $c = lkHex(GLASS_HI);
        }
        $g = (lkNoise($x, $y, 21) - 0.5) * 0.20;
        return $g > 0 ? lkMix($c, [255, 255, 255], $g * 0.25) : lkScale($c, 1 + $g);
    }

    // --- filled: resin body — top-lit depth ramp, bright core, darker at edges
    $bot = $colBot[$x];
    $span = max(1.6, $bot - $surf);
    $d = clamp01(($y - $surf) / $span);
    $t = clamp01(0.62 * $d + ($isEdge ? 0.34 : 0.0) + (lkNoise($x, $y, 7) - 0.5) * 0.07);
    $cured = lkGradient([lkHex(CORE), lkHex(MID), lkHex(DEEP)], $t);
    $crestTop = $y === $colTop[$x] || !$ink[$y - 1][$x];
    if ($crestTop) {
        $cured = lkMix($cured, lkHex(HOT), 0.45);      // top-lit: bright crest along each stroke's upper edge
    }

    // --- molten variant: hot meniscus crest, flow lines, rising bubbles
    $molten = $cured;
    $crest = clamp01(1.0 - ($y - $surf) / 1.7) * (1.0 - $cool);
    $molten = lkMix($molten, lkHex(HOT), $crest * 0.85);
    $molten = $isEdge ? lkScale($molten, 0.90) : $molten;
    $flow = sin($y * 1.75 - $x * 0.85 + 26.0 * $p);
    $molten = lkMix($molten, lkHex(HOTLINE), clamp01(($flow - 0.55) / 0.45) * (1.0 - $cool) * 0.55);
    if (($y + (int) round($p * 34) + (int) round(lkNoise($x, 11, 3) * 7)) % 5 === 0
        && $d > 0.10 && $d < 0.92 && lkNoise($x, 17, 5) > 0.60 && (1.0 - $cool) > 0.12) {
        $molten = lkMix($molten, lkHex(BUB), 0.75);    // bubble glint
    }

    // --- melt → cured blend + specular sweep that freezes as a cured-resin stripe
    $c = lkMix($molten, $cured, $cool);
    $k = clamp01(($cool - SPEC0) / (1.0 - SPEC0));
    if ($k > 0.0) {
        $dband = abs($x + 0.75 * $y - SPEC_D0);
        $bandI = $dband < 1.4 ? 1.0 : ($dband < 2.6 ? 1.0 - ($dband - 1.4) / 1.2 : 0.0);
        if ($bandI > 0.0) {
            $c = lkMix($c, lkHex(SPEC), $k * $bandI * 0.92);
        }
    }
    return $c;
}

function scene0(): int
{
    return scene()[0];
}

// ================================================================ BUILD

/** Pixel grid rows of rgb|null → cell grid via the house half-block idiom. */
function cells(float $p): array
{
    $w = scene0();
    $px = [];
    for ($y = 0; $y < H_PX; $y++) {
        $row = [];
        for ($x = 0; $x < $w; $x++) {
            $c = pixelColour($x, $y, $p);
            $row[] = $c === null ? null : array_map('intval', $c);
        }
        $px[] = $row;
    }
    return lkHalfBlock($px);
}

// ================================================================ CLI

$write = in_array('--write', $argv, true);
$dir = __DIR__;

$static = lkEncode(cells(1.0), 'tc');
[$w, $h] = lkVerify($static, 'tc');
lkCheckDepth($static, 'tc', 'tc');

if (!$write) {
    echo $static;
    fwrite(STDERR, "[tc] {$w}x{$h} static ok\n");
}

$frames = [];
for ($f = 0; $f < FRAMES - 1; $f++) {
    $frames[] = lkEncode(cells($f / (FRAMES - 1)), 'tc');
}
$anim = lkAnim($frames, $static);
[$aw, $ah] = lkVerify(lkLastFrame($anim), 'tc/anim');
lkCheckDepth($anim, 'tc', 'tc/anim');

if ($write) {
    file_put_contents($dir . '/logo-' . SLUG . '-tc.ansi', $static);
    file_put_contents($dir . '/logo-' . SLUG . '-anim-tc.ansi', $anim);
    fwrite(STDERR, "[tc] {$w}x{$h} static ok — wrote logo-" . SLUG . "-tc.ansi (" . strlen($static) . "B)\n");
    fwrite(STDERR, "[tc] anim ok — wrote logo-" . SLUG . "-anim-tc.ansi (" . strlen($anim) . "B, " . count($frames) . "+1 frames)\n");
}
if ($aw !== $w || $ah !== $h) {
    exit("anim final frame geometry {$aw}x{$ah} != static {$w}x{$h}\n");
}
if ($w > 80 || $h > 14) {
    exit("bounds violated: {$w}x{$h}\n");
}
fwrite(STDERR, "verify: {$w}x{$h}, " . FRAMES . " frames total, tc confirmed\n");
