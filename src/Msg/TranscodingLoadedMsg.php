<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Admin\TranscodingAccelerators;
use Phlix\Console\Api\Dto\Admin\ToneMappingSettings;
use SugarCraft\Core\Msg;

/**
 * Carries the concurrent result of the transcoding accelerators + tone-mapping
 * GETs into the AdminTranscodingScreen so it can swap in the loaded data.
 */
final readonly class TranscodingLoadedMsg implements Msg
{
    public function __construct(
        public TranscodingAccelerators $accelerators,
        public ToneMappingSettings $toneMapping,
    ) {
    }
}
