<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

/**
 * The five tabbed sections of {@see AdminLiveTvScreen}, in display (tab) order.
 * The backing string value keys the screen's per-section loading / error /
 * selection maps; {@see label()} is the tab-bar caption.
 */
enum LiveTvSection: string
{
    case Tuners = 'tuners';
    case Channels = 'channels';
    case Guide = 'guide';
    case Recordings = 'recordings';
    case Rules = 'rules';

    /** The human tab-bar caption. */
    public function label(): string
    {
        return match ($this) {
            self::Tuners => 'Tuners',
            self::Channels => 'Channels',
            self::Guide => 'Guide',
            self::Recordings => 'Recordings',
            self::Rules => 'Series Rules',
        };
    }
}
