<?php
declare(strict_types=1);
namespace Phlix\Console\Msg;
use SugarCraft\Core\Msg;
final readonly class StatsLoadedMsg implements Msg
{
    /** @param array{playback:?array, storage:?array, top_media:?list<array>, top_users:?list<array>} $stats */
    public function __construct(public array $stats) {}
}