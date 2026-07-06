<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Http;

use InvalidArgumentException;
use Omegaalfa\FiberEventLoop\Future;
use Throwable;

function await(Future $future): mixed
{
    return $future->await();
}

function awaitAll(array $futures): array
{
    if ($futures === []) {
        return [];
    }

    foreach ($futures as $future) {
        if (!$future instanceof Future) {
            throw new InvalidArgumentException('awaitAll expects only Future instances');
        }
    }

    $results = [];
    foreach ($futures as $key => $future) {
        $results[$key] = $future->await();
    }

    return $results;
}

/**
 * @param array<string|int, Future> $futures
 * @return array{key: string|int, value: mixed}
 */
function awaitAny(array $futures): array
{
    if ($futures === []) {
        throw new InvalidArgumentException('awaitAny expects at least one Future');
    }

    $first = null;
    foreach ($futures as $future) {
        if (!$future instanceof Future) {
            throw new InvalidArgumentException('awaitAny expects only Future instances');
        }

        $first ??= $future;
        if ($future->getLoop() !== $first->getLoop()) {
            throw new InvalidArgumentException('awaitAny expects futures bound to the same event loop');
        }
    }

    $loop = $first->getLoop();
    $ticks = 0;

    while (true) {
        foreach ($futures as $key => $future) {
            if (!$future->isSettled()) {
                continue;
            }

            return [
                'key' => $key,
                'value' => $future->await(),
            ];
        }

        if (++$ticks >= 10_000) {
            $firstKey = array_key_first($futures);
            $firstFuture = $futures[$firstKey];

            return [
                'key' => $firstKey,
                'value' => $firstFuture->await(),
            ];
        }

        $loop->next();
    }
}

/**
 * @param array<string|int, Future> $futures
 */
function race(array $futures): mixed
{
    return awaitAny($futures)['value'];
}

/**
 * @param array<string|int, Future> $futures
 * @return array<string|int, array{state: 'fulfilled'|'rejected', value?: mixed, reason?: Throwable}>
 */
function awaitSettled(array $futures): array
{
    if ($futures === []) {
        return [];
    }

    foreach ($futures as $future) {
        if (!$future instanceof Future) {
            throw new InvalidArgumentException('awaitSettled expects only Future instances');
        }
    }

    $results = [];
    foreach ($futures as $key => $future) {
        try {
            $results[$key] = [
                'state' => 'fulfilled',
                'value' => $future->await(),
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