<?php
declare(strict_types=1);
namespace Phlix\Console\Msg;
use SugarCraft\Core\Msg;
/** @param list<array{id:string,title:string,type:string,poster_url:?string}> $items */
final readonly class AdminMetadataMatchLoadedMsg implements Msg
{
    public function __construct(public array $items) {}
}
