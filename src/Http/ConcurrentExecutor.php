<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Http;

use Omegaalfa\HttpClient\Loop\Loop;
use Omegaalfa\HttpClient\Loop\Task;

final class ConcurrentExecutor
{
    public function __construct(Loop $loop)
    {
        // Tasks use the process-wide loop singleton; reject mismatched injection.
        if ($loop !== Loop::get()) {
            throw new \LogicException('ConcurrentExecutor must use the active Loop singleton.');
        }
    }

    /**
     * @param array<string|int, Task> $futures
     * @return Task
     */
    public function all(array $futures): Task
    {
        return new Task(function () use ($futures) {
            $results = [];

            foreach ($futures as $key => $future) {
                $results[$key] = $future->await();
            }

            return $results;
        });
    }

    /**
     * @param array<string|int, Task> $futures
     * @return Task
     */
    public function any(array $futures): Task
    {
        return new Task(static fn () => awaitAny($futures));
    }

    /**
     * @param array<string|int, Task> $futures
     * @return Task
     */
    public function race(array $futures): Task
    {
        return new Task(static fn () => race($futures));
    }

    /**
     * @param array<string|int, Task> $futures
     * @return Task
     */
    public function settled(array $futures): Task
    {
        return new Task(static fn () => awaitSettled($futures));
    }

    /**
     * @param array<string|int, callable> $tasks
     * @return Task
     */
    public function named(array $tasks): Task
    {
        return new Task(function () use ($tasks) {
            $futures = [];
            foreach ($tasks as $key => $task) {
                $result = $task();
                $futures[$key] = $result instanceof Task
                    ? $result
                    : new Task(static fn () => $result);
            }

            $resolved = [];
            foreach ($futures as $key => $future) {
                $resolved[$key] = $future->await();
            }

            return $resolved;
        });
    }
}
