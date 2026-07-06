<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Http;

use Omegaalfa\FiberEventLoop\FiberEventLoop;
use Omegaalfa\FiberEventLoop\Future;

final class ConcurrentExecutor
{
    public function __construct(private readonly FiberEventLoop $loop)
    {
    }

    /**
     * @param array<string|int, Future> $futures
     * @return Future
     */
    public function all(array $futures): Future
    {
        return $this->loop->async(function () use ($futures) {
            $results = [];

            foreach ($futures as $key => $future) {
                $results[$key] = $future->await();
            }

            return $results;
        });
    }

    /**
     * @param array<string|int, Future> $futures
     * @return Future
     */
    public function any(array $futures): Future
    {
        return $this->loop->async(static fn () => awaitAny($futures));
    }

    /**
     * @param array<string|int, Future> $futures
     * @return Future
     */
    public function race(array $futures): Future
    {
        return $this->loop->async(static fn () => race($futures));
    }

    /**
     * @param array<string|int, Future> $futures
     * @return Future
     */
    public function settled(array $futures): Future
    {
        return $this->loop->async(static fn () => awaitSettled($futures));
    }

    /**
     * @param array<string|int, callable> $tasks
     * @return Future
     */
    public function named(array $tasks): Future
    {
        return $this->loop->async(function () use ($tasks) {
            $futures = [];
            foreach ($tasks as $key => $task) {
                $result = $task();
                $futures[$key] = $result instanceof Future
                    ? $result
                    : $this->loop->async(static fn () => $result);
            }

            $resolved = [];
            foreach ($futures as $key => $future) {
                $resolved[$key] = $future->await();
            }

            return $resolved;
        });
    }
}