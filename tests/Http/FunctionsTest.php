<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Http;

use InvalidArgumentException;
use Omegaalfa\FiberEventLoop\FiberEventLoop;
use Omegaalfa\FiberEventLoop\Future;
use PHPUnit\Framework\TestCase;
use function Omegaalfa\HttpClient\Http\await;
use function Omegaalfa\HttpClient\Http\awaitAll;
use function Omegaalfa\HttpClient\Http\awaitAny;
use function Omegaalfa\HttpClient\Http\awaitSettled;
use function Omegaalfa\HttpClient\Http\race;

final class FunctionsTest extends TestCase
{
    public function testAwaitResolvesFutureValue(): void
    {
        $loop = new FiberEventLoop();
        $future = $loop->async(static fn (): int => 42);

        self::assertSame(42, await($future));
    }

    public function testAwaitAllReturnsResolvedResultsKeepingKeys(): void
    {
        $loop = new FiberEventLoop();
        $f1 = $loop->async(static fn (): string => 'a');
        $f2 = $loop->async(static fn (): string => 'b');

        self::assertSame(['first' => 'a', 'second' => 'b'], awaitAll([
            'first' => $f1,
            'second' => $f2,
        ]));
    }

    public function testAwaitAllThrowsOnInvalidInput(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('awaitAll expects only Future instances');

        awaitAll(['no-future']);
    }

    public function testAwaitAllReturnsEmptyArrayForEmptyInput(): void
    {
        self::assertSame([], awaitAll([]));
    }

    public function testAwaitAnyReturnsFirstSettledFutureWithKey(): void
    {
        $loop = new FiberEventLoop();

        $slow = new Future($loop);
        $fast = new Future($loop);
        $fast->resolve('fast');

        $result = awaitAny([
            'slow' => $slow,
            'fast' => $fast,
        ]);

        self::assertSame('fast', $result['key']);
        self::assertSame('fast', $result['value']);
    }

    public function testRaceReturnsWinnerValue(): void
    {
        $loop = new FiberEventLoop();

        $f1 = new Future($loop);
        $f2 = new Future($loop);
        $f1->resolve('winner');

        self::assertSame('winner', race([$f1, $f2]));
    }

    public function testAwaitSettledCollectsFulfilledAndRejectedFutures(): void
    {
        $loop = new FiberEventLoop();

        $ok = $loop->async(static fn (): int => 10);
        $fail = $loop->async(static function (): never {
            throw new \RuntimeException('boom');
        });

        $result = awaitSettled([
            'ok' => $ok,
            'fail' => $fail,
        ]);

        self::assertSame('fulfilled', $result['ok']['state']);
        self::assertSame(10, $result['ok']['value']);
        self::assertSame('rejected', $result['fail']['state']);
        self::assertInstanceOf(\RuntimeException::class, $result['fail']['reason']);
    }
}
