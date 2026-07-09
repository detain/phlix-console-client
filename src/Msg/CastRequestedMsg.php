<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\MediaItem;
use SugarCraft\Core\Msg;

/**
 * Cast this item — the App pushes a {@see \Phlix\Console\Screen\CastScreen}.
 *
 * Carries the already-loaded {@see MediaItem} (a leaf, so it holds the signed
 * `stream_url`) so the cast screen can send it to a discovered device without
 * re-fetching the detail. Emitted by DetailScreen's `C` key, handled by the App
 * (mirroring {@see PlayRequestedMsg}).
 */
final readonly class CastRequestedMsg implements Msg
{
    public function __construct(
        public MediaItem $item,
    ) {
    }
}
