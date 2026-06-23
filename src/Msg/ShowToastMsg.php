<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;
use SugarCraft\Toast\ToastType;

/**
 * Ask the App to surface a toast notification. Any screen (or the App itself)
 * emits this via {@see \SugarCraft\Core\Cmd::send()}; the App owns the single
 * toast host so notifications float above whatever screen is on top.
 *
 * Use the named constructors so callers needn't import {@see ToastType}.
 */
final readonly class ShowToastMsg implements Msg
{
    public function __construct(
        public ToastType $type,
        public string $message,
    ) {
    }

    public static function error(string $message): self
    {
        return new self(ToastType::Error, $message);
    }

    public static function warning(string $message): self
    {
        return new self(ToastType::Warning, $message);
    }

    public static function info(string $message): self
    {
        return new self(ToastType::Info, $message);
    }

    public static function success(string $message): self
    {
        return new self(ToastType::Success, $message);
    }
}
