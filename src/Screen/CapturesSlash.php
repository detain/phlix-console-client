<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

/**
 * Marker for screens that handle the `/` key themselves, so the App does NOT
 * hijack it to open the global search screen. Implemented by screens that use
 * `/` for their own filter/search ({@see LibraryScreen}), or that must not be
 * interrupted by a search overlay ({@see PlayerScreen}, where pushing a screen
 * over live playback would orphan its ffmpeg). Screens without this marker let
 * `/` open global search.
 */
interface CapturesSlash
{
}
