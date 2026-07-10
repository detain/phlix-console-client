<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
    case Cast;
    case Search;
    case Settings;
    case Stats;
    case Music;
    case Album;
    case Books;
    case BookDetail;
    case Audiobooks;
    case AudiobookDetail;
    case Photos;
    case PhotoAlbum;
    case PhotoViewer;
    case Admin;
    case AdminDashboard;
    case AdminUsers;
    case AdminUserProfiles;
    case AdminPlugins;
    case AdminPluginDetail;
    case AdminPluginCatalog;
    case AdminLogs;
    case AdminBackup;
    case AdminSettings;
    case AdminLibraries;
    case AdminDlna;
    case AdminRemote;
    case AdminLiveTv;
    case Recommendations;
    case WatchHistory;
}
