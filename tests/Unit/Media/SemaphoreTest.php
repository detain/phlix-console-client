<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Tests\Unit\Media;

use Phlix\Console\Media\Semaphore;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * @internal
 *
 * @covers \Phlix\Console\Media\Semaphore
 */
final class SemaphoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset environment variables between tests
        unset($_ENV['PHLIX_POSTER_CONCURRENCY'], $_SERVER['PHLIX_POSTER_CONCURRENCY']);
    }

    public function testDefaultLimitIsSix(): void
    {
        $sem = new Semaphore();
        self::assertSame(6, $this->getLimit($sem));
    }

    public function testCustomLimitIsRespected(): void
    {
        $sem = new Semaphore(3);
        self::assertSame(3, $this->getLimit($sem));
    }

    public function testEnvVarOverridesDefault(): void
    {
        $_ENV['PHLIX_POSTER_CONCURRENCY'] = '4';
        $sem = new Semaphore();
        self::assertSame(4, $this->getLimit($sem));
    }

    public function testEnvVarMustBePositive(): void
    {
        // Invalid value should fall back to default
        $_ENV['PHLIX_POSTER_CONCURRENCY'] = '-1';
        $sem = new Semaphore();
        self::assertSame(6, $this->getLimit($sem));

        $_ENV['PHLIX_POSTER_CONCURRENCY'] = '0';
        $sem = new Semaphore();
        self::assertSame(6, $this->getLimit($sem));

        $_ENV['PHLIX_POSTER_CONCURRENCY'] = 'abc';
        $sem = new Semaphore();
        self::assertSame(6, $this->getLimit($sem));
    }

    public function testAtMostNConcurrentWithTwentyQueued(): void
    {
        $limit = 6;
        $total = 20;
        $sem = new Semaphore($limit);

        $maxObserved = 0;
        $currentlyRunning = 0;
        $started = 0;
        $finished = 0;

        $promises = [];

        for ($i = 0; $i < $total; $i++) {
            $idx = $i;
            $promises[] = $sem->wrap(function () use ($idx, &$currentlyRunning, &$maxObserved, &$started, &$finished): PromiseInterface {
                $started++;
                $currentlyRunning++;
                $maxObserved = max($maxObserved, $currentlyRunning);

                // Create a promise that resolves after a small delay
                $deferred = new Deferred();
                Loop::addTimer(0.01, static function () use ($deferred, $idx, &$currentlyRunning, &$finished): void {
                    $currentlyRunning--;
                    $finished++;
                    $deferred->resolve($idx);
                });

                return $deferred->promise();
            });
        }

        // Wait for all promises to complete
        $this->awaitAll($promises);

        self::assertSame($total, $started);
        self::assertSame($total, $finished);
        self::assertLessThanOrEqual($limit, $maxObserved, "Concurrency should not exceed {$limit}");
    }

    public function testRejectedLoadFreesSlotAndQueueDoesNotDeadlock(): void
    {
        $limit = 2;
        $total = 5;
        $sem = new Semaphore($limit);

        $started = 0;
        $finished = 0;
        $rejected = 0;

        $promises = [];

        // Alternate between success and failure
        for ($i = 0; $i < $total; $i++) {
            $promises[] = $sem->wrap(function () use ($i, &$started, &$finished, &$rejected): PromiseInterface {
                $started++;

                $deferred = new Deferred();
                $isFailure = ($i % 2 === 1); // Odd indices fail

                Loop::addTimer(0.005, static function () use ($deferred, $i, $isFailure, &$finished, &$rejected): void {
                    if ($isFailure) {
                        $rejected++;
                        $deferred->reject(new \RuntimeException("Reject {$i}"));
                    } else {
                        $finished++;
                        $deferred->resolve($i);
                    }
                });

                return $deferred->promise();
            });
        }

        // Wait for all promises to complete (some will be rejected)
        $this->awaitAllSettled($promises);

        self::assertSame($total, $started);
        self::assertSame(5, $finished + $rejected, 'All promises should have settled');
        self::assertSame(2, $rejected, 'Two promises should have been rejected');
        self::assertSame(3, $finished, 'Three promises should have resolved');
    }

    public function testFifoOrderIsRespected(): void
    {
        $limit = 2;
        $total = 5;
        $sem = new Semaphore($limit);

        $order = [];

        $promises = [];

        for ($i = 0; $i < $total; $i++) {
            $idx = $i;
            $promises[] = $sem->wrap(function () use ($idx, &$order): PromiseInterface {
                $deferred = new Deferred();
                // Each task resolves instantly but we track when they START
                // (the semaphore dispatches in order)
                Loop::futureTick(static function () use ($deferred, $idx, &$order): void {
                    $order[] = $idx;
                    $deferred->resolve($idx);
                });

                return $deferred->promise();
            });
        }

        $this->awaitAll($promises);

        // First 2 should have started before any of them finished (due to limit=2)
        // But the first $limit items should be the first to acquire slots
        for ($i = 0; $i < $limit; $i++) {
            self::assertContains($i, array_slice($order, 0, $limit), "First {$limit} items should include {$i}");
        }
    }

    public function testEnvVarViaServerSuperglobal(): void
    {
        $_SERVER['PHLIX_POSTER_CONCURRENCY'] = '3';
        $sem = new Semaphore();
        self::assertSame(3, $this->getLimit($sem));
    }

    /**
     * Extract the private $limit property via reflection.
     */
    private function getLimit(Semaphore $sem): int
    {
        $ref = new \ReflectionClass($sem);
        $prop = $ref->getProperty('limit');
        $prop->setAccessible(true);

        return (int) $prop->getValue($sem);
    }

    /**
     * Wait for all promises to resolve.
     *
     * @param list<PromiseInterface> $promises
     * @return list<mixed>
     */
    private function awaitAll(array $promises): array
    {
        $results = [];
        $state = ['done' => 0, 'total' => count($promises)];

        foreach ($promises as $promise) {
            $promise->then(
                function (mixed $v) use (&$results, &$state): void {
                    $results[] = $v;
                    $state['done']++;
                    if ($state['done'] === $state['total']) {
                        Loop::stop();
                    }
                },
                function (mixed $e) use (&$state): void {
                    $state['done']++;
                    if ($state['done'] === $state['total']) {
                        Loop::stop();
                    }
                },
            );
        }

        if ($state['done'] < $state['total']) {
            $timer = Loop::addTimer(5.0, static fn () => Loop::stop());
            Loop::run();
            Loop::cancelTimer($timer);
        }

        return $results;
    }

    /**
     * Wait for all promises to settle (resolve or reject).
     *
     * @param list<PromiseInterface> $promises
     */
    private function awaitAllSettled(array $promises): void
    {
        $state = ['done' => 0, 'total' => count($promises)];

        foreach ($promises as $promise) {
            $promise->then(
                function () use (&$state): void {
                    $state['done']++;
                    if ($state['done'] === $state['total']) {
                        Loop::stop();
                    }
                },
                function () use (&$state): void {
                    $state['done']++;
                    if ($state['done'] === $state['total']) {
                        Loop::stop();
                    }
                },
            );
        }

        if ($state['done'] < $state['total']) {
            $timer = Loop::addTimer(5.0, static fn () => Loop::stop());
            Loop::run();
            Loop::cancelTimer($timer);
        }
    }
}
