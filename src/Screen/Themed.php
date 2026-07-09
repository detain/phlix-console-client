<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Ui\Theme;
use SugarCraft\Core\Model;

/**
 * A screen whose chrome can be tinted by the active {@see Theme}. Mirrors
 * {@see Breadcrumbed}: only the {@see \Phlix\Console\App} knows the chosen theme,
 * so it applies it to the top screen via {@see withTheme()} (clone-mutate, the
 * original unchanged) just before that screen renders — the screen then passes
 * its theme to {@see \Phlix\Console\Ui\Chrome::frame()}.
 *
 * Screens get the implementation for free by `use`-ing {@see ThemedScreen}; the
 * trait's Nocturne default means a screen never sees a missing theme and existing
 * renders stay identical.
 */
interface Themed extends Model
{
    /**
     * A copy that will render with $theme (clone-mutate; the original is
     * unchanged).
     */
    public function withTheme(Theme $theme): static;
}
