<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Open the personalized recommendations screen ("For You").
 * The App pushes a RecommendationsScreen onto the stack.
 */
final readonly class OpenRecommendationsMsg implements Msg
{
}
