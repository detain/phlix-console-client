<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Open the personalized recommendations screen ("For You").
 * The App pushes a RecommendationsScreen onto the stack.
 */
final readonly class OpenRecommendationsMsg implements Msg
{
}
