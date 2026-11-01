<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Loop;

use Omegaalfa\HttpClient\Loop\Loop;
use Omegaalfa\HttpClient\Loop\Task;
use Omegaalfa\HttpClient\Loop\TaskCancelled;
use ReflectionProperty;
use PHPUnit\Framework\TestCase;

final class TaskCancellationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new ReflectionProperty(Loop::class, 'instance'))->setValue(null, null);
    }

    public function testCancelBeforeStartDoesNotStartFiberAndIsAwaitable(): void
    {
        $started = false;
        $task = new Task(static function () use (&$started): void {
            $started = true;
        });

        self::assertTrue($task->cancel());
        self::assertFalse($task->cancel());
        Loop::get()->run();

        self::assertFalse($started);
        self::assertSame(Task::CANCELLED, $task->getState());
        try {
            $task->await();
            self::fail('Awaiting a cancelled task must throw TaskCancelled.');
        } catch (TaskCancelled) {
            self::assertTrue($task->isDone());
        }
    }

    public function testCancelDuringSleepRunsFinallyAndRemovesTimer(): void
    {
        $finallyRan = false;
        $task = new Task(static function () use (&$finallyRan): void {
            try {
                Loop::get()->sleep(60.0);
            } finally {
                $finallyRan = true;
            }
        });
        Loop::get()->defer(static fn () => $task->cancel());

        $started = hrtime(true);
        Loop::get()->run();

        self::assertLessThan(1_000_000_000, hrtime(true) - $started);
        self::assertTrue($finallyRan);
        self::assertSame(Task::CANCELLED, $task->getState());
        self::assertInstanceOf(TaskCancelled::class, $task->getError());
    }

    public function testCancelDuringReadRemovesRegistrationAndAllowsAnotherWaiter(): void
    {
        [$reader, $writer] = $this->socketPair();
        $task = new Task(static fn () => Loop::get()->waitReadable($reader));
        Loop::get()->defer(static fn () => $task->cancel());
        Loop::get()->run();

        self::assertSame(Task::CANCELLED, $task->getState());
        $next = new Task(static function () use ($reader): string {
            Loop::get()->waitReadable($reader);
            return (string) fread($reader, 1);
        });
        fwrite($writer, 'r');
        self::assertSame('r', $next->await());

        fclose($reader);
        fclose($writer);
    }

    public function testCancelDuringWriteRemovesRegistrationAndAllowsAnotherWaiter(): void
    {
        [$reader, $writer] = $this->socketPair();
        $task = new Task(static fn () => Loop::get()->waitWritable($writer));
        Loop::get()->defer(static fn () => $task->cancel());
        Loop::get()->run();

        self::assertSame(Task::CANCELLED, $task->getState());
        $next = new Task(static function () use ($writer): string {
            Loop::get()->waitWritable($writer);
            return 'writable';
        });
        self::assertSame('writable', $next->await());

        fclose($reader);
        fclose($writer);
    }

    public function testCancelDuringAwaitDetachesOnlyTheAwaitingTask(): void
    {
        $child = new Task(static function (): string {
            Loop::get()->pause();
            return 'child completed';
        });
        $parent = new Task(static fn (): string => $child->await());
        Loop::get()->defer(static fn () => $parent->cancel());
        Loop::get()->run();

        self::assertSame(Task::CANCELLED, $parent->getState());
        self::assertSame(Task::COMPLETED, $child->getState());
        self::assertSame('child completed', $child->await());
    }

    public function testCancelCompletedTaskIsNoOp(): void
    {
        $task = new Task(static fn (): int => 42);
        self::assertSame(42, $task->await());
        self::assertFalse($task->cancel());
        self::assertSame(Task::COMPLETED, $task->getState());
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
