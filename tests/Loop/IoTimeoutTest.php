<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Loop;

use Omegaalfa\HttpClient\Loop\IoDriver;
use Omegaalfa\HttpClient\Loop\IoEvent;
use Omegaalfa\HttpClient\Loop\IoTimeoutException;
use Omegaalfa\HttpClient\Loop\Loop;
use Omegaalfa\HttpClient\Loop\Task;
use ReflectionProperty;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

final class IoTimeoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new ReflectionProperty(Loop::class, 'instance'))->setValue(null, null);
    }

    public function testReadWaitTimesOutAndReleasesItsIoAndTimerRegistrations(): void
    {
        $loop = Loop::get();
        [$reader, $writer] = $this->socketPair();
        $task = new Task(static function () use ($reader): string {
            try {
                Loop::get()->waitReadable($reader, 0.01);
            } catch (IoTimeoutException) {
                return 'timeout';
            }
            return 'ready';
        });

        $loop->run();

        self::assertSame('timeout', $task->await());
        self::assertSame(Task::COMPLETED, $task->getState());
        $this->assertNoWaitRegistrations($loop);
        self::assertIsResource($reader);
        self::assertIsResource($writer);
        fclose($reader);
        fclose($writer);
    }

    public function testWriteWaitTimesOutWhenSocketHasNoWriteCapacity(): void
    {
        $loop = Loop::get();
        [$reader, $writer] = $this->socketPair();
        $chunk = str_repeat('x', 8192);
        while (true) {
            $written = fwrite($writer, $chunk);
            if ($written === false || $written === 0) {
                break;
            }
        }

        $task = new Task(static function () use ($writer): string {
            try {
                Loop::get()->waitWritable($writer, 0.01);
            } catch (IoTimeoutException) {
                return 'timeout';
            }
            return 'writable';
        });
        $loop->run();

        self::assertSame('timeout', $task->await());
        $this->assertNoWaitRegistrations($loop);
        self::assertIsResource($reader);
        self::assertIsResource($writer);
        fclose($reader);
        fclose($writer);
    }

    public function testReadinessBeforeDeadlineInvalidatesTimeoutAndPreservesNoTimeoutCall(): void
    {
        $loop = Loop::get();
        [$reader, $writer] = $this->socketPair();
        [$legacyReader, $legacyWriter] = $this->socketPair();
        fwrite($writer, 'a');
        fwrite($legacyWriter, 'b');
        $timed = new Task(static function () use ($reader): string {
            Loop::get()->waitReadable($reader, 1.0);
            return (string) fread($reader, 1);
        });
        $legacy = new Task(static function () use ($legacyReader): string {
            Loop::get()->waitReadable($legacyReader);
            return (string) fread($legacyReader, 1);
        });

        $loop->run();

        self::assertSame('a', $timed->await());
        self::assertSame('b', $legacy->await());
        $this->assertNoWaitRegistrations($loop);
        foreach ([$reader, $writer, $legacyReader, $legacyWriter] as $stream) {
            fclose($stream);
        }
    }

    public function testIoEventReturnedAtTimeoutBoundaryWinsExactlyOnce(): void
    {
        $driver = new class () implements IoDriver {
            /** @var array<int, array{generation:int, stream:mixed}> */
            public array $readers = [];
            public bool $emitted = false;
            public int $removals = 0;

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
                    $this->removals++;
                }
            }
            public function removeWrite(int $streamId, int $generation): void
            {
            }
            public function wait(?int $timeoutNs): array
            {
                if ($this->emitted) {
                    return [];
                }
                if ($timeoutNs !== null && $timeoutNs > 0) {
                    time_nanosleep(intdiv($timeoutNs, 1_000_000_000), $timeoutNs % 1_000_000_000);
                }
                $this->emitted = true;
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
        };

        $loop = Loop::get($driver);
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        $resumes = 0;
        $task = new Task(static function () use ($stream, &$resumes): string {
            try {
                Loop::get()->waitReadable($stream, 0.005);
            } catch (IoTimeoutException) {
                return 'timeout';
            }
            $resumes++;
            return 'ready';
        });

        $loop->run();

        self::assertSame('ready', $task->await());
        self::assertSame(1, $resumes);
        self::assertSame(1, $driver->removals);
        $this->assertNoWaitRegistrations($loop);
        fclose($stream);
    }

    public function testZeroTimeoutExpiresImmediatelyAndInvalidValuesAreRejected(): void
    {
        $loop = Loop::get();
        [$reader, $writer] = $this->socketPair();
        $zero = new Task(static function () use ($reader): string {
            try {
                Loop::get()->waitReadable($reader, 0);
            } catch (IoTimeoutException) {
                return 'timeout';
            }
            return 'ready';
        });
        $loop->run();
        self::assertSame('timeout', $zero->await());

        foreach ([-0.1, INF, NAN] as $invalid) {
            $task = new Task(static function () use ($reader, $invalid): bool {
                try {
                    Loop::get()->waitReadable($reader, $invalid);
                } catch (InvalidArgumentException) {
                    return true;
                }
                return false;
            });
            self::assertTrue($task->await());
            self::assertSame(Task::COMPLETED, $task->getState());
        }
        $this->assertNoWaitRegistrations($loop);
        fclose($reader);
        fclose($writer);
    }

    public function testCancellationBeforeTimeoutRemovesBothRegistrations(): void
    {
        $loop = Loop::get();
        [$reader, $writer] = $this->socketPair();
        $task = new Task(static fn () => $loop->waitReadable($reader, 10.0));
        $loop->defer(static fn () => $loop->stop());
        $loop->run();

        self::assertSame(Task::SUSPENDED, $task->getState());
        self::assertTrue($task->cancel());
        $this->assertNoWaitRegistrations($loop);
        $loop->run();

        self::assertSame(Task::CANCELLED, $task->getState());
        self::assertIsResource($reader);
        self::assertIsResource($writer);
        fclose($reader);
        fclose($writer);
    }

    public function testShutdownDuringTimedIoWaitClearsTimeoutAndLeavesStreamOpen(): void
    {
        $loop = Loop::get();
        [$reader, $writer] = $this->socketPair();
        $task = new Task(static fn () => $loop->waitReadable($reader, 60.0));
        $loop->defer(static fn () => $loop->stop());
        $loop->run();

        self::assertSame(1, $this->timerCount($loop));
        $loop->shutdown();

        self::assertSame(Task::CANCELLED, $task->getState());
        self::assertSame(Loop::SHUT_DOWN, $loop->getState());
        $this->assertNoWaitRegistrations($loop);
        self::assertIsResource($reader);
        self::assertIsResource($writer);
        fclose($reader);
        fclose($writer);
    }

    public function testDifferentDeadlinesExpireIndependentlyInDeadlineOrder(): void
    {
        $loop = Loop::get();
        [$earlyReader, $earlyWriter] = $this->socketPair();
        [$lateReader, $lateWriter] = $this->socketPair();
        $order = [];
        $early = new Task(static function () use ($earlyReader, &$order): void {
            try {
                Loop::get()->waitReadable($earlyReader, 0.005);
            } catch (IoTimeoutException) {
                $order[] = 'early';
            }
        });
        $late = new Task(static function () use ($lateReader, &$order): void {
            try {
                Loop::get()->waitReadable($lateReader, 0.03);
            } catch (IoTimeoutException) {
                $order[] = 'late';
            }
        });

        $loop->run();

        self::assertSame(['early', 'late'], $order);
        self::assertSame(Task::COMPLETED, $early->getState());
        self::assertSame(Task::COMPLETED, $late->getState());
        $this->assertNoWaitRegistrations($loop);
        foreach ([$earlyReader, $earlyWriter, $lateReader, $lateWriter] as $stream) {
            fclose($stream);
        }
    }

    private function assertNoWaitRegistrations(Loop $loop): void
    {
        self::assertSame(0, $this->timerCount($loop));
        foreach (['readers', 'writers'] as $propertyName) {
            $property = new ReflectionProperty(Loop::class, $propertyName);
            self::assertSame([], $property->getValue($loop));
        }
        $waits = new ReflectionProperty(Loop::class, 'fiberWaits');
        self::assertCount(0, $waits->getValue($loop));
        $driverProperty = new ReflectionProperty(Loop::class, 'ioDriver');
        $driver = $driverProperty->getValue($loop);
        foreach (['readers', 'writers'] as $propertyName) {
            if (!property_exists($driver, $propertyName)) {
                continue;
            }
            $property = new ReflectionProperty($driver, $propertyName);
            self::assertSame([], $property->getValue($driver));
        }
    }

    private function timerCount(Loop $loop): int
    {
        $property = new ReflectionProperty(Loop::class, 'timers');
        return $property->getValue($loop)->count();
    }

    private function socketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        foreach ($pair as $stream) {
            stream_set_blocking($stream, false);
        }
        return $pair;
    }
}
