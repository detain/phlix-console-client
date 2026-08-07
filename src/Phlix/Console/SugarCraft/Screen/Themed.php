<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\SugarCraft\Screen;

/**
 * Interface for screens that support theming.
 */
interface Themed
{
    /**
     * Returns the theme name or null for default theme.
     */
    public function theme(): ?string;
}