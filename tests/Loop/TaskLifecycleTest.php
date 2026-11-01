<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Loop;

use Omegaalfa\HttpClient\Loop\Loop;
use Omegaalfa\HttpClient\Loop\Task;
use ReflectionProperty;
use PHPUnit\Framework\TestCase;
use LogicException;
use Fiber;

final class TaskLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new ReflectionProperty(Loop::class, 'instance'))->setValue(null, null);
    }

    public function testTaskMovesThroughQueuedSuspendedAndCompletedExactlyOnce(): void
    {
        $loop = Loop::get();
        $runs = 0;
        $task = new Task(function () use (&$runs): int {
            $runs++;
            Loop::get()->pause();
            $runs++;
            return $runs;
        });

        self::assertSame(Task::QUEUED, $task->getState());
        $loop->defer(static fn () => $loop->stop());
        $loop->run();
        self::assertSame(Task::SUSPENDED, $task->getState());
        $loop->run();
        self::assertSame(Task::COMPLETED, $task->getState());
        self::assertSame(2, $task->await());
        self::assertSame(2, $runs);
    }

    public function testStopPreservesSuspendedFiberForTheNextRun(): void
    {
        $loop = Loop::get();
        $task = new Task(static function (): string {
            Loop::get()->pause();
            return 'resumed';
        });
        $loop->defer(static fn () => $loop->stop());

        $loop->run();
        self::assertSame(Task::SUSPENDED, $task->getState());

        $loop->run();
        self::assertSame(Task::COMPLETED, $task->getState());
        self::assertSame('resumed', $task->await());
    }

    public function testNestedRunIsRejectedWithoutCorruptingOuterRun(): void
    {
        $loop = Loop::get();
        $task = new Task(static function () use ($loop): string {
            try {
                $loop->run();
            } catch (LogicException $e) {
                self::assertStringContainsString('already running', $e->getMessage());
            }
            return 'ok';
        });

        self::assertSame('ok', $task->await());
        self::assertSame(Task::COMPLETED, $task->getState());
    }

    public function testUnobservedFailureIsReportedAndRemainsAwaitable(): void
    {
        $task = new Task(static function (): never {
            throw new \RuntimeException('task failure');
        });

        try {
            Loop::get()->run();
            self::fail('Expected an unobserved task failure diagnostic.');
        } catch (LogicException $e) {
            self::assertSame('task failure', $e->getPrevious()?->getMessage());
        }

        self::assertSame(Task::FAILED, $task->getState());
        self::assertInstanceOf(\RuntimeException::class, $task->getError());
        try {
            $task->await();
            self::fail('Await must rethrow the task error.');
        } catch (\RuntimeException $e) {
            self::assertSame('task failure', $e->getMessage());
        }
    }

    public function testSuspendedFiberWithoutWakeSourceProducesDeadlockDiagnostic(): void
    {
        $task = new Task(static function (): void {
            Fiber::suspend(); // Deliberately bypass the loop's scheduling primitives.
        });

        try {
            Loop::get()->run();
            self::fail('Expected a deadlock diagnostic for the stranded Fiber.');
        } catch (LogicException $e) {
            self::assertStringContainsString('suspended task(s)', $e->getMessage());
            self::assertStringContainsString('#' . $task->getId(), $e->getMessage());
        }

        self::assertSame(Task::SUSPENDED, $task->getState());
    }
}
