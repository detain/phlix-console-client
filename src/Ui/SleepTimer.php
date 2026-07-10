<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Ui;

use Phlix\Console\Msg\SleepTimerFireMsg;
use Phlix\Console\Msg\SleepTimerTickMsg;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;

/**
 * In-player sleep timer with preset durations: 15, 30, 45, 60, 90, 120 minutes.
 *
 * Mirrors the Roku client's SleepTimer.brs pattern:
 * - Start a countdown from a preset duration
 * - Tick every second via SleepTimerTickMsg
 * - Fire SleepTimerFireMsg when the timer expires → player pauses
 * - Cancel resets the timer
 *
 * Persists the last-used preset index to user_settings via the PlayerPrefs API
 * so the console can restore the user's preferred default.
 */
final class SleepTimer
{
    /** Preset durations in minutes. */
    public const PRESETS = [15, 30, 45, 60, 90, 120];

    private ?int $remainingSeconds = null;
    private bool $active = false;
    /** Index into PRESETS, or -1 when cancelled/inactive. */
    private int $selectedPresetIndex = -1;

    /**
     * Start the timer from a preset index (0-based into PRESETS).
     *
     * @return array{timer: self, cmd: \Closure|null}
     */
    public function startFromPreset(int $presetIndex): array
    {
        if ($presetIndex < 0 || $presetIndex >= count(self::PRESETS)) {
            return ['timer' => $this, 'cmd' => null];
        }

        $minutes = self::PRESETS[$presetIndex];
        $this->remainingSeconds = $minutes * 60;
        $this->active = true;
        $this->selectedPresetIndex = $presetIndex;

        return ['timer' => $this, 'cmd' => $this->tickCmd()];
    }

    /**
     * Cancel the running timer.
     *
     * @return array{timer: self, cmd: \Closure|null}
     */
    public function cancel(): array
    {
        $this->remainingSeconds = null;
        $this->active = false;
        $this->selectedPresetIndex = -1;

        return ['timer' => $this, 'cmd' => null];
    }

    /**
     * Tick the timer: decrement remaining seconds.
     * Returns self and optionally a follow-up tick Cmd.
     * Fires SleepTimerFireMsg when the timer expires.
     *
     * @return array{timer: self, cmd: \Closure|null}
     */
    public function tick(): array
    {
        if (!$this->active || $this->remainingSeconds === null) {
            return ['timer' => $this, 'cmd' => null];
        }

        $this->remainingSeconds--;

        if ($this->remainingSeconds <= 0) {
            $this->remainingSeconds = null;
            $this->active = false;

            return ['timer' => $this, 'cmd' => fn (): Msg => new SleepTimerFireMsg()];
        }

        return ['timer' => $this, 'cmd' => $this->tickCmd()];
    }

    /**
     * Dispatch a SleepTimerTickMsg for the current remaining seconds.
     * Used by PlayerScreen to update the status line display.
     */
    public function dispatchTick(): Msg
    {
        return new SleepTimerTickMsg($this->remainingSeconds ?? 0);
    }

    private function tickCmd(): \Closure
    {
        return Cmd::tick(1.0, function (): Msg {
            /** @var SleepTimerTickMsg */
            return $this->dispatchTick();
        });
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function remainingSeconds(): ?int
    {
        return $this->remainingSeconds;
    }

    public function selectedPresetIndex(): int
    {
        return $this->selectedPresetIndex;
    }

    /**
     * Format the remaining time as "h:mm:ss" or "m:ss".
     */
    public function formatRemaining(): string
    {
        if ($this->remainingSeconds === null) {
            return '';
        }

        $total = max(0, $this->remainingSeconds);
        $h = intdiv($total, 3600);
        $m = intdiv($total % 3600, 60);
        $s = $total % 60;

        if ($h > 0) {
            return sprintf('%d:%02d:%02d', $h, $m, $s);
        }

        return sprintf('%d:%02d', $m, $s);
    }
}
