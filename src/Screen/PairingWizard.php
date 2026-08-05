<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

/**
 * Immutable hub pairing wizard state machine. Tracks the phase, user input
 * buffer, claim details, remaining poll countdown, and any error message.
 *
 * @readonly
 */
final readonly class PairingWizard
{
    // Phase constants
    private const PHASE_IDLE = 0;
    private const PHASE_WAITING_FOR_URL = 1;
    private const PHASE_POLLING = 3;
    private const PHASE_ERROR = 4;

    private function __construct(
        private int $phase,
        private string $inputBuffer,
        private string $claimCode,
        private string $claimId,
        private string $hubUrl,
        private int $pollLeft,
        private ?string $errorMessage,
    ) {
    }

    public static function idle(): self
    {
        return new self(self::PHASE_IDLE, '', '', '', '', 0, null);
    }

    public static function waitingForUrl(): self
    {
        return new self(self::PHASE_WAITING_FOR_URL, '', '', '', '', 0, null);
    }

    /**
     * @param string $claimCode Human-readable claim code (e.g. "ABCD-1234")
     * @param string $claimId   Opaque claim ID for polling
     * @param string $hubUrl    The hub base URL
     * @param int    $expiresIn Seconds until the claim expires (used to derive pollLeft)
     */
    public static function showingCode(string $claimCode, string $claimId, string $hubUrl, int $expiresIn): self
    {
        // Derive initial poll-left from claim TTL: one poll every 3s, max 10.
        $pollLeft = min(10, (int) ceil($expiresIn / 3.0));

        return new self(self::PHASE_POLLING, '', $claimCode, $claimId, $hubUrl, $pollLeft, null);
    }

    public static function error(string $message): self
    {
        return new self(self::PHASE_ERROR, '', '', '', '', 0, $message);
    }

    public function phase(): int
    {
        return $this->phase;
    }

    public function inputBuffer(): string
    {
        return $this->inputBuffer;
    }

    public function claimCode(): string
    {
        return $this->claimCode;
    }

    public function claimId(): string
    {
        return $this->claimId;
    }

    public function hubUrl(): string
    {
        return $this->hubUrl;
    }

    public function pollLeft(): int
    {
        return $this->pollLeft;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function isIdle(): bool
    {
        return $this->phase === self::PHASE_IDLE;
    }

    public function isWaitingForUrl(): bool
    {
        return $this->phase === self::PHASE_WAITING_FOR_URL;
    }

    /** Append a character to the URL input buffer. */
    public function appendChar(string $char): self
    {
        return new self(
            $this->phase,
            $this->inputBuffer . $char,
            $this->claimCode,
            $this->claimId,
            $this->hubUrl,
            $this->pollLeft,
            $this->errorMessage,
        );
    }

    /** Update the remaining poll countdown (called after each pending poll result). */
    public function withPollCountdown(int $remaining): self
    {
        return new self(
            $this->phase,
            $this->inputBuffer,
            $this->claimCode,
            $this->claimId,
            $this->hubUrl,
            $remaining,
            $this->errorMessage,
        );
    }

    // Phase query helpers (for use in statusLine / hint)
    public function isPolling(): bool
    {
        return $this->phase === self::PHASE_POLLING;
    }

    public function isError(): bool
    {
        return $this->phase === self::PHASE_ERROR;
    }
}
