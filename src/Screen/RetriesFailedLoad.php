<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Screen;

/**
 * Shared 'r' retry arm for content screens whose failed-load hint publishes
 * "r  retry" [CON-44]: clears the error, flips the screen back to loading and
 * re-runs its init() fetch. The host declares `$error` and `$loading`.
 */
trait RetriesFailedLoad
{
    /** @return array{self, ?\Closure} */
    private function retryLoad(): array
    {
        $next = clone $this;
        $next->error = null;
        $next->loading = true;

        return [$next, $this->init()];
    }
}
