<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Http;

use Omegaalfa\HttpClient\Loop\Loop;
use Omegaalfa\HttpClient\Loop\Task;
use Omegaalfa\HttpClient\Http\ConcurrentExecutor;
use PHPUnit\Framework\TestCase;

final class ConcurrentExecutorTest extends TestCase
{
    public function testAllResolvesAllFutures(): void
    {
        $loop = Loop::get();
        $executor = new ConcurrentExecutor($loop);

        $future = $executor->all([
            'a' => new Task(static fn (): int => 1),
            'b' => new Task(static fn (): int => 2),
        ]);

        self::assertSame(['a' => 1, 'b' => 2], $future->await());
    }

    public function testNamedHandlesFutureAndScalarReturnValues(): void
    {
        $loop = Loop::get();
        $executor = new ConcurrentExecutor($loop);

        $future = $executor->named([
            'future' => static fn () => new Task(static fn (): string => 'ok'),
            'scalar' => static fn (): string => 'done',
        ]);

        self::assertSame(['future' => 'ok', 'scalar' => 'done'], $future->await());
    }

    public function testAnyReturnsFirstSettledFuture(): void
    {
        $loop = Loop::get();
        $executor = new ConcurrentExecutor($loop);

        $fast = new Task(static function (): string {
            return 'fast';
        });
        $slow = new Task(static function (): string {
            Loop::get()->sleep(0.05);
            return 'slow';
        });

        $result = $executor->any(['a' => $fast, 'b' => $slow])->await();
        self::assertSame('a', $result['key']);
        self::assertSame('fast', $result['value']);
    }

    public function testRaceReturnsFirstWinnerValue(): void
    {
        $loop = Loop::get();
        $executor = new ConcurrentExecutor($loop);

        $fast = new Task(static function (): int {
            return 1;
        });
        $slow = new Task(static function (): int {
            Loop::get()->sleep(0.05);
            return 2;
        });

        self::assertSame(1, $executor->race([$fast, $slow])->await());
    }

    public function testSettledCollectsBothStates(): void
    {
        $loop = Loop::get();
        $executor = new ConcurrentExecutor($loop);

        $ok = new Task(static fn (): string => 'ok');
        $fail = new Task(static function (): never {
            throw new \RuntimeException('err');
        });

        $result = $executor->settled(['ok' => $ok, 'fail' => $fail])->await();

        self::assertSame('fulfilled', $result['ok']['state']);
        self::assertSame('ok', $result['ok']['value']);
        self::assertSame('rejected', $result['fail']['state']);
        self::assertInstanceOf(\RuntimeException::class, $result['fail']['reason']);
    }
}
