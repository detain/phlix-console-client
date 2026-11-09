<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console;

use SugarCraft\Image\Mosaic;
use SugarCraft\ColorProfile\Probe;

/**
 * Detects what the current terminal can render — the best image protocol
 * (sixel / kitty / iTerm2 / half-block) and the color depth — and turns it into
 * a human-readable report.
 *
 * Drives `phlix doctor` only [review console__tuispec i4]: this is a
 * capability survey, not the app's render-mode choice. The startup default is
 * NOT picked here — {@see \Phlix\Console\Media\MosaicFactory} maps null/auto
 * straight to the universal half-block cell renderer without probing (the
 * live DA1/XTWINOPS probe was RETIRED from every default path, CONSOLIDATED
 * M17: it misdetects on tmux/generic hosts and — on a real tty — writes query
 * bytes and buries the user under malformed lines).
 *
 * The probe lives on behind one explicit opt-in: {@see detect()} (and
 * `phlix doctor --probe`). The default {@see detectFromEnv()} reads ONLY
 * environment variables — same inference table the vendor's retired probe
 * consults first (SugarCraft\Image\Detect::probeEnv), mirrored here because
 * that entry point is private — and writes nothing to the terminal, flips no
 * tty modes, and blocks on no replies [review console__tuispec #1 / CU-053].
 * {@see report()} always labels which stage produced the numbers.
 */
final class Capabilities
{
    /**
     * The live-probe survey: consults the env table AND (on an interactive
     * tty) issues DA1/XTWINOPS queries with the tty briefly in non-canonical
     * mode. NEVER call this on a default path — it exists for the explicit
     * `phlix doctor --probe` opt-in only.
     *
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

    /**
     * The quiet survey: environment variables only — no terminal writes, no
     * tty mode changes, no query/reply waits. The rule set mirrors the
     * vendor's env precedence (Kitty → iTerm2 → Sixel → unknown) so the
     * snapshot agrees with what the retired probe would have concluded from
     * env alone; the half-block fallback is always available.
     *
     * @return array{
     *   protocol: string, sixel: bool, kitty: bool, iterm2: bool,
     *   halfblock: bool, colorProfile: string, term: string, tmux: bool
     * }
     */
    public function detectFromEnv(): array
    {
        $inTmux = getenv('TMUX') !== false;
        $term = (string) getenv('TERM');

        // Kitty block (vendor order: definite env hit skips every query).
        $kitty = getenv('KITTY_WINDOW_ID') !== false
            || getenv('TERM_PROGRAM') === 'WezTerm'
            || getenv('TERM_PROGRAM') === 'ghostty'
            || preg_match('/xterm-kitty/i', $term) === 1;

        // iTerm2 block (mintty speaks OSC 1337 too; WezTerm stays Kitty-family).
        $termProgram = (string) getenv('TERM_PROGRAM');
        $iterm2 = !$kitty && ($termProgram === 'iTerm.app'
            || $termProgram === 'iTerm2'
            || $termProgram === 'mintty'
            || (string) getenv('LC_TERMINAL') === 'iTerm2');

        // Sixel block: strong $TERM hints, or xterm carrying XTERM_VERSION.
        $sixel = !$kitty && !$iterm2 && (
            preg_match('/^(mlterm(-256color)?|foot(-extra|-direct)?)$/i', $term) === 1
            || ((string) getenv('XTERM_VERSION') !== '' && preg_match('/^xterm(-256color)?$/i', $term) === 1)
        );

        return [
            'protocol'     => $kitty ? 'kitty' : ($iterm2 ? 'iterm2' : ($sixel ? 'sixel' : 'halfblock')),
            'sixel'        => $sixel,
            'kitty'        => $kitty,
            'iterm2'       => $iterm2,
            // The half-block cell renderer never fails, so it is the universal
            // fallback the vendor reports as always-true on every Capability.
            'halfblock'    => true,
            'colorProfile' => Probe::colorProfile()->name,
            'term'         => $term,
            'tmux'         => $inTmux,
        ];
    }

    /**
     * The doctor report. Default = the quiet env snapshot; `--probe` opts
     * into the live survey. The source line states which one ran so the
     * numbers are never mistaken for a queried answer [CU-053].
     */
    public function report(bool $liveProbe = false): string
    {
        $d = $liveProbe ? $this->detect() : $this->detectFromEnv();
        $yn = static fn (bool $b): string => $b ? 'yes' : 'no';

        return implode("\n", [
            'Phlix console — terminal capabilities',
            str_repeat('-', 38),
            sprintf('  Render protocol : %s', $d['protocol']),
            sprintf('  Protocol source : %s', $liveProbe ? 'live DA1/XTWINOPS query' : 'environment (no terminal was queried)'),
            sprintf('  Color profile   : %s', $d['colorProfile']),
            sprintf('  TERM            : %s', $d['term'] !== '' ? $d['term'] : '(unset)'),
            sprintf('  Inside tmux     : %s', $yn($d['tmux'])),
            '  Supported image protocols:',
            sprintf('    sixel     : %s', $yn($d['sixel'])),
            sprintf('    kitty     : %s', $yn($d['kitty'])),
            sprintf('    iterm2    : %s', $yn($d['iterm2'])),
            sprintf('    halfblock : %s', $yn($d['halfblock'])),
            '',
            '  Capability survey only — `phlix run`/`phlix poster` render with',
            '  the universal half-block cell mode unless you pass --mode.',
            '  Pass --probe to run the live terminal query (it writes escape',
            '  sequences to this terminal; env-only inference misdetects inside',
            '  tmux and on generic terminals).',
        ]);
    }
}
