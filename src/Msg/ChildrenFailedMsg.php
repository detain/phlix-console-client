<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Loading a container's children failed. Tagged with the owning `parentId`
 * (see {@see ChildrenLoadedMsg}) so it only affects the DetailScreen it belongs
 * to.
 */
final readonly class ChildrenFailedMsg implements Msg
{
    public function __construct(
        public string $parentId,
        public string $reason,
    ) {
    }
}
