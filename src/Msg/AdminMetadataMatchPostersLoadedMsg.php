<?php
declare(strict_types=1);
namespace Phlix\Console\Msg;
use SugarCraft\Core\Msg;
/** @param list<array{url:string,thumb:string,width:int,height:int}> $posters */
final readonly class AdminMetadataMatchPostersLoadedMsg implements Msg
{
    public function __construct(public array $posters) {}
}
