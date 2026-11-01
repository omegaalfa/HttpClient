<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Loop;

use Omegaalfa\HttpClient\Loop\Loop;
use Omegaalfa\HttpClient\Loop\Task;
use Omegaalfa\HttpClient\Loop\Timer;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use PHPUnit\Framework\TestCase;
use WeakReference;
use RuntimeException;

final class TimerHeapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new ReflectionProperty(Loop::class, 'instance'))->setValue(null, null);
    }

    public function testTimersUseDeadlineThenInsertionSequence(): void
    {
        $timers = new Timer();
        $timers->schedule(20, 0, static function (): void {
        });
        $sameDeadlineFirst = $timers->schedule(10, 1, static function (): void {
        });
        $sameDeadlineSecond = $timers->schedule(10, 2, static function (): void {
        });
        $earlier = $timers->schedule(5, 3, static function (): void {
        });

        self::assertSame($earlier, $timers->extractNext());
        self::assertSame($sameDeadlineFirst, $timers->extractNext());
        self::assertSame($sameDeadlineSecond, $timers->extractNext());
        self::assertSame(20, $timers->peekNext()?->at);
    }

    public function testLegacyTupleAndCallbackRemovalMethodsRemainAvailable(): void
    {
        $timers = new Timer();
        $callback = static function (): void {
        };
        $timers->insert([10, 1, $callback]);
        $timers->insert([20, 2, $callback]);

        self::assertSame([10, 1, $callback], $timers->top());
        $timers->removeCallback($callback);

        self::assertTrue($timers->isEmpty());
        self::assertSame(0, count($timers));
    }

    public function testIndexedRemovalIsIdempotentAndReleasesCapturedObjects(): void
    {
        $timers = new Timer();
        $payload = (object) ['data' => str_repeat('x', 4096)];
        $weak = WeakReference::create($payload);
        $callback = static function () use ($payload): void {
        };
        $node = $timers->schedule(100, 1, $callback);
        unset($payload, $callback);

        self::assertTrue($timers->remove($node));
        self::assertFalse($timers->remove($node));
        gc_collect_cycles();

        self::assertNull($weak->get());
        self::assertFalse($timers->hasActiveTimers());
        self::assertNull($node->callback);
    }

    public function testExtractedTimerCannotBeRemovedOrRetainedAsPending(): void
    {
        $timers = new Timer();
        $node = $timers->schedule(1, 1, static function (): void {
        });

        self::assertSame($node, $timers->extractNext());
        self::assertFalse($timers->remove($node));
        self::assertTrue($timers->isEmpty());
        self::assertFalse($node->active);
    }

    #[DataProvider('timerCounts')]
    public function testConcurrentTimerWaitsCompleteExactlyOnce(int $count): void
    {
        $completed = 0;
        for ($i = 0; $i < $count; $i++) {
            new Task(static function () use (&$completed): void {
                Loop::get()->sleep(0.001);
                $completed++;
            });
        }

        Loop::get()->run();

        self::assertSame($count, $completed);
    }

    public static function timerCounts(): iterable
    {
        yield '100 timers' => [100];
        yield '500 timers' => [500];
        yield '1000 timers' => [1000];
    }

    public function testCancelDuringTimerDispatchInvalidatesThePendingWake(): void
    {
        $finallyCount = 0;
        $task = new Task(static function () use (&$finallyCount): void {
            try {
                Loop::get()->sleep(0);
            } finally {
                $finallyCount++;
            }
        });
        // This timer is inserted before the Task starts, so its equal/earlier
        // deadline callback cancels the Task before its wake node is extracted.
        Loop::get()->after(0, static fn () => $task->cancel());

        Loop::get()->run();

        self::assertSame(Task::CANCELLED, $task->getState());
        self::assertSame(1, $finallyCount);
    }

    public function testExpiredTaskCanBeCancelledWithoutASecondResume(): void
    {
        $completed = 0;
        $task = new Task(static function () use (&$completed): void {
            Loop::get()->sleep(0);
            $completed++;
        });

        self::assertNull($task->await());
        self::assertFalse($task->cancel());
        Loop::get()->run();

        self::assertSame(1, $completed);
        self::assertSame(Task::COMPLETED, $task->getState());
    }

    public function testThousandsOfCancelledTimerClosuresAreReleased(): void
    {
        $timers = new Timer();
        $nodes = [];
        $weakPayloads = [];
        for ($i = 0; $i < 2000; $i++) {
            $payload = (object) ['data' => str_repeat('p', 4096)];
            $weakPayloads[] = WeakReference::create($payload);
            $callback = static function () use ($payload): void {
            };
            $nodes[] = $timers->schedule(1_000_000 + $i, $i, $callback);
            unset($payload, $callback);
        }
        $allocatedWithTimers = memory_get_usage(false);

        foreach ($nodes as $node) {
            self::assertTrue($timers->remove($node));
        }
        unset($nodes, $node);
        gc_collect_cycles();

        self::assertFalse($timers->hasActiveTimers());
        foreach ($weakPayloads as $weakPayload) {
            self::assertNull($weakPayload->get());
        }
        self::assertLessThan($allocatedWithTimers, memory_get_usage(false));
    }

    public function testCancellingThousandsOfSleepingTasksCompactsWithoutDuplicateResumes(): void
    {
        $loop = Loop::get();
        $tasks = [];
        $finallyCount = 0;
        for ($i = 0; $i < 1000; $i++) {
            $tasks[] = new Task(static function () use (&$finallyCount): void {
                try {
                    Loop::get()->sleep(3600);
                } finally {
                    $finallyCount++;
                }
            });
        }
        $loop->defer(static function () use (&$tasks): void {
            foreach ($tasks as $task) {
                if (!$task->cancel()) {
                    throw new RuntimeException('A pending timer task was not cancellable.');
                }
            }
        });

        $loop->run();

        self::assertSame(1000, $finallyCount);
        self::assertSame(1000, count(array_filter($tasks, static fn (Task $task): bool => $task->getState() === Task::CANCELLED)));
        $timers = (new ReflectionProperty(Loop::class, 'timers'))->getValue($loop);
        self::assertTrue($timers->isEmpty());
    }

    public function testShutdownClearsThousandsOfPendingTaskTimersAndRunsFinally(): void
    {
        $loop = Loop::get();
        $tasks = [];
        $finallyCount = 0;
        for ($i = 0; $i < 1000; $i++) {
            $tasks[] = new Task(static function () use (&$finallyCount): void {
                try {
                    Loop::get()->sleep(3600);
                } finally {
                    $finallyCount++;
                }
            });
        }
        $loop->after(0, static fn () => $loop->shutdown());

        $loop->run();

        self::assertSame(Loop::SHUT_DOWN, $loop->getState());
        self::assertSame(1000, count(array_filter($tasks, static fn (Task $task): bool => $task->getState() === Task::CANCELLED)));
        self::assertGreaterThan(0, $finallyCount);
        self::assertLessThanOrEqual(256, $finallyCount);
    }
}
