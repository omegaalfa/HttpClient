<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Http;

use Omegaalfa\FiberEventLoop\FiberEventLoop;
use Omegaalfa\HttpClient\Http\ConcurrentExecutor;
use PHPUnit\Framework\TestCase;

final class ConcurrentExecutorTest extends TestCase
{
    public function testAllResolvesAllFutures(): void
    {
        $loop = new FiberEventLoop();
        $executor = new ConcurrentExecutor($loop);

        $future = $executor->all([
            'a' => $loop->async(static fn (): int => 1),
            'b' => $loop->async(static fn (): int => 2),
        ]);

        self::assertSame(['a' => 1, 'b' => 2], $future->await());
    }

    public function testNamedHandlesFutureAndScalarReturnValues(): void
    {
        $loop = new FiberEventLoop();
        $executor = new ConcurrentExecutor($loop);

        $future = $executor->named([
            'future' => static fn () => $loop->async(static fn (): string => 'ok'),
            'scalar' => static fn (): string => 'done',
        ]);

        self::assertSame(['future' => 'ok', 'scalar' => 'done'], $future->await());
    }

    public function testAnyReturnsFirstSettledFuture(): void
    {
        $loop = new FiberEventLoop();
        $executor = new ConcurrentExecutor($loop);

        $fast = $loop->async(static function (): string {
            return 'fast';
        });
        $slow = $loop->async(function () use ($loop): string {
            $loop->sleep(0.05);
            return 'slow';
        });

        $result = $executor->any(['a' => $fast, 'b' => $slow])->await();
        self::assertSame('a', $result['key']);
        self::assertSame('fast', $result['value']);
    }

    public function testRaceReturnsFirstWinnerValue(): void
    {
        $loop = new FiberEventLoop();
        $executor = new ConcurrentExecutor($loop);

        $fast = $loop->async(static function (): int {
            return 1;
        });
        $slow = $loop->async(function () use ($loop): int {
            $loop->sleep(0.05);
            return 2;
        });

        self::assertSame(1, $executor->race([$fast, $slow])->await());
    }

    public function testSettledCollectsBothStates(): void
    {
        $loop = new FiberEventLoop();
        $executor = new ConcurrentExecutor($loop);

        $ok = $loop->async(static fn (): string => 'ok');
        $fail = $loop->async(static function (): never {
            throw new \RuntimeException('err');
        });

        $result = $executor->settled(['ok' => $ok, 'fail' => $fail])->await();

        self::assertSame('fulfilled', $result['ok']['state']);
        self::assertSame('ok', $result['ok']['value']);
        self::assertSame('rejected', $result['fail']['state']);
        self::assertInstanceOf(\RuntimeException::class, $result['fail']['reason']);
    }
}
