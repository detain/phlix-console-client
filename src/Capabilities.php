<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console;

use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Palette\Probe;

/**
 * Detects what the current terminal can render — the best image protocol
 * (sixel / kitty / iTerm2 / half-block) and the color depth — and turns it into
 * a human-readable report.
 *
 * Drives `phlix doctor` and the default render-mode choice at startup. Uses
 * {@see Mosaic::auto()} which never throws (it falls back to half-block on a
 * terminal with no graphics protocol).
 */
final class Capabilities
{
    /**
     * @return array{
     *   protocol: string, sixel: bool, kitty: bool, iterm2: bool,
     *   halfblock: bool, colorProfile: string, term: string, tmux: bool
     * }
     */
    public function detect(): array
    {
        $mosaic = Mosaic::auto();
        $cap = $mosaic->capability();

        return [
            'protocol'     => $mosaic->protocol(),
            'sixel'        => $cap->sixel,
            'kitty'        => $cap->kitty,
            'iterm2'       => $cap->iterm2,
            'halfblock'    => $cap->halfblock,
            'colorProfile' => Probe::colorProfile()->name,
            'term'         => (string) (getenv('TERM') ?: ''),
            'tmux'         => $cap->inTmux,
        ];
    }

    public function report(): string
    {
        $d = $this->detect();
        $yn = static fn (bool $b): string => $b ? 'yes' : 'no';

        return implode("\n", [
            'Phlix console — terminal capabilities',
            str_repeat('-', 38),
            sprintf('  Render protocol : %s', $d['protocol']),
            sprintf('  Color profile   : %s', $d['colorProfile']),
            sprintf('  TERM            : %s', $d['term'] !== '' ? $d['term'] : '(unset)'),
            sprintf('  Inside tmux     : %s', $yn($d['tmux'])),
            '  Supported image protocols:',
            sprintf('    sixel     : %s', $yn($d['sixel'])),
            sprintf('    kitty     : %s', $yn($d['kitty'])),
            sprintf('    iterm2    : %s', $yn($d['iterm2'])),
            sprintf('    halfblock : %s', $yn($d['halfblock'])),
        ]);
    }
}
