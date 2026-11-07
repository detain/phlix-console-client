<?php

/**
 * Test bootstrap — the suite's deterministic environment baseline.
 *
 * Loads the Composer autoloader (the config's former bootstrap target,
 * verbatim) and then pins a colour-capable TERM when the invoking
 * environment never advertised a terminal type at all. GitHub Actions
 * steps run with TERM unset; the colour-EMISSION tests (ThemeTest,
 * ThemePaletteSoleSourceTest, Chrome/Skeleton/Metrics/NowPlayingBar/
 * App/Settings/Themed-screen) silently depended on whatever TERM the
 * runner or a developer shell happened to export — a dependence made
 * load-bearing by CU-059, where Theme::colorSuppressed() began honouring
 * every basic-TERM signal (unset, empty, 'dumb', 'unknown'), exactly as
 * the ncurses convention and the manual now document.
 *
 * The suite's env doctrine is unchanged: suppression tests setEnv() the
 * value they are about and restore it in tearDown; everything else sees
 * this fixed baseline — an ordinary colour-capable terminal — identically
 * on every machine [review console__a11y #6 / CU-059 forward-fix].
 *
 * An exported TERM (of any value, including 'dumb') is left alone, so a
 * developer can still reproduce the suppression arms locally on purpose;
 * NO_COLOR is never touched.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$term = getenv('TERM');

if ($term === false || $term === '') {
    putenv('TERM=xterm-256color');
}
