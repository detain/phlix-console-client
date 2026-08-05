<?php
declare(strict_types=1);
namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Message sent to a screen to trigger its initial data fetch.
 * Used by screens that use the update() pattern for initialization
 * instead of the init() method pattern.
 */
final readonly class InitMsg implements Msg
{
}