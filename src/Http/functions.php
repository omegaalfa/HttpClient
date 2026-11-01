<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Http;

use InvalidArgumentException;
use Omegaalfa\HttpClient\Loop\BoundedChannel;
use Omegaalfa\HttpClient\Loop\Task;
use Throwable;

/**
 * @throws Throwable
 */
function await(Task $task): mixed
{
    return $task->await();
}

/**
 * @param array<array-key, mixed> $tasks
 * @return array<array-key, mixed>
 */
function awaitAll(array $tasks): array
{
    foreach ($tasks as $task) {
        if (!$task instanceof Task) {
            throw new InvalidArgumentException('awaitAll expects only Task instances');
        }
    }

    // The group owns every child's failure, including later children that
    // won't be awaited if an earlier child rejects.
    foreach ($tasks as $task) {
        $task->markObserved();
    }

    $results = [];
    foreach ($tasks as $key => $task) {
        $results[$key] = $task->await();
    }

    return $results;
}

/**
 * Return the first task to settle, preserving its key and value.
 * A rejected first task is rethrown, matching Task::await().
 *
 * @param array<string|int, Task> $tasks
 * @return array{key: string|int, value: mixed}
 */
function awaitAny(array $tasks): array
{
    if ($tasks === []) {
        throw new InvalidArgumentException('awaitAny expects at least one Task');
    }

    foreach ($tasks as $task) {
        if (!$task instanceof Task) {
            throw new InvalidArgumentException('awaitAny expects only Task instances');
        }
    }

    $channel = new BoundedChannel(count($tasks));
    $observers = [];
    foreach ($tasks as $key => $task) {
        $observers[] = new Task(static function () use ($key, $task, $channel): void {
            try {
                $result = ['key' => $key, 'value' => $task->await()];
            } catch (Throwable $error) {
                $result = ['key' => $key, 'error' => $error];
            }

            $channel->send($result);
        });
    }

    try {
        /** @var array{key: string|int, value?: mixed, error?: Throwable} $winner */
        $winner = new Task(static fn (): mixed => $channel->receive())->await();
    } catch (Throwable $e) {
        throw $e;
    } finally {
        foreach ($observers as $observer) {
            $observer->cancel();
        }
    }

    if (isset($winner['error'])) {
        throw $winner['error'];
    }

    return ['key' => $winner['key'], 'value' => $winner['value'] ?? null];
}

/** @param array<string|int, Task> $tasks
 * @throws Throwable
 */
function race(array $tasks): mixed
{
    return awaitAny($tasks)['value'];
}

/**
 * @param array<string|int, Task> $tasks
 * @return array<array-key, array{state: 'fulfilled', value: mixed}|array{state: 'rejected', reason: Throwable}>
 */
function awaitSettled(array $tasks): array
{
    foreach ($tasks as $task) {
        if (!$task instanceof Task) {
            throw new InvalidArgumentException('awaitSettled expects only Task instances');
        }
    }

    $results = [];
    foreach ($tasks as $key => $task) {
        try {
            $results[$key] = [
                'state' => 'fulfilled',
                'value' => $task->await(),
            ];
        } catch (Throwable $exception) {
            $results[$key] = [
                'state' => 'rejected',
                'reason' => $exception,
            ];
        }
    }

    return $results;
}
