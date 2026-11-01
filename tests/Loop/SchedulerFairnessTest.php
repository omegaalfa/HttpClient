<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Loop;

use Omegaalfa\HttpClient\Loop\Loop;
use Omegaalfa\HttpClient\Loop\IoDriver;
use Omegaalfa\HttpClient\Loop\IoEvent;
use Omegaalfa\HttpClient\Loop\Task;
use ReflectionProperty;
use PHPUnit\Framework\TestCase;

final class SchedulerFairnessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new ReflectionProperty(Loop::class, 'instance'))->setValue(null, null);
    }

    public function testThousandsOfPausesMakeProgressAndFinish(): void
    {
        $loop = Loop::get();
        $iterations = 0;
        $timerRan = false;
        $task = new Task(static function () use (&$iterations): void {
            for ($i = 0; $i < 5_000; $i++) {
                $iterations++;
                Loop::get()->pause();
            }
        });
        $loop->after(0.0, static function () use (&$timerRan): void {
            $timerRan = true;
        });

        $loop->run();

        self::assertSame(5_000, $iterations);
        self::assertTrue($timerRan);
        self::assertSame(Task::COMPLETED, $task->getState());
    }

    public function testCallbackFloodCannotDelayDueTimerOrReadyIoUntilQueueDrains(): void
    {
        $loop = Loop::get();
        [$reader, $writer] = $this->socketPair();
        $total = 10_000;
        $processed = 0;
        $order = [];
        $callbacksAtTimer = null;
        $callbacksAtIo = null;
        $ioTask = new Task(static function () use ($reader, &$callbacksAtIo, &$processed): void {
            Loop::get()->waitReadable($reader);
            $callbacksAtIo = $processed;
            fread($reader, 1);
        });
        $loop->defer(static fn () => $loop->stop());
        $loop->run();

        for ($i = 0; $i < $total; $i++) {
            $loop->defer(static function () use (&$processed, &$order, $i): void {
                $processed++;
                $order[] = $i;
            });
        }
        $loop->after(0.0, static function () use (&$callbacksAtTimer, &$processed): void {
            $callbacksAtTimer = $processed;
        });
        fwrite($writer, 'x');
        $loop->run();

        self::assertLessThan($total, $callbacksAtTimer);
        self::assertLessThan($total, $callbacksAtIo);
        self::assertSame($total, $processed);
        self::assertSame(range(0, $total - 1), $order);
        self::assertSame(Task::COMPLETED, $ioTask->getState());
        fclose($reader);
        fclose($writer);
    }

    public function testContinuouslyRequeuedCallbackDoesNotStarveTimerOrIo(): void
    {
        $loop = Loop::get();
        [$reader, $writer] = $this->socketPair();
        $timerRan = false;
        $callbackCount = 0;
        $callbacksAtIo = null;
        $ioTask = new Task(static function () use ($reader, &$callbacksAtIo, &$callbackCount): void {
            Loop::get()->waitReadable($reader);
            $callbacksAtIo = $callbackCount;
            fread($reader, 1);
        });
        $loop->defer(static fn () => $loop->stop());
        $loop->run();

        $callback = null;
        $callback = static function () use (&$callback, &$callbackCount, &$timerRan, $loop): void {
            $callbackCount++;
            if (!$timerRan) {
                $loop->defer($callback);
            }
        };
        $loop->defer($callback);
        $loop->after(0.0, static function () use (&$timerRan): void {
            $timerRan = true;
        });
        fwrite($writer, 'c');

        $loop->run();

        self::assertTrue($timerRan);
        self::assertGreaterThan(0, $callbacksAtIo);
        self::assertLessThan(1_000, $callbacksAtIo);
        self::assertSame($callbacksAtIo + 1, $callbackCount);
        self::assertSame(Task::COMPLETED, $ioTask->getState());
        fclose($reader);
        fclose($writer);
    }

    public function testContinuouslyRescheduledCallbacksAndTimersDoNotStarveIo(): void
    {
        $loop = Loop::get();
        [$reader, $writer] = $this->socketPair();
        $timerCount = 0;
        $timersAtIo = null;
        $ioTask = new Task(static function () use ($reader, &$timersAtIo, &$timerCount): void {
            Loop::get()->waitReadable($reader);
            $timersAtIo = $timerCount;
            fread($reader, 1);
        });
        $loop->defer(static fn () => $loop->stop());
        $loop->run();

        $timer = null;
        $timer = static function () use (&$timer, &$timerCount, $loop): void {
            $timerCount++;
            if ($timerCount < 5_000) {
                $loop->after(0.0, $timer);
            }
        };
        $loop->after(0.0, $timer);
        fwrite($writer, 'y');
        $loop->run();

        self::assertLessThan(5_000, $timersAtIo);
        self::assertSame(5_000, $timerCount);
        self::assertSame(Task::COMPLETED, $ioTask->getState());
        fclose($reader);
        fclose($writer);
    }

    public function testManySimultaneouslyReadyStreamsAreDispatchedAcrossCycles(): void
    {
        $driver = new AlwaysReadyTestDriver();
        $loop = Loop::get($driver);
        $streamCount = 300;
        $streams = [];
        $tasks = [];
        $completed = 0;
        $completedAtTimer = null;
        for ($i = 0; $i < $streamCount; $i++) {
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, 'zz');
            rewind($stream);
            $streams[] = $stream;
            $tasks[] = new Task(static function () use ($loop, $stream, &$completed): void {
                for ($i = 0; $i < 2; $i++) {
                    $loop->waitReadable($stream);
                    fread($stream, 1);
                    $completed++;
                }
            });
        }
        $loop->defer(static fn () => $loop->stop());
        $loop->run();
        $driver->enabled = true;
        $loop->after(0.0, static function () use (&$completedAtTimer, &$completed): void {
            $completedAtTimer = $completed;
        });
        $loop->run();

        self::assertLessThan($streamCount * 2, $completedAtTimer);
        self::assertSame($streamCount * 2, $completed);
        foreach ($streams as $stream) {
            fclose($stream);
        }
    }

    public function testShutdownInvalidatesTheUndispatchedRemainderOfAnIoBatch(): void
    {
        $driver = new AlwaysReadyTestDriver();
        $loop = Loop::get($driver);
        $streamCount = 300;
        $streams = [];
        $tasks = [];
        $resumed = 0;
        for ($i = 0; $i < $streamCount; $i++) {
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, 'x');
            rewind($stream);
            $streams[] = $stream;
            if ($i === 0) {
                $tasks[] = new Task(static function () use ($loop, $stream): void {
                    $loop->waitReadable($stream);
                    $loop->shutdown();
                });
            } else {
                $tasks[] = new Task(static function () use ($loop, $stream, &$resumed): void {
                    $loop->waitReadable($stream);
                    $resumed++;
                });
            }
        }
        $loop->defer(static fn () => $loop->stop());
        $loop->run();
        $driver->enabled = true;

        $loop->run();

        self::assertSame(0, $resumed);
        self::assertSame(Task::COMPLETED, $tasks[0]->getState());
        foreach (array_slice($tasks, 1) as $task) {
            self::assertSame(Task::CANCELLED, $task->getState());
        }
        self::assertSame(Loop::SHUT_DOWN, $loop->getState());
        foreach ($streams as $stream) {
            fclose($stream);
        }
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

final class AlwaysReadyTestDriver implements IoDriver
{
    /** @var array<int, array{generation: int, stream: mixed}> */
    private array $readers = [];
    public bool $enabled = false;

    public function registerRead(int $streamId, int $generation, $stream): void
    {
        $this->readers[$streamId] = ['generation' => $generation, 'stream' => $stream];
    }

    public function registerWrite(int $streamId, int $generation, $stream): void
    {
    }

    public function removeRead(int $streamId, int $generation): void
    {
        if (($this->readers[$streamId]['generation'] ?? null) === $generation) {
            unset($this->readers[$streamId]);
        }
    }

    public function removeWrite(int $streamId, int $generation): void
    {
    }

    public function wait(?int $timeoutNs): array
    {
        if (!$this->enabled) {
            return [];
        }
        $events = [];
        foreach ($this->readers as $id => $watch) {
            $events[] = new IoEvent($id, $watch['generation'], 'read');
        }
        return $events;
    }

    public function clear(): void
    {
        $this->readers = [];
    }
}
