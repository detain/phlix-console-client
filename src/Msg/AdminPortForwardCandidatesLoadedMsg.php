<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Admin\PortForwardCandidate;
use SugarCraft\Core\Msg;

/**
 * The discovered port-forward candidates were fetched — carries the list for the
 * Remote Access screen's read-only candidates sub-view to render.
 */
final readonly class AdminPortForwardCandidatesLoadedMsg implements Msg
{
    /**
     * @param list<PortForwardCandidate> $candidates
     */
    public function __construct(
        public array $candidates,
    ) {
    }
}
