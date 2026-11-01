<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Loop;

use Omegaalfa\HttpClient\Loop\Loop;
use ReflectionProperty;
use PHPUnit\Framework\TestCase;

final class LoopShutdownTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new ReflectionProperty(Loop::class, 'instance'))->setValue(null, null);
    }

    public function testStopReturnsAfterCurrentCallbackAndLoopCanRunAgain(): void
    {
        $loop = Loop::get();
        $events = [];
        $loop->defer(function () use ($loop, &$events): void {
            $events[] = 'stop';
            $loop->stop();
        });
        $loop->defer(function () use (&$events): void {
            $events[] = 'next';
        });

        $loop->run();
        self::assertSame(['stop'], $events);

        // stop is scoped to one run; queued work remains schedulable.
        $loop->run();
        self::assertSame(['stop', 'next'], $events);
    }

    public function testShutdownAliasStopsTimerDrivenRun(): void
    {
        $loop = Loop::get();
        $started = hrtime(true);
        $loop->after(0.02, static function () use ($loop): void {
            $loop->shutdown();
        });

        $loop->run();

        self::assertGreaterThanOrEqual(15_000_000, hrtime(true) - $started);
        self::assertLessThan(500_000_000, hrtime(true) - $started);
    }

    public function testIdleTimerWaitUsesNegligibleProcessCpu(): void
    {
        if (!function_exists('getrusage')) {
            self::markTestSkipped('getrusage() is unavailable on this platform.');
        }

        $loop = Loop::get();
        $before = getrusage();
        $loop->after(0.2, static function (): void {
        });
        $started = hrtime(true);
        $loop->run();
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;
        $after = getrusage();
        $cpu = ($after['ru_utime.tv_sec'] - $before['ru_utime.tv_sec'])
            + ($after['ru_utime.tv_usec'] - $before['ru_utime.tv_usec']) / 1_000_000;

        self::assertGreaterThanOrEqual(0.18, $elapsed);
        self::assertLessThan(0.05, $cpu, 'Idle timer wait consumed too much user CPU.');
    }
}
