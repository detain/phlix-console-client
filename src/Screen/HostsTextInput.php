<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Screen;

/**
 * Contract for screens that can hold a focused sugar-forms text input — a
 * screen whose currently-open form focuses an
 * {@see \SugarCraft\Forms\Field\Input} field, so printable characters belong
 * to the field.
 *
 * The App's global `/` (search) and `:` (palette) gate [review console__a11y,
 * CU-007] asks this in addition to the blanket {@see CapturesSlash} marker:
 * while {@see isEditingText()} is true the keys type into the field instead of
 * opening overlays; while it is false the shortcuts stay live, so screens that
 * merely CAN host text input never swallow the commands while nothing is
 * being edited. A screen with a text input that has no form state to consult
 * should implement {@see CapturesSlash} (whole-screen capture) instead.
 *
 * Drift law (pin: tests/Screen/TextInputCaptureGateTest): every screen in
 * `src/Screen/` that instantiates a text `Input` field implements either
 * marker — the gate cannot be forgot open.
 */
interface HostsTextInput
{
    /** Whether the screen's active form currently focuses a text-entry field. */
    public function isEditingText(): bool;
}
