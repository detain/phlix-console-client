<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * The Settings form was submitted with a (validated) theme name and
 * photo-slideshow interval. The App persists them, applies the theme LIVE, and
 * pops back to the previous screen.
 */
final readonly class SettingsSavedMsg implements Msg
{
    public function __construct(
        public string $themeName,
        public int $slideshowInterval,
    ) {
    }
}
