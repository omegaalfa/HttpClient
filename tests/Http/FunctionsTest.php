<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Http;

use InvalidArgumentException;
use Omegaalfa\HttpClient\Loop\Loop;
use Omegaalfa\HttpClient\Loop\Task;
use PHPUnit\Framework\TestCase;

use function Omegaalfa\HttpClient\Http\await;
use function Omegaalfa\HttpClient\Http\awaitAll;
use function Omegaalfa\HttpClient\Http\awaitAny;
use function Omegaalfa\HttpClient\Http\awaitSettled;
use function Omegaalfa\HttpClient\Http\race;
use function Omegaalfa\HttpClient\Loop\awaitAll as loopAwaitAll;

final class FunctionsTest extends TestCase
{
    public function testAwaitResolvesTaskValue(): void
    {
        $task = new Task(static fn (): int => 42);

        self::assertSame(42, await($task));
    }

    public function testAwaitAllReturnsResolvedResultsKeepingKeys(): void
    {
        $f1 = new Task(static fn (): string => 'a');
        $f2 = new Task(static fn (): string => 'b');

        self::assertSame(['first' => 'a', 'second' => 'b'], awaitAll([
            'first' => $f1,
            'second' => $f2,
        ]));
    }

    public function testAwaitAllThrowsOnInvalidInput(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('awaitAll expects only Task instances');

        awaitAll(['no-future']);
    }

    public function testAwaitAllReturnsEmptyArrayForEmptyInput(): void
    {
        self::assertSame([], awaitAll([]));
    }

    public function testAwaitAllObservesLaterChildFailuresAfterFirstFailure(): void
    {
        $firstError = new \RuntimeException('first failure');
        $laterError = new \RuntimeException('later failure');
        $first = new Task(static function () use ($firstError): never {
            throw $firstError;
        });
        $later = new Task(static function () use ($laterError): never {
            Loop::get()->sleep(0.005);
            throw $laterError;
        });

        try {
            awaitAll([$first, $later]);
            self::fail('awaitAll should propagate the first child failure.');
        } catch (\RuntimeException $error) {
            self::assertSame($firstError, $error);
        }

        Loop::get()->run();

        self::assertSame(Task::FAILED, $later->getState());
        self::assertSame($laterError, $later->getError());
    }

    public function testAwaitAllWorksInsideAFiber(): void
    {
        $group = new Task(static function (): array {
            $first = new Task(static fn (): string => 'first');
            $second = new Task(static fn (): string => 'second');

            return awaitAll(['left' => $first, 'right' => $second]);
        });

        self::assertSame(['left' => 'first', 'right' => 'second'], $group->await());
    }

    public function testLoopAwaitAllObservesLaterChildFailuresAfterFirstFailure(): void
    {
        $firstError = new \RuntimeException('first failure');
        $laterError = new \RuntimeException('later failure');
        $first = new Task(static function () use ($firstError): never {
            throw $firstError;
        });
        $later = new Task(static function () use ($laterError): never {
            Loop::get()->sleep(0.005);
            throw $laterError;
        });

        try {
            loopAwaitAll($first, $later);
            self::fail('Loop awaitAll should propagate the first child failure.');
        } catch (\RuntimeException $error) {
            self::assertSame($firstError, $error);
        }

        Loop::get()->run();

        self::assertSame(Task::FAILED, $later->getState());
        self::assertSame($laterError, $later->getError());
    }

    public function testCancellingAwaitAllCallerDoesNotCancelChildTasks(): void
    {
        $child = new Task(static function (): string {
            Loop::get()->sleep(0.005);
            return 'child completed';
        });
        $groupCaller = new Task(static fn (): array => awaitAll([$child]));
        $canceller = new Task(static function () use ($groupCaller): void {
            Loop::get()->sleep(0.001);
            $groupCaller->cancel();
        });

        try {
            $groupCaller->await();
            self::fail('The group caller should have been cancelled.');
        } catch (\Omegaalfa\HttpClient\Loop\TaskCancelled) {
            self::assertSame(Task::CANCELLED, $groupCaller->getState());
        }

        $canceller->await();
        self::assertSame('child completed', $child->await());
        self::assertSame(Task::COMPLETED, $child->getState());
    }

    public function testAwaitAnyReturnsFirstSettledTaskWithKey(): void
    {
        $slow = new Task(static function (): string {
            Loop::get()->sleep(0.02);
            return 'slow';
        });
        $fast = new Task(static fn (): string => 'fast');

        $result = awaitAny([
            'slow' => $slow,
            'fast' => $fast,
        ]);

        self::assertSame('fast', $result['key']);
        self::assertSame('fast', $result['value']);
    }

    public function testAwaitAnyReturnsFirstSettledNotFirstInList(): void
    {
        $slow = new Task(static function (): string {
            Loop::get()->sleep(0.03);
            return 'slow';
        });
        $fast = new Task(static function (): string {
            Loop::get()->sleep(0.005);
            return 'fast';
        });

        $result = awaitAny(['slow' => $slow, 'fast' => $fast]);

        self::assertSame('fast', $result['key']);
        self::assertSame('fast', $result['value']);
    }

    public function testAwaitAnyResolvesFirstSettledTaskOutsideFiber(): void
    {
        $slow = new Task(static function (): string {
            Loop::get()->sleep(0.3);
            return 'slow';
        });
        $fast = new Task(static function (): string {
            Loop::get()->sleep(0.05);
            return 'fast';
        });

        $start = microtime(true);
        $result = awaitAny(['a' => $slow, 'b' => $fast]);
        $elapsed = microtime(true) - $start;

        self::assertSame('b', $result['key']);
        self::assertSame('fast', $result['value']);
        self::assertLessThan(0.25, $elapsed, 'awaitAny must return on the first settled future, not wait for the slowest.');
    }

    public function testRaceReturnsWinnerValue(): void
    {
        $f1 = new Task(static fn (): string => 'winner');
        $f2 = new Task(static function (): string {
            Loop::get()->sleep(0.02);
            return 'loser';
        });

        self::assertSame('winner', race([$f1, $f2]));
    }

    public function testAwaitSettledCollectsFulfilledAndRejectedTasks(): void
    {
        $ok = new Task(static fn (): int => 10);
        $fail = new Task(static function (): never {
            throw new \RuntimeException('boom');
        });

        $result = awaitSettled([
            'ok' => $ok,
            'fail' => $fail,
        ]);

        self::assertSame('fulfilled', $result['ok']['state']);
        self::assertArrayHasKey('value', $result['ok']);
        self::assertSame(10, $result['ok']['value']);
        self::assertSame('rejected', $result['fail']['state']);
        self::assertArrayHasKey('reason', $result['fail']);
        self::assertInstanceOf(\RuntimeException::class, $result['fail']['reason']);
    }
}
