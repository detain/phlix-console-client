<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Media;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * A promise semaphore that limits the number of concurrently executing promises.
 *
 * At most N promises run at once; additional promises are queued FIFO and
 * dispatched as slots become available. When a promise settles (resolve or
 * reject) its slot is freed and the next queued promise is started.
 *
 * The concurrency limit is controlled by the PHLIX_POSTER_CONCURRENCY
 * environment variable (defaults to 6).
 *
 * @template T
 */
final class Semaphore
{
    /**
     * @var list<array{task: callable(): PromiseInterface<T>, deferred: Deferred<T>}>
     */
    private array $queue = [];

    private int $running = 0;

    private readonly int $limit;

    public function __construct(?int $limit = null)
    {
        if ($limit !== null) {
            $this->limit = $limit;
        } else {
            $env = $_ENV['PHLIX_POSTER_CONCURRENCY'] ?? $_SERVER['PHLIX_POSTER_CONCURRENCY'] ?? null;
            if ($env !== null) {
                $parsed = filter_var($env, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $this->limit = $parsed !== false ? $parsed : self::defaultLimit();
            } else {
                $this->limit = self::defaultLimit();
            }
        }
    }

    private static function defaultLimit(): int
    {
        return 6;
    }

    /**
     * Wrap a callable so it runs through the semaphore.
     *
     * The callable should return a PromiseInterface. The semaphore ensures at
     * most N calls run concurrently.
     *
     * @param callable(): PromiseInterface<T> $task
     * @return PromiseInterface<T>
     */
    public function wrap(callable $task): PromiseInterface
    {
        /** @var Deferred<T> */
        $deferred = new Deferred();

        /** @var array{task: callable(): PromiseInterface<T>, deferred: Deferred<T>} $entry */
        $entry = [
            'task' => $task,
            'deferred' => $deferred,
        ];

        $this->queue[] = $entry;

        $this->dispatch();

        return $deferred->promise();
    }

    private function dispatch(): void
    {
        // Start as many queued tasks as we have free slots
        while ($this->queue !== [] && $this->running < $this->limit) {
            /** @var array{task: callable(): PromiseInterface<T>, deferred: Deferred<T>} $entry */
            $entry = array_shift($this->queue);
            $task = $entry['task'];
            $deferred = $entry['deferred'];

            $this->running++;

            $promise = $task();

            $promise->then(
                function (mixed $value) use ($deferred): void {
                    $deferred->resolve($value);
                    $this->running--;
                    $this->dispatch();
                },
                function (mixed $reason) use ($deferred): void {
                    $deferred->reject($reason);
                    $this->running--;
                    $this->dispatch();
                },
            );
        }
    }

    public function __destruct()
    {
        foreach ($this->queue as $entry) {
            $entry['deferred']->reject(new \RuntimeException('Semaphore shutting down'));
        }
    }
}
