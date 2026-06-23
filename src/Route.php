<?php

declare(strict_types=1);

namespace Phlix\Console;

/**
 * The top-level screen the {@see App} is showing. The App hand-rolls routing
 * (candy-core's ScreenStack discards a nested screen's updated model, so it
 * can't host a stateful form) — this enum names the current destination.
 */
enum Route
{
    case ServerSetup;
    case Loading;
    case Login;
    case Browse;
    case Library;
    case Detail;
    case Player;
    case Search;
    case Music;
    case Album;
}
