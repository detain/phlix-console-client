<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * The trickplay fetch failed silently — the player carries on without scrub
 * previews. No user-visible error; the scrubber remains usable without
 * thumbnail strips.
 */
final readonly class TrickplayFailedMsg implements Msg
{
}
