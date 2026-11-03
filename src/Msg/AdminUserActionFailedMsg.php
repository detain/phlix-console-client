<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A user action failed — carries the ONE user-safe sentence produced by
 * ExceptionSentences at the screen's arm: the server's own `error` text
 * (e.g. "Cannot disable the last admin") when the failure is wire prose,
 * otherwise the curated network/generic sentence. Never raw throwable
 * text [review M15]; the list is left unchanged.
 */
final readonly class AdminUserActionFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}
