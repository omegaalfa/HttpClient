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

    public function testAwaitAnyReturnsFirstSettledNotFirstInList(): void
    {
        $loop = new FiberEventLoop();

        // Nenhum assenta nos primeiros ticks baratos: o vencedor precisa ser o
        // primeiro a assentar, não o primeiro da lista.
        $slow = $loop->async(static function () use ($loop): string {
            for ($i = 0; $i < 40_000; $i++) {
                $loop->next();
            }

            return 'slow';
        });

        $fast = $loop->async(static function () use ($loop): string {
            for ($i = 0; $i < 20_000; $i++) {
                $loop->next();
            }

            return 'fast';
        });

        $result = await($loop->async(static fn (): array => awaitAny(['slow' => $slow, 'fast' => $fast])));

        self::assertSame('fast', $result['key']);
        self::assertSame('fast', $result['value']);
    }

    public function testAwaitAnyResolvesFirstSettledFutureOutsideFiber(): void
    {
        $loop = new FiberEventLoop();

        // Espera no padrão do cliente (timer + suspensão via next()), como o
        // polling de socket faz durante I/O real.
        $slow = $loop->async(static function () use ($loop): string {
            $done = false;
            $loop->after(static function () use (&$done): void {
                $done = true;
            }, 0.3);

            while (!$done) {
                $loop->next();
            }

            return 'slow';
        });

        $fast = $loop->async(static function () use ($loop): string {
            $done = false;
            $loop->after(static function () use (&$done): void {
                $done = true;
            }, 0.05);

            while (!$done) {
                $loop->next();
            }

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
        self::assertArrayHasKey('value', $result['ok']);
        self::assertSame(10, $result['ok']['value']);
        self::assertSame('rejected', $result['fail']['state']);
        self::assertArrayHasKey('reason', $result['fail']);
        self::assertInstanceOf(\RuntimeException::class, $result['fail']['reason']);
    }
}
