<?php

declare(strict_types=1);

namespace Phlix\Console\Api\SyncPlay;

/**
 * Time synchronization for SyncPlay - mirrors TypeScript TimeSync class.
 *
 * Calculates offset between local clock and server clock using
 * round-trip time measurements from TIME_PING/TIME_PONG exchanges.
 */
final class TimeSync
{
    /** Maximum number of samples to keep for smoothing. */
    private const MAX_SAMPLES = 10;

    /** Minimum samples required before reporting stable status. */
    private const MIN_SAMPLES_FOR_STABLE = 3;

    /** Clock stability threshold in ms. */
    private const STABILITY_THRESHOLD = 100.0;

    /** @var list<array{offset:float, latency:float}> */
    private array $samples = [];

    /** Most recent computed offset in ms. */
    private float $offset = 0.0;

    /** Most recent computed latency in ms. */
    private float $latency = 0.0;

    /** Whether the clock is considered stable. */
    private bool $isStable = false;

    /** @var \Closure(): int Unix timestamp in ms */
    private \Closure $now;

    public function __construct(?\Closure $now = null)
    {
        $this->now = $now ?? static fn (): int => (int) (microtime(true) * 1000);
    }

    /**
     * Process a TIME_PONG response and update the clock offset.
     *
     * @param int $clientTime The original client timestamp (t1) sent in the PING
     * @param int $serverTime The server receive time (t2) from the PONG
     */
    public function processPong(int $clientTime, int $serverTime): void
    {
        $t4 = ($this->now)();
        $rtt = $t4 - $clientTime;
        $latency = $rtt / 2.0;
        $offset = $serverTime - $t4 + $latency;

        // Add sample and maintain window size
        $this->samples[] = ['offset' => $offset, 'latency' => $latency];
        if (count($this->samples) > self::MAX_SAMPLES) {
            array_shift($this->samples);
        }

        // Update current values using median of recent samples
        $this->recalculate();
    }

    /**
     * Apply a server-initiated time drift correction.
     *
     * @param int $serverTime Server's current time (ms)
     * @param int $clientTime Client's last known time (ms)
     */
    public function applyDriftCorrection(int $serverTime, int $clientTime): void
    {
        // Calculate expected server time based on offset
        $expectedServerTime = $serverTime - $this->offset;

        // Adjust offset to correct drift
        $drift = $expectedServerTime - $clientTime;
        $this->offset += $drift * 0.5; // Apply correction gradually

        // Invalidate stability temporarily
        $this->isStable = false;
    }

    /**
     * Get the server-synchronized current time in ms.
     */
    public function getSynchronizedTime(): int
    {
        return ($this->now)() + (int) $this->offset;
    }

    /**
     * Get the current time sync status.
     *
     * @return array{offset:float, latency:float, isStable:bool}
     */
    public function getStatus(): array
    {
        return [
            'offset' => $this->offset,
            'latency' => $this->latency,
            'isStable' => $this->isStable,
        ];
    }

    /**
     * Get the raw offset value in ms.
     */
    public function getOffset(): float
    {
        return $this->offset;
    }

    /**
     * Recalculate offset and latency from sample median.
     */
    private function recalculate(): void
    {
        $count = count($this->samples);
        if ($count === 0) {
            return;
        }

        // Sort by offset for median calculation
        $offsets = array_column($this->samples, 'offset');
        $latencies = array_column($this->samples, 'latency');

        sort($offsets);
        sort($latencies);

        $mid = intdiv($count, 2);
        $this->offset = $offsets[$mid];
        $this->latency = $latencies[$mid];

        // Determine stability based on sample variance
        if ($count >= self::MIN_SAMPLES_FOR_STABLE) {
            $this->isStable = $this->calculateVariance($offsets) < self::STABILITY_THRESHOLD;
        }
    }

    /**
     * Calculate variance of a list of values.
     *
     * @param list<float> $values
     */
    private function calculateVariance(array $values): float
    {
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }

        $mean = array_sum($values) / $count;
        $squaredDiffs = array_map(fn (float $v): float => ($v - $mean) ** 2, $values);

        return array_sum($squaredDiffs) / $count;
    }

    /**
     * Reset all samples and stability state.
     */
    public function reset(): void
    {
        $this->samples = [];
        $this->offset = 0.0;
        $this->latency = 0.0;
        $this->isStable = false;
    }
}
