<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Ui;

/**
 * The house byte formatter: a byte count rendered as a human-readable binary
 * (1024-step) size, one decimal above bytes. [CU-021: review console__uxdesign
 * #9] StatsScreen used to print raw integers for storage Used/Total — the admin
 * screens had already established the readable form, each with its own private
 * copy (AdminDashboardScreen::humanBytes, AdminBackupScreen::humanBytes,
 * AdminFilesystemScreen::formatSize). This is the public single source new
 * renderers use; folding the three private copies onto it is a named follow-up
 * (it rotates their pinned-view tests and stays out of this cure's scope).
 */
final class Bytes
{
    /**
     * Humanize a byte count to a KiB/MiB/GiB/… string (binary 1024 steps),
     * rounded to one decimal above bytes. A negative count clamps to "0 B".
     *
     * The algorithm is the admin dashboard's verbatim, so a size rendered here
     * and the same size rendered on the dashboard print identically.
     */
    public static function human(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        $size = (float) $bytes;
        $unit = 0;
        while ($size >= 1024.0 && $unit < count($units) - 1) {
            $size /= 1024.0;
            ++$unit;
        }

        return $unit === 0
            ? $bytes . ' B'
            : number_format($size, 1) . ' ' . $units[$unit];
    }
}
