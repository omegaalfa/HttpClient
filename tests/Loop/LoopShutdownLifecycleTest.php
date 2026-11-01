<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Loop;

use Omegaalfa\HttpClient\Loop\Loop;
use Omegaalfa\HttpClient\Loop\Task;
use Omegaalfa\HttpClient\Loop\TaskCancelled;
use ReflectionProperty;
use PHPUnit\Framework\TestCase;
use LogicException;

final class LoopShutdownLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new ReflectionProperty(Loop::class, 'instance'))->setValue(null, null);
    }

    public function testIdleShutdownIsTerminalAndIdempotent(): void
    {
        $loop = Loop::get();
        $loop->shutdown();
        $loop->shutdown();

        self::assertSame(Loop::SHUT_DOWN, $loop->getState());
        $this->expectException(LogicException::class);
        $loop->run();
    }

    public function testNewSchedulingIsRejectedAsSoonAsShutdownStarts(): void
    {
        $loop = Loop::get();
        $rejectedDefer = false;
        $rejectedTimer = false;
        $loop->defer(static function () use ($loop, &$rejectedDefer, &$rejectedTimer): void {
            $loop->shutdown();
            try {
                $loop->defer(static function (): void {
                });
            } catch (LogicException) {
                $rejectedDefer = true;
            }
            try {
                $loop->after(0.0, static function (): void {
                });
            } catch (LogicException) {
                $rejectedTimer = true;
            }
        });

        $loop->run();

        self::assertTrue($rejectedDefer);
        self::assertTrue($rejectedTimer);
        self::assertSame(Loop::SHUT_DOWN, $loop->getState());
    }

    public function testShutdownCancelsQueuedTasksAndDropsUserCallbacks(): void
    {
        $loop = Loop::get();
        $started = false;
        $callbackRan = false;
        $task = new Task(static function () use (&$started): void {
            $started = true;
        });
        $loop->defer(static function () use (&$callbackRan): void {
            $callbackRan = true;
        });

        $loop->shutdown();

        self::assertFalse($started);
        self::assertFalse($callbackRan);
        self::assertSame(Task::CANCELLED, $task->getState());
        self::assertSame(Loop::SHUT_DOWN, $loop->getState());
    }

    public function testShutdownCancelsTimerWaitAndRunsFinally(): void
    {
        $loop = Loop::get();
        $finallyRan = false;
        $task = new Task(static function () use (&$finallyRan): void {
            try {
                $loop = Loop::get();
                $loop->sleep(60.0);
            } finally {
                $finallyRan = true;
            }
        });
        $loop->defer(static fn () => $loop->stop());
        $loop->run();
        self::assertSame(Task::SUSPENDED, $task->getState());

        $started = hrtime(true);
        $loop->shutdown();

        self::assertLessThan(1_000_000_000, hrtime(true) - $started);
        self::assertTrue($finallyRan);
        self::assertSame(Task::CANCELLED, $task->getState());
    }

    public function testShutdownRemovesIoWaitAndLeavesUserStreamsOpen(): void
    {
        $loop = Loop::get();
        [$reader, $writer] = $this->socketPair();
        $readTask = new Task(static fn () => Loop::get()->waitReadable($reader));
        $writeTask = new Task(static fn () => Loop::get()->waitWritable($writer));
        $loop->defer(static fn () => $loop->stop());
        $loop->run();
        $loop->shutdown();

        self::assertSame(Task::CANCELLED, $readTask->getState());
        self::assertSame(Task::CANCELLED, $writeTask->getState());
        self::assertIsResource($reader);
        self::assertIsResource($writer);
        self::assertSame(1, fwrite($writer, 'x'));
        self::assertSame('x', fread($reader, 1));
        fclose($reader);
        fclose($writer);
    }

    public function testShutdownCancelsBothAwaiterAndAwaitedTaskWithoutDeadlock(): void
    {
        $loop = Loop::get();
        $child = new Task(static function (): void {
            Loop::get()->sleep(60.0);
        });
        $parent = new Task(static function () use ($child): void {
            $child->await();
        });
        $loop->defer(static fn () => $loop->stop());
        $loop->run();

        $loop->shutdown();

        self::assertSame(Task::CANCELLED, $child->getState());
        self::assertSame(Task::CANCELLED, $parent->getState());
        self::assertSame(Loop::SHUT_DOWN, $loop->getState());
    }

    public function testShutdownInsideFiberCancelsAtNextCheckpointAndAllowsAsyncFinally(): void
    {
        $loop = Loop::get();
        $continuedAfterCall = false;
        $finallyStarted = false;
        $finallyCompleted = false;
        $siblingFinallyRan = false;
        $sibling = new Task(static function () use (&$siblingFinallyRan): void {
            try {
                Loop::get()->sleep(60.0);
            } finally {
                $siblingFinallyRan = true;
            }
        });
        $task = new Task(static function () use ($loop, &$continuedAfterCall, &$finallyStarted, &$finallyCompleted): void {
            try {
                $loop->shutdown();
                $continuedAfterCall = true;
                $loop->sleep(60.0); // Delivers the pending cancellation.
            } finally {
                $finallyStarted = true;
                $loop->sleep(0.005); // Cleanup waits are allowed to finish.
                $finallyCompleted = true;
            }
        });

        try {
            $task->await();
            self::fail('The task should observe cooperative cancellation.');
        } catch (TaskCancelled) {
            self::assertSame(Task::CANCELLED, $task->getState());
        }

        self::assertTrue($continuedAfterCall);
        self::assertTrue($finallyStarted);
        self::assertTrue($finallyCompleted);
        self::assertTrue($siblingFinallyRan);
        self::assertSame(Task::CANCELLED, $sibling->getState());
        self::assertSame(Loop::SHUT_DOWN, $loop->getState());
    }

    public function testShutdownFromCallbackInvalidatesRemainingCallbacksAndTimers(): void
    {
        $loop = Loop::get();
        $callbackRan = false;
        $timerRan = false;
        $loop->defer(static fn () => $loop->shutdown());
        $loop->defer(static function () use (&$callbackRan): void {
            $callbackRan = true;
        });
        $loop->after(0.0, static function () use (&$timerRan): void {
            $timerRan = true;
        });

        $loop->run();

        self::assertFalse($callbackRan);
        self::assertFalse($timerRan);
        self::assertSame(Loop::SHUT_DOWN, $loop->getState());
    }

    public function testShutdownFromTimerInvalidatesOtherPendingTimers(): void
    {
        $loop = Loop::get();
        $laterTimerRan = false;
        $loop->after(0.0, static fn () => $loop->shutdown());
        $loop->after(0.0, static function () use (&$laterTimerRan): void {
            $laterTimerRan = true;
        });

        $loop->run();

        self::assertFalse($laterTimerRan);
        self::assertSame(Loop::SHUT_DOWN, $loop->getState());
    }

    public function testShutdownSurfacesFailureFromCleanupInsteadOfDroppingIt(): void
    {
        $loop = Loop::get();
        $task = new Task(static function (): void {
            try {
                Loop::get()->sleep(60.0);
            } finally {
                throw new \RuntimeException('cleanup failed');
            }
        });
        $loop->defer(static fn () => $loop->stop());
        $loop->run();

        try {
            $loop->shutdown();
            self::fail('Shutdown must report cleanup failures.');
        } catch (LogicException $e) {
            self::assertSame('cleanup failed', $e->getPrevious()?->getMessage());
        }

        self::assertSame(Task::FAILED, $task->getState());
        self::assertSame(Loop::SHUT_DOWN, $loop->getState());
    }

    private function socketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        stream_set_blocking($pair[0], false);
        stream_set_blocking($pair[1], false);
        return $pair;
    }
}
