<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Http;

use Fiber;
use InvalidArgumentException;
use Omegaalfa\FiberEventLoop\Future;
use Throwable;

function await(Future $future): mixed
{
    return $future->await();
}

/**
 * @param array<array-key, mixed> $futures
 * @return array<array-key, mixed>
 */
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
 * Retorna o primeiro futuro a assentar (resolvido ou rejeitado). O vencedor é
 * sempre o primeiro a assentar de fato — nunca o primeiro da lista —, mesmo que
 * os demais levem mais tempo.
 *
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

    $firstSettled = static function () use ($futures, $loop): array {
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

            $loop->next();
        }
    };

    if (Fiber::getCurrent() !== null) {
        return $firstSettled();
    }

    // Fora de fiber, next() não avança o loop: o polling roda dentro de um
    // fiber próprio e o run() do await() externo é encerrado no vencedor.
    $driver = $loop->async(static function () use ($firstSettled, $loop): array {
        try {
            return $firstSettled();
        } finally {
            $loop->stop();
        }
    });

    return $driver->await();
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
 * @return array<array-key, array{state: 'fulfilled', value: mixed}|array{state: 'rejected', reason: Throwable}>
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
