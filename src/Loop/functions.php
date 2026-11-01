<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Loop;

/**
 * Funções auxiliares para criação de tarefas
 */
function async(callable $fn): Task
{
    return new Task($fn);
}

/**
 * @param Task $task
 * @return mixed
 * @throws \Throwable
 */
function await(Task $task): mixed
{
    return $task->await();
}

/**
 * @param Task ...$tasks
 * @return list<mixed>
 * @throws \Throwable
 */
function awaitAll(Task ...$tasks): array
{
    foreach ($tasks as $task) {
        $task->markObserved();
    }

    $results = [];
    foreach ($tasks as $task) {
        $results[] = $task->await();
    }

    return $results;
}

function delay(float $seconds): void
{
    Loop::get()->sleep($seconds);
}

function pause(): void
{
    Loop::get()->pause();
}

/** @param resource $stream */
function readable($stream): void
{
    Loop::get()->waitReadable($stream);
}

/** @param resource $stream */
function writable($stream): void
{
    Loop::get()->waitWritable($stream);
}

function run(callable $main): mixed
{
    return async($main)->await();
}
