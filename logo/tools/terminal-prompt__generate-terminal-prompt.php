<?php

declare(strict_types=1);

/**
 * generate-terminal-prompt — phlix-console-client animated truecolor ANSI logo
 * (concept #20: "Terminal Prompt").
 *
 * Story, in beat order (30 frames × 60 ms = 1.8 s, repeat-launch snappy):
 *   (a) an amber `$` prompt types `phlix` char-by-char, block cursor riding;
 *   (b) Enter: the line executes and the "Phlix" banner prints top-down,
 *       one half-block row per frame, amber truecolor gradient + drop shadow;
 *   (c) a green `✓` status line reveals in chunks, then a fresh prompt cursor
 *       blinks — the static final frame holds the cursor lit so it is
 *       representative of the live blink.
 *
 * House pipeline (tools copied unchanged into this dir):
 *   mask font → lkLayout → per-pixel gradient → lkHalfBlock → cell grid
 *   → lkEncode('tc') → lkVerify per frame → lkAnim().
 *
 * Usage:
 *   php generate-terminal-prompt.php
 *   php logo-play.php logo-terminal-prompt-anim-tc.ansi 60
 *   php logo-preview.php logo-terminal-prompt-tc.ansi logo-terminal-prompt-tc.png
 */

require __DIR__ . '/logo-kit.php';

// ================================================================ DESIGN DATA

const SLUG = 'terminal-prompt';
const W = 58;                    // grid columns (≤ 80)
const H = 11;                    // grid rows    (≤ 14)
const FRAME_MS = 60;
const DYNAMIC = 29;              // animation frames f0..f28; static = f29
const TYPED_ROW = 0;
const BANNER_ROW = 2;            // first terminal row of the banner (6 rows)
const STATUS_ROW = 9;
const PROMPT_ROW = 10;

const C_PROMPT = [255, 176, 0];  // #FFB000 amber
const C_WORD   = [240, 246, 252];
const C_CURSOR = [255, 201, 77]; // bright amber block cursor
const C_CHECK  = [63, 185, 80];  // #3FB950 green
const C_GREY   = [139, 148, 158];
const C_GREYHI = [201, 209, 217];
const C_SHADOW = [74, 47, 6];

/** vertical amber light → base, blended with a horizontal gold tint */
const GRADIENT = ['#FFE08A', '#FFC93C', '#FFB000', '#F08A00', '#D97F00'];
const GOLD_RIGHT = '#FFDC7A';

/**
 * Pixel mask font, 11 rows tall: capital P + x-height lowercase (baseline r10,
 * x-height starts r4). '#' = ink. Half-blocked with the shadow row = 6 rows.
 */
const FONT = [
    'P' => [
        '.####.', '######', '##...#', '##...#', '######', '#####.',
        '##....', '##....', '##....', '##....', '##....',
    ],
    'h' => [
        '##....', '##....', '##....', '##....', '##.###', '#####.',
        '##..##', '##..##', '##..##', '##..##', '##..##',
    ],
    'l' => [
        '..##..', '.####.', '..##..', '..##..', '..##..', '..##..',
        '..##..', '..##..', '..##..', '..##..', '..####',
    ],
    'i' => [
        '.##..', '.##..', '.....', '.##..', '.##..', '.##..',
        '.##..', '.##..', '.##..', '.##..', '.##..',
    ],
    'x' => [
        '......', '......', '......', '......', '##..##', '##..##',
        '.#..#.', '..##..', '.#..#.', '##..##', '##..##',
    ],
];

const STATUS_A = 'connected to phlix server';
const STATUS_B = ' — ';
const STATUS_C = '4 libraries, 12,841 items';

// ================================================================ BANNER

/** @return list<list<array{0:string,1:?array,2:?array}>> banner cell rows */
function bannerCells(): array
{
    static $cells = null;
    if ($cells !== null) {
        return $cells;
    }
    [$mask] = lkLayout(FONT, 'Phlix', letterGap: 1, wordGap: 1, margin: 0);
    $mh = count($mask);
    $mw = strlen($mask[0]);
    $on = static fn (int $x, int $y): bool => ($mask[$y][$x] ?? '.') === '#';
    $px = array_fill(0, $mh + 1, array_fill(0, $mw + 1, null));   // +1 shadow row
    for ($y = 0; $y <= $mh; $y++) {
        for ($x = 0; $x <= $mw; $x++) {
            if ($on($x, $y)) {
                $c = lkGradient(GRADIENT, $y / ($mh - 1));
                $c = lkMix($c, lkHex(GOLD_RIGHT), 0.16 * $x / $mw); // warm toward the right
                if ($y === 0) {
                    $c = lkShade($c, 0.18);                        // lit rim
                }
                $px[$y][$x] = $c;
            } elseif ($on($x - 1, $y - 1)) {
                $px[$y][$x] = C_SHADOW;                            // drop shadow
            }
        }
    }
    return $cells = lkHalfBlock($px);
}

// ================================================================ FRAME STATE

/** banner rows visible at frame f: none before Enter, one row/frame f8..f13 */
function bannerRows(int $f): int
{
    if ($f < 8) {
        return 0;
    }
    return min(6, $f - 7);
}

/** typed word length f0..f6 → 0,0,1..5; frozen afterwards */
function typedLen(int $f): int
{
    return min(5, max(0, $f - 1));
}

/** block-cursor blink: rides the typing, flashes off at Enter, then toggles;
 *  static frame (f = DYNAMIC = 29) lands cursor-lit after off/on pairs */
function cursorOn(int $f): bool
{
    if ($f <= 6) {
        return true;
    }
    if ($f === 7) {
        return false;      // Enter
    }
    if ($f === 8) {
        return true;       // fresh prompt appears
    }
    return $f % 2 === 1;
}

function buildFrame(int $f): array
{
    $cells = lkBlankCells(W, H);

    // (b) banner prints row by row, flush left
    $banner = bannerCells();
    for ($r = 0, $k = bannerRows($f); $r < $k; $r++) {
        foreach ($banner[$r] as $c => [$g, $fg, $bg]) {
            if ($g !== ' ') {
                $cells[BANNER_ROW + $r][$c] = [$g, $fg, $bg];
            }
        }
    }

    // (a) the command line (stays on screen after Enter, cursor leaves it)
    lkPut($cells, 0, TYPED_ROW, '$', C_PROMPT);
    $n = typedLen($f);
    if ($n > 0) {
        lkText($cells, 2, TYPED_ROW, substr('phlix', 0, $n), C_WORD);
    }
    if ($f <= 6 && cursorOn($f)) {
        lkPut($cells, $f === 0 ? 1 : 2 + $n, TYPED_ROW, '█', C_CURSOR);
    }

    // (c) status line reveals: check, then grey text chunks, then counts
    if ($f >= 16) {
        lkPut($cells, 0, STATUS_ROW, '✓', C_CHECK);
        $x = 2;
        if ($f >= 17) {
            lkText($cells, $x, STATUS_ROW, STATUS_A, C_GREY);
            $x += mb_strlen(STATUS_A);
            lkText($cells, $x, STATUS_ROW, STATUS_B, C_GREY);
            $x += mb_strlen(STATUS_B);
        }
        if ($f >= 18) {
            lkText($cells, $x, STATUS_ROW, STATUS_C, C_GREYHI);
        }
    }

    // fresh prompt with blinking cursor at the bottom
    if ($f >= 8) {
        lkPut($cells, 0, PROMPT_ROW, '$', C_PROMPT);
        if (cursorOn($f)) {
            lkPut($cells, 2, PROMPT_ROW, '█', C_CURSOR);
        }
    }

    return $cells;
}

// ================================================================ BUILD + WRITE

$frames = [];
for ($f = 0; $f < DYNAMIC; $f++) {
    $frames[] = lkEncode(buildFrame($f), 'tc');
}
$static = lkEncode(buildFrame(DYNAMIC), 'tc');

// gate: every frame is uniform W×H with \e[0m line endings
foreach ($frames as $i => $enc) {
    [$w, $h] = lkVerify($enc, "frame $i");
    if ($w !== W || $h !== H) {
        throw new RuntimeException("frame $i is {$w}x{$h}, expected " . W . 'x' . H);
    }
}
[$sw, $sh] = lkVerify($static, 'static');
if ($sw !== W || $sh !== H) {
    throw new RuntimeException("static is {$sw}x{$sh}, expected " . W . 'x' . H);
}

$anim = lkAnim($frames, $static);
lkCheckDepth($anim, 'tc', SLUG);

file_put_contents(__DIR__ . '/logo-' . SLUG . '-anim-tc.ansi', $anim);
file_put_contents(__DIR__ . '/logo-' . SLUG . '-tc.ansi', $static);

$totalFrames = count($frames) + 1;
fwrite(STDERR, sprintf(
    "[%s] %dx%d, %d frames, %.1fs at %dms — wrote logo-%s-anim-tc.ansi + logo-%s-tc.ansi\n",
    SLUG, $sw, $sh, $totalFrames, $totalFrames * FRAME_MS / 1000, FRAME_MS, SLUG, SLUG,
));
