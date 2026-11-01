<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Loop;

use Omegaalfa\HttpClient\Loop\Loop;
use Omegaalfa\HttpClient\Loop\Semaphore;
use Omegaalfa\HttpClient\Loop\SemaphoreClosedException;
use Omegaalfa\HttpClient\Loop\Task;
use Omegaalfa\HttpClient\Loop\TaskCancelled;
use ReflectionProperty;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SemaphoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new ReflectionProperty(Loop::class, 'instance'))->setValue(null, null);
    }

    public function testMaximumConcurrencyIsRespectedAndCapacityReleased(): void
    {
        $semaphore = new Semaphore(3);
        $active = $maximum = $completed = 0;
        $tasks = [];
        for ($i = 0; $i < 60; $i++) {
            $tasks[] = new Task(static function () use ($semaphore, &$active, &$maximum, &$completed): void {
                $semaphore->withPermit(static function () use (&$active, &$maximum, &$completed): void {
                    $maximum = max($maximum, ++$active);
                    Loop::get()->pause();
                    $active--;
                    $completed++;
                });
            });
        }
        Loop::get()->run();
        self::assertSame(3, $maximum);
        self::assertSame(60, $completed);
        self::assertSame(0, $semaphore->getActiveCount());
        self::assertSame(0, $semaphore->getWaitingCount());
        foreach ($tasks as $task) {
            $task->await();
        }
    }

    public function testWaitersReceiveSlotsInFifoOrder(): void
    {
        $semaphore = new Semaphore(1);
        $order = [];
        $tasks = [];
        $tasks[] = new Task(static function () use ($semaphore, &$order): void {
            $semaphore->withPermit(static function () use (&$order): void {
                $order[] = 'holder';
                Loop::get()->sleep(0.005);
            });
        });
        foreach (['a', 'b', 'c'] as $name) {
            $tasks[] = new Task(static function () use ($semaphore, &$order, $name): void {
                $semaphore->withPermit(static function () use (&$order, $name): void {
                    $order[] = $name;
                });
            });
        }
        Loop::get()->run();
        self::assertSame(['holder', 'a', 'b', 'c'], $order);
        foreach ($tasks as $task) {
            $task->await();
        }
    }

    public function testCancellationWhileWaitingRemovesWaiterWithoutLosingSlot(): void
    {
        $semaphore = new Semaphore(1);
        $holder = new Task(static function () use ($semaphore): void {
            $semaphore->withPermit(static fn () => Loop::get()->sleep(0.01));
        });
        $waiter = new Task(static fn () => $semaphore->withPermit(static fn () => self::fail('Canceled waiter must not run.')));
        Loop::get()->defer(static fn () => $waiter->cancel());
        Loop::get()->run();
        self::assertSame(Task::CANCELLED, $waiter->getState());
        self::assertSame(0, $semaphore->getWaitingCount());
        self::assertSame(0, $semaphore->getActiveCount());
        try {
            $waiter->await();
            self::fail('Expected cancellation.');
        } catch (TaskCancelled) {
        }
        $holder->await();
    }

    public function testThousandsOfCancelledWaitersAreDetached(): void
    {
        $semaphore = new Semaphore(1);
        $holder = new Task(static fn () => $semaphore->withPermit(static fn () => Loop::get()->sleep(0.01)));
        $waiters = [];
        for ($i = 0; $i < 1_000; $i++) {
            $waiters[] = new Task(static fn () => $semaphore->withPermit(static fn () => self::fail('Canceled waiter ran.')));
        }
        Loop::get()->defer(static function () use (&$waiters): void {
            foreach ($waiters as $waiter) {
                $waiter->cancel();
            }
        });
        Loop::get()->run();
        self::assertSame(0, $semaphore->getWaitingCount());
        self::assertSame(0, $semaphore->getActiveCount());
        foreach ($waiters as $waiter) {
            self::assertSame(Task::CANCELLED, $waiter->getState());
        }
        $holder->await();
    }

    public function testCancellationAfterAcquiringRunsFinallyAndTransfersSlot(): void
    {
        $semaphore = new Semaphore(1);
        $cleaned = false;
        $order = [];
        $first = new Task(static function () use ($semaphore, &$cleaned): void {
            try {
                $semaphore->withPermit(static fn () => Loop::get()->sleep(60));
            } finally {
                $cleaned = true;
            }
        });
        $second = new Task(static function () use ($semaphore, &$order): void {
            $semaphore->withPermit(static function () use (&$order): void {
                $order[] = 'second';
            });
        });
        Loop::get()->defer(static fn () => $first->cancel());
        Loop::get()->run();
        self::assertTrue($cleaned);
        self::assertSame(['second'], $order);
        self::assertSame(0, $semaphore->getActiveCount());
        try {
            $first->await();
            self::fail('Expected cancellation.');
        } catch (TaskCancelled) {
        }
        $second->await();
    }

    public function testCancellationAfterGrantButBeforeQueuedResumeTransfersSlotOnce(): void
    {
        $semaphore = new Semaphore(1);
        $order = [];
        $firstWaiter = null;
        $holder = new Task(static function () use ($semaphore): void {
            $semaphore->withPermit(static function (): void {
                Loop::get()->sleep(0.005);
                // The permit is transferred as this operation returns. stop()
                // makes run() leave before the queued waiter wake is consumed.
                Loop::get()->stop();
            });
        });
        $firstWaiter = new Task(static function () use ($semaphore, &$order): void {
            $semaphore->withPermit(static function () use (&$order): void {
                $order[] = 'first';
            });
        });
        $secondWaiter = new Task(static function () use ($semaphore, &$order): void {
            $semaphore->withPermit(static function () use (&$order): void {
                $order[] = 'second';
            });
        });
        Loop::get()->run();
        self::assertSame(Task::SUSPENDED, $firstWaiter->getState());
        self::assertTrue($firstWaiter->cancel());
        Loop::get()->run();

        self::assertSame(['second'], $order);
        self::assertSame(Task::CANCELLED, $firstWaiter->getState());
        self::assertSame(Task::COMPLETED, $secondWaiter->getState());
        self::assertSame(0, $semaphore->getActiveCount());
        self::assertSame(0, $semaphore->getWaitingCount());
        try {
            $firstWaiter->await();
            self::fail('Expected cancellation.');
        } catch (TaskCancelled) {
        }
        $holder->await();
        $secondWaiter->await();
    }

    public function testExceptionReleasesPermitForNextOperation(): void
    {
        $semaphore = new Semaphore(1);
        $nextRan = false;
        $failed = new Task(static fn () => $semaphore->withPermit(static function (): never {
            throw new RuntimeException('operation failed');
        }));
        $next = new Task(static function () use ($semaphore, &$nextRan): void {
            $semaphore->withPermit(static function () use (&$nextRan): void {
                $nextRan = true;
            });
        });
        $observer = new Task(static function () use ($failed): void {
            try {
                $failed->await();
                self::fail('Expected operation exception.');
            } catch (RuntimeException $error) {
                self::assertSame('operation failed', $error->getMessage());
            }
        });
        $observer->await();
        $next->await();
        self::assertTrue($nextRan);
        self::assertSame(0, $semaphore->getActiveCount());
    }

    public function testCloseFailsQueuedTasksAndIsIdempotent(): void
    {
        $semaphore = new Semaphore(1);
        $holder = new Task(static fn () => $semaphore->withPermit(static fn () => Loop::get()->sleep(0.01)));
        $waiter = new Task(static fn () => $semaphore->withPermit(static fn () => 'unexpected'));
        Loop::get()->defer(static fn () => $semaphore->close());
        Loop::get()->defer(static fn () => $semaphore->close());
        $observer = new Task(static function () use ($waiter): void {
            try {
                $waiter->await();
                self::fail('Expected closed semaphore failure.');
            } catch (SemaphoreClosedException) {
            }
        });
        $observer->await();
        self::assertTrue($semaphore->isClosed());
        self::assertSame(0, $semaphore->getWaitingCount());
        $holder->await();
    }

    public function testShutdownCancelsActiveAndQueuedSemaphoreOperations(): void
    {
        $loop = Loop::get();
        $semaphore = new Semaphore(1);
        $finally = 0;
        $tasks = [];
        for ($i = 0; $i < 5; $i++) {
            $tasks[] = new Task(static function () use ($semaphore, &$finally): void {
                try {
                    $semaphore->withPermit(static fn () => Loop::get()->sleep(60));
                } finally {
                    $finally++;
                }
            });
        }
        $loop->defer(static fn () => $loop->shutdown());
        try {
            $loop->run();
        } catch (Throwable) {
            self::fail('Shutdown should not lose semaphore cleanup.');
        }
        self::assertSame(Loop::SHUT_DOWN, $loop->getState());
        self::assertSame(5, $finally);
        self::assertSame(0, $semaphore->getActiveCount());
        self::assertSame(0, $semaphore->getWaitingCount());
        foreach ($tasks as $task) {
            self::assertTrue($task->isDone());
            try {
                $task->await();
            } catch (TaskCancelled) {
            }
        }
    }

    public function testThousandsOfQueuedOperationsCompleteOnceWithoutRetainedWaiters(): void
    {
        $semaphore = new Semaphore(25);
        $active = $maximum = $completed = 0;
        $tasks = [];
        for ($i = 0; $i < 2_000; $i++) {
            $tasks[] = new Task(static function () use ($semaphore, &$active, &$maximum, &$completed): void {
                $semaphore->withPermit(static function () use (&$active, &$maximum, &$completed): void {
                    $maximum = max($maximum, ++$active);
                    Loop::get()->pause();
                    $active--;
                    $completed++;
                });
            });
        }
        Loop::get()->run();
        self::assertSame(2_000, $completed);
        self::assertSame(25, $maximum);
        self::assertSame(0, $semaphore->getWaitingCount());
        self::assertSame(0, $semaphore->getActiveCount());
        foreach ($tasks as $task) {
            $task->await();
        }
    }
}
