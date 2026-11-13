<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Ui;

/**
 * Motion opt-out. Animation (the loading-skeleton shimmer) is disabled when the
 * environment sets `NO_MOTION` or `PHLIX_NO_ANIMATION` to any non-empty value
 * other than `0`/`false`/`no`/`off`. When disabled the skeleton renders a static
 * (phase-0) placeholder and the App never arms the shimmer tick.
 */
final class Motion
{
    private const VARIABLES = ['NO_MOTION', 'PHLIX_NO_ANIMATION'];

    /** True when animation is allowed (neither opt-out variable is set). */
    public static function enabled(): bool
    {
        foreach (self::VARIABLES as $name) {
            $value = $_SERVER[$name] ?? getenv($name);
            if (!is_string($value)) {
                continue;
            }
            if (!in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return true;
    }
}
