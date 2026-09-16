<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Ui;

/**
 * The client's single playback-clock formatter: "m:ss", or "h:mm:ss" once
 * the clock passes an hour.
 *
 * Six screens and sessions rendered this exact shape with copy-pasted
 * decompositions; each caller now converts its own input to WHOLE SECONDS
 * with its documented rounding (round for float playheads, floor for chapter
 * starts, intdiv for millisecond clocks) and delegates the decomposition
 * here. Negatives clamp to 0 — no caller may render a time below zero.
 */
final class Clock
{
    /** Whole seconds → "m:ss" (or "h:mm:ss" past an hour); negatives clamp to 0. */
    public static function format(int $seconds): string
    {
        $s = max(0, $seconds);
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        $sec = $s % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $sec) : sprintf('%d:%02d', $m, $sec);
    }
}
