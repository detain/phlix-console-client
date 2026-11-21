<?php

declare(strict_types=1);

/**
 * generate-buffering-to-ready — concept #10 for the Phlix console client.
 *
 * Story: a buffering screen (braille spinner, amber progress bar, ticking
 * percentage) crawls to 99%, stalls with a few nervous frames (the joke),
 * flashes "READY" in a warm burst, then a white-hot edge sweeps the whole UI
 * away and the Phlix logo — play button + wordmark + amber rail line —
 * assembles behind it, settling into a clean static frame.
 *
 * Palette: dark UI chrome (#15171f panel on a soft #12141b page wash),
 * muted grey spinner/text, Phlix-amber fill ramp, white-hot flash on the
 * resolve edge. Half-block ▀/▄/▌ + background-fill ramps (house idioms).
 *
 * Usage:
 *   php generate-buffering-to-ready.php            preview static + stats to stdout
 *   php generate-buffering-to-ready.php --write    write the .ansi deliverables
 */

require __DIR__ . '/logo-kit.php';

// ================================================================ DESIGN DATA

const W = 48;                       // canvas columns (uniform every frame)
const H = 10;                       // canvas rows

/** 8-px-tall masks → 4 cell rows after half-blocking. */
const FONT = [
    'P' => ['####.', '#...#', '#...#', '####.', '#....', '#....', '#....', '#....'],
    'h' => ['#....', '#....', '#.##.', '##..#', '#...#', '#...#', '#...#', '#...#'],
    'l' => ['#', '#', '#', '#', '#', '#', '#', '#'],
    'i' => ['#', '.', '#', '#', '#', '#', '#', '#'],
    'x' => ['#...#', '#...#', '.#.#.', '..#..', '.#.#.', '#...#', '#...#', '#...#'],
];

const SPIN = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

// palette -------------------------------------------------------------------
const PAGE      = '#12141b';   // soft wash behind the final logo
const PANEL_BG  = '#15171f';
const BORDER    = '#2b2f3d';
const TITLE     = '#565b6b';
const TXT       = '#c6cbda';
const TXT_DIM   = '#8b90a0';   // spinner / labels
const TXT_FAINT = '#565b6b';
const TRACK     = '#232634';   // unfilled bar rail
const AMBER     = '#F5A623';   // Phlix brand amber
const AMBER_LO  = '#B4700F';
const AMBER_HI  = '#FFC95E';
const HEAD      = '#FFE9C2';   // bar head / flash ink
const HOT       = '#FFF2D6';   // resolve-edge white-hot
const FLASH_BG  = '#3a2a12';
const BTN_FRAME = '#9a7a35';
const BTN_IN    = '#1a1c26';
const WORDMARK  = ['#EF9A14', '#FFC24B', '#FFE2A8'];  // left → right ramp

// geometry ------------------------------------------------------------------
const PBX0 = 1;  const PBX1 = 46;                     // buffering panel frame
const SPIN_X = 3;  const SPIN_Y = 2;                  // spinner cell
const LBL_X = 5;                                      // "Buffering" label
const META_X = 18;                                    // title metadata (y=2)
const BAR_X0 = 3;  const BAR_X1 = 40;  const BAR_Y = 4;
const BAR_W = BAR_X1 - BAR_X0 + 1;
const PCT_X = 41;                                     // percent text (4 wide)
const BTN_X0 = 6;  const BTN_X1 = 14;  const BTN_Y0 = 2;  const BTN_Y1 = 8;
const TRI_L = 8;                                      // ▶ left edge (cols 8..12)
const WM_X0 = 17;                                     // wordmark left column
const WM_Y0 = 4;                                      // 4 cell rows: y4..y7
const UL_Y = 8;                                       // amber rail under word

// timeline (motion frames; lkAnim appends the static frame) ------------------
const F_BUF_END = 27;     // 0% → 99%
const F_STALL_END = 43;   // the 99% agony — nervous beats
const F_FLASH_END = 46;   // READY burst
const F_SWEEP_END = 65;   // bright edge sweeps UI, logo assembles behind
const FRAMES_MOTION = F_SWEEP_END + 1;

const DESCRIPTION = 'Phlix as a buffering screen that refuses to end: spinner + amber bar crawl to 99%, stall jitter, then a READY flash and a white-hot sweep that dissolves the whole UI into the Phlix wordmark with a play button.';
const TAGS = ['buffering', 'progress-bar', 'spinner', 'stall', 'flash', 'dissolve', 'play-button'];

// ================================================================ CELL HELPERS

function blank(): array
{
    return array_fill(0, H, array_fill(0, W, [' ', null, null]));
}

function set(array &$g, int $x, int $y, string $ch, ?array $fg, ?array $bg = null): void
{
    if ($x >= 0 && $x < W && $y >= 0 && $y < H) {
        $g[$y][$x] = [$ch, $fg, $bg];
    }
}

function bgbox(array &$g, int $x0, int $y0, int $x1, int $y1, ?array $bg): void
{
    for ($y = $y0; $y <= $y1; $y++) {
        for ($x = max(0, $x0); $x <= min(W - 1, $x1); $x++) {
            set($g, $x, $y, ' ', null, $bg);
        }
    }
}

/** Text drawn only at columns >= $edge (cells left of the sweep edge vanish). */
function say(array &$g, int $x, int $y, string $s, ?array $fg, ?array $bg = null, float $edge = -10): void
{
    foreach (mb_str_split($s) as $i => $ch) {
        if ($x + $i >= $edge) {
            set($g, $x + $i, $y, $ch, $fg, $bg);
        }
    }
}

// ================================================================ BUFFERING UI

/** All observable state of buffering frame $f (null = fully-resolved UI). */
function uiState(?float $f): array
{
    if ($f === null) {
        $f = (float) FRAMES_MOTION;
    }
    $flash = $f > F_STALL_END;
    $stall = $f > F_BUF_END && $f <= F_STALL_END;

    if ($f <= F_BUF_END) {
        $x = $f / F_BUF_END;
        $pct = (int) round(99 * (1 - (1 - $x) ** 2.2));      // fast start, crawl at the end
        $spin = SPIN[(int) floor($f * 0.9) % 10];
        $dots = 1 + ((int) floor($f / 3) % 3);
        $retry = null;
    } elseif (!$flash) {                                      // the 99% stall
        $t = (int) $f - F_BUF_END;                            // 1..16
        $seq = ['⠼', '⠼', '⠴', '⠴', '⠼', '⠸', '⠸', '⠼', '⠴', '⠼', '⠼', '⠇', '⠏', '⠏', '⠇', '⠼'];
        $spin = $seq[($t - 1) % 16];                          // slows, reverses, twitches
        $pct = ($t === 10) ? 98 : 99;                         // one blink of pure dread
        $dots = 1 + ((int) floor($t / 4) % 3);
        $retry = $t < 8 ? null : (int) max(2, 4 - intdiv($t - 8, 2));
    } else {
        $pct = 100;
        $spin = '▸';
        $dots = 0;
        $retry = null;
    }

    return [
        'flash' => $flash,
        'pct' => $pct,
        'fill' => $flash ? 1.0 : min(1.0, max(0.0, $pct / 100 + ($stall && (int) $f % 5 === 0 ? -0.012 : 0))),
        'spin' => $spin,
        'dots' => $dots,
        'retry' => $retry,
    ];
}

/**
 * Draw the buffering panel. Columns left of $edge are already resolved to
 * logo territory, so every cell gates on x >= $edge.
 */
function drawUI(array &$g, array $st, float $edge = -10): void
{
    $fl = $st['flash'];
    $bg  = lkHex($fl ? FLASH_BG : PANEL_BG);
    $brd = lkHex($fl ? AMBER : BORDER);
    $tx  = lkHex($fl ? HEAD : TXT);
    $dim = lkHex($fl ? AMBER_HI : TXT_DIM);
    $fnt = lkHex($fl ? AMBER : TXT_FAINT);

    bgbox($g, (int) round($edge), PBX0 + 1, PBX1 - 1, H - 2, $bg);   // interior wash

    // frame -------------------------------------------------------------------
    if (PBX0 >= $edge) { set($g, PBX0, 0, '╭', $brd); }
    if (PBX1 >= $edge) { set($g, PBX1, 0, '╮', $brd); }
    if (PBX0 >= $edge) { set($g, PBX0, H - 1, '╰', $brd); }
    if (PBX1 >= $edge) { set($g, PBX1, H - 1, '╯', $brd); }
    for ($x = PBX0 + 1; $x <= PBX1 - 1; $x++) {
        if ($x >= $edge) {
            set($g, $x, 0, '─', $brd);
            set($g, $x, H - 1, '─', $brd);
        }
    }
    for ($y = 1; $y <= H - 2; $y++) {
        if (PBX0 >= $edge) { set($g, PBX0, $y, '│', $brd); }
        if (PBX1 >= $edge) { set($g, PBX1, $y, '│', $brd); }
    }
    say($g, 16, 0, ' phlix console ', lkHex($fl ? HEAD : TITLE), null, $edge);

    // title + spinner rows ------------------------------------------------------
    say($g, 3, 1, 'Now playing', $fnt, null, $edge);
    say($g, 33, 1, 'phlix ▸ tv', $fnt, null, $edge);
    if (SPIN_X >= $edge) { set($g, SPIN_X, SPIN_Y, $st['spin'], $dim); }
    if (LBL_X >= $edge) {
        say($g, LBL_X, SPIN_Y, $fl ? 'READY' : 'Buffering' . str_repeat('.', max(1, $st['dots'])), $tx, null, $edge);
    }
    say($g, META_X, SPIN_Y, 'Hellsing OVA 1 · Ep 3', $dim, null, $edge);

    // progress bar ----------------------------------------------------------------
    $filled = (int) round($st['fill'] * BAR_W);
    for ($i = 0; $i < BAR_W; $i++) {
        $x = BAR_X0 + $i;
        if ($x < $edge || $x > BAR_X1) { continue; }
        if ($i < $filled) {
            set($g, $x, BAR_Y, '▀', lkGradient([AMBER_LO, AMBER, $fl ? HEAD : AMBER_HI], $i / (BAR_W - 1)));
        } elseif ($i === $filled && $st['fill'] * BAR_W - $filled >= 0.4) {
            set($g, $x, BAR_Y, '▌', lkHex(HEAD));
        } else {
            set($g, $x, BAR_Y, '▀', lkHex(TRACK));
        }
    }
    say($g, PCT_X, BAR_Y, sprintf('%4d%%', $st['pct']), $fl ? lkHex(HEAD) : $tx, null, $edge);

    // detail rows --------------------------------------------------------------------
    say($g, 3, 5, '1080p · h264 · AC-3 · 24 fps', $fnt, null, $edge);
    say($g, 3, 6, $fl ? 'stream locked · play ahead' : 'buffer ahead 12s', $fnt, null, $edge);
    if (!$fl && $st['retry'] !== null) { say($g, 32, 6, "retry in {$st['retry']}s", $dim, null, $edge); }
    say($g, 3, H - 2, '0:23 / 24:58', $fnt, null, $edge);
    say($g, 31, H - 2, 'auto quality', $fnt, null, $edge);
}

// ================================================================ FINAL LOGO

/** @return array{0:int,1:int} wordmark [xFirst, xLast] on the canvas */
function wmRange(): array
{
    static $r = null;
    return $r ??= (function (): array {
        [$mask] = lkLayout(FONT, 'Phlix', letterGap: 1, wordGap: 1, margin: 0);
        return [WM_X0, WM_X0 + strlen($mask[0]) - 1];
    })();
}

/** Wordmark pixel colour: canvas column x, pixel row py 0..7. */
function wmColour(int $x, int $py): array
{
    [$a, $b] = wmRange();
    $base = lkGradient(WORDMARK, ($x - $a) / max(1, $b - $a));
    return lkShade($base, 0.26 - 0.52 * $py / 7);
}

/** The logo. Cells appear only at columns x < $limitByRow[y] (sweep reveal). */
function drawLogo(array &$g, array $limitByRow): void
{
    for ($y = 0; $y < H; $y++) {
        for ($x = 4; $x <= 39; $x++) {
            if ($x < $limitByRow[$y]) {
                set($g, $x, $y, ' ', null, lkHex(PAGE));
            }
        }
    }
    // play button — Block-Elements frame (▛▀▜▌▙▄▟) reads in terminal and raster
    $fr = lkHex(BTN_FRAME);
    if (BTN_X0 < $limitByRow[BTN_Y0]) { set($g, BTN_X0, BTN_Y0, '▛', $fr); }
    if (BTN_X1 < $limitByRow[BTN_Y0]) { set($g, BTN_X1, BTN_Y0, '▜', $fr); }
    if (BTN_X0 < $limitByRow[BTN_Y1]) { set($g, BTN_X0, BTN_Y1, '▙', $fr); }
    if (BTN_X1 < $limitByRow[BTN_Y1]) { set($g, BTN_X1, BTN_Y1, '▟', $fr); }
    for ($x = BTN_X0 + 1; $x <= BTN_X1 - 1; $x++) {
        if ($x < $limitByRow[BTN_Y0]) { set($g, $x, BTN_Y0, '▀', $fr); }
        if ($x < $limitByRow[BTN_Y1]) { set($g, $x, BTN_Y1, '▄', $fr); }
    }
    for ($y = BTN_Y0 + 1; $y <= BTN_Y1 - 1; $y++) {
        if (BTN_X0 < $limitByRow[$y]) { set($g, BTN_X0, $y, '▌', $fr); }
        if (BTN_X1 < $limitByRow[$y]) { set($g, BTN_X1, $y, '▐', $fr); }
        for ($x = BTN_X0 + 1; $x <= BTN_X1 - 1; $x++) {
            if ($x < $limitByRow[$y]) { set($g, $x, $y, ' ', null, lkHex(BTN_IN)); }
        }
    }
    $widths = [1, 3, 5, 3, 1];                                    // ▶: vertical left edge, apex right
    $tcols  = [AMBER_LO, AMBER, AMBER_HI, AMBER, AMBER_LO];
    foreach ($widths as $i => $wd) {
        $y = BTN_Y0 + 1 + $i;
        for ($d = 0; $d < $wd; $d++) {
            $x = TRI_L + $d;
            if ($x < $limitByRow[$y]) { set($g, $x, $y, '█', lkHex($tcols[$i])); }
        }
    }
    // wordmark ------------------------------------------------------------------------
    [$mask] = lkLayout(FONT, 'Phlix', letterGap: 1, wordGap: 1, margin: 0);
    $mw = strlen($mask[0]);
    for ($col = 0; $col < $mw; $col++) {
        $x = WM_X0 + $col;
        for ($k = 0; $k < 4; $k++) {
            $y = WM_Y0 + $k;
            if ($x >= $limitByRow[$y]) { continue; }
            $t = $mask[2 * $k][$col] === '#';
            $b = $mask[2 * $k + 1][$col] === '#';
            if (!$t && !$b) { continue; }
            $ct = $t ? wmColour($x, 2 * $k) : null;
            $cb = $b ? wmColour($x, 2 * $k + 1) : null;
            $g[$y][$x] = match (true) {
                $t && $b && $ct === $cb => ['█', $ct, null],
                $t && $b => ['▀', $ct, $cb],
                $t => ['▀', $ct, null],
                default => ['▄', $cb, null],
            };
        }
    }
    // amber rail line (the progress bar's residue) ------------------------------------------
    [$a, $b] = wmRange();
    for ($x = $a; $x <= $b; $x++) {
        if ($x < $limitByRow[UL_Y]) {
            set($g, $x, UL_Y, '▀', lkGradient([AMBER_LO, AMBER, AMBER_HI], ($x - $a) / max(1, $b - $a)));
        }
    }
}

// ================================================================ FRAME BUILD

function buildFrame(?float $f): array
{
    if ($f === null) {
        $g = blank();
        drawLogo($g, array_fill(0, H, W + 40));
        return $g;
    }
    $g = blank();
    if ($f <= F_FLASH_END) {
        drawUI($g, uiState($f));
        return $g;
    }
    // sweep: white-hot edge eats the UI left→right; logo appears behind it
    $p = ($f - F_FLASH_END) / (F_SWEEP_END - F_FLASH_END);
    $e = -2 + $p * (W + 8);
    $limitByRow = [];
    for ($y = 0; $y < H; $y++) {
        $limitByRow[$y] = $e - 2.5 * lkNoise($y, 7);
    }
    drawUI($g, uiState(null), min($limitByRow) - 2);
    drawLogo($g, array_map(static fn (float $v): float => $v - 3, $limitByRow));
    for ($y = 0; $y < H; $y++) {
        $ex = (int) floor($limitByRow[$y]);
        if ($ex < W) { set($g, $ex + 1, $y, ' ', null, lkHex(PAGE)); }
        if ($ex >= 0 && $ex < W) { set($g, $ex, $y, '█', lkHex(HOT)); }
        if ($ex - 1 >= 0 && $ex - 1 < W) { set($g, $ex - 1, $y, '█', lkHex(AMBER_HI)); }
    }
    return $g;
}

// ================================================================ CLI

$write = in_array('--write', $argv, true);

$static = lkEncode(buildFrame(null), 'tc');
[$w, $h] = lkVerify($static, 'static');
lkCheckDepth($static, 'tc', 'static');

$frames = [];
for ($f = 0; $f < FRAMES_MOTION; $f++) {
    [$fw, $fh] = lkVerify($frames[] = lkEncode(buildFrame((float) $f), 'tc'), "frame $f");
    if ([$fw, $fh] !== [$w, $h]) {
        exit("frame $f is {$fw}x{$fh}, static is {$w}x{$h}\n");
    }
}
$anim = lkAnim($frames, $static);
lkCheckDepth($anim, 'tc', 'anim');

echo $static;
fwrite(STDERR, sprintf(
    "[tc] %dx%d · %d motion frames + final · %s bytes (anim)\n",
    $w, $h, count($frames), number_format(strlen($anim))
));
fwrite(STDERR, "story: buffer→99 stall→READY flash→sweep-resolve into Phlix · " . DESCRIPTION . "\n");

if ($write) {
    file_put_contents(__DIR__ . '/logo-buffering-to-ready-tc.ansi', $static);
    file_put_contents(__DIR__ . '/logo-buffering-to-ready-anim-tc.ansi', $anim);
    fwrite(STDERR, "wrote logo-buffering-to-ready-tc.ansi + logo-buffering-to-ready-anim-tc.ansi\n");
}
