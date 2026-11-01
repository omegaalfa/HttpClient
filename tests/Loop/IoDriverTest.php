<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Loop;

use Omegaalfa\HttpClient\Loop\IoDriver;
use Omegaalfa\HttpClient\Loop\IoEvent;
use Omegaalfa\HttpClient\Loop\Loop;
use Omegaalfa\HttpClient\Loop\StreamClosedException;
use Omegaalfa\HttpClient\Loop\StreamSelectException;
use Omegaalfa\HttpClient\Loop\Task;
use ReflectionProperty;
use PHPUnit\Framework\TestCase;

final class IoDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new ReflectionProperty(Loop::class, 'instance'))->setValue(null, null);
    }

    public function testMultiplexesSeveralNonBlockingReadableAndWritableStreams(): void
    {
        $loop = Loop::get();
        [$readA, $writeA] = $this->socketPair();
        [$readB, $writeB] = $this->socketPair();
        $readerA = new Task(static function () use ($readA): string {
            Loop::get()->waitReadable($readA);
            return (string) fread($readA, 1);
        });
        $readerB = new Task(static function () use ($readB): string {
            Loop::get()->waitReadable($readB);
            return (string) fread($readB, 1);
        });
        $writer = new Task(static function () use ($writeA, $writeB): void {
            Loop::get()->sleep(0.01);
            Loop::get()->waitWritable($writeA);
            fwrite($writeA, 'a');
            Loop::get()->waitWritable($writeB);
            fwrite($writeB, 'b');
        });

        $loop->run();

        self::assertSame('a', $readerA->await());
        self::assertSame('b', $readerB->await());
        self::assertSame(Task::COMPLETED, $writer->getState());
        foreach ([$readA, $writeA, $readB, $writeB] as $stream) {
            fclose($stream);
        }
    }

    public function testClosedStreamWhileWaitingFailsOnlyItsTask(): void
    {
        $loop = Loop::get();
        [$reader, $writer] = $this->socketPair();
        $task = new Task(static function () use ($reader): string {
            try {
                Loop::get()->waitReadable($reader);
            } catch (StreamClosedException $error) {
                return $error->getMessage();
            }
            return 'unexpected readiness';
        });
        $loop->defer(static fn () => $loop->stop());
        $loop->run();
        fclose($reader);

        self::assertStringContainsString('closed while waiting for readability', $task->await());
        self::assertSame(Task::COMPLETED, $task->getState());
        fclose($writer);
    }

    public function testRemovedRegistrationCannotBeResumedByAlreadyReturnedEvent(): void
    {
        $driver = new class () implements IoDriver {
            /** @var array<int, array{generation: int, stream: mixed}> */
            public array $reads = [];

            public function registerRead(int $streamId, int $generation, $stream): void
            {
                $this->reads[$streamId] = ['generation' => $generation, 'stream' => $stream];
            }

            public function registerWrite(int $streamId, int $generation, $stream): void
            {
            }
            public function removeRead(int $streamId, int $generation): void
            {
                if (($this->reads[$streamId]['generation'] ?? null) === $generation) {
                    unset($this->reads[$streamId]);
                }
            }
            public function removeWrite(int $streamId, int $generation): void
            {
            }
            public function wait(?int $timeoutNs): array
            {
                $events = [];
                foreach ($this->reads as $id => $watch) {
                    $events[] = new IoEvent($id, $watch['generation'], 'read');
                }
                return $events;
            }
            public function clear(): void
            {
                $this->reads = [];
            }
        };

        $loop = Loop::get($driver);
        [$streamA, $peerA] = $this->socketPair();
        [$streamB, $peerB] = $this->socketPair();
        $continuedB = false;
        $taskB = null;
        $taskA = new Task(static function () use ($streamA, &$taskB): void {
            Loop::get()->waitReadable($streamA);
            $taskB->cancel();
        });
        $taskB = new Task(static function () use ($streamB, &$continuedB): void {
            Loop::get()->waitReadable($streamB);
            $continuedB = true;
        });

        $loop->run();

        self::assertSame(Task::COMPLETED, $taskA->getState());
        self::assertSame(Task::CANCELLED, $taskB->getState());
        self::assertFalse($continuedB);
        self::assertSame([], $driver->reads);
        foreach ([$streamA, $peerA, $streamB, $peerB] as $stream) {
            fclose($stream);
        }
    }

    public function testBlockedIoWaitUsesLittleCpuUntilTimerCancelsTask(): void
    {
        if (!function_exists('getrusage')) {
            self::markTestSkipped('getrusage() is unavailable on this platform.');
        }
        $loop = Loop::get();
        [$reader, $writer] = $this->socketPair();
        $task = new Task(static fn () => Loop::get()->waitReadable($reader));
        $loop->after(0.15, static fn () => $task->cancel());
        $before = getrusage();
        $started = hrtime(true);
        $loop->run();
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;
        $after = getrusage();
        $cpu = ($after['ru_utime.tv_sec'] - $before['ru_utime.tv_sec'])
            + ($after['ru_utime.tv_usec'] - $before['ru_utime.tv_usec']) / 1_000_000;

        self::assertGreaterThanOrEqual(0.12, $elapsed);
        self::assertLessThan(0.05, $cpu);
        self::assertSame(Task::CANCELLED, $task->getState());
        fclose($reader);
        fclose($writer);
    }

    public function testDriverFailureIsPropagatedInsteadOfTreatedAsNoReadiness(): void
    {
        $driverError = new StreamSelectException('selector failed');
        $driver = new class ($driverError) implements IoDriver {
            public function __construct(private StreamSelectException $error)
            {
            }
            public function registerRead(int $streamId, int $generation, $stream): void
            {
            }
            public function registerWrite(int $streamId, int $generation, $stream): void
            {
            }
            public function removeRead(int $streamId, int $generation): void
            {
            }
            public function removeWrite(int $streamId, int $generation): void
            {
            }
            public function wait(?int $timeoutNs): array
            {
                throw $this->error;
            }
            public function clear(): void
            {
            }
        };

        $loop = Loop::get($driver);
        [$reader, $writer] = $this->socketPair();
        $task = new Task(static fn () => Loop::get()->waitReadable($reader));
        try {
            $loop->run();
            self::fail('Expected the selector failure to propagate.');
        } catch (StreamSelectException $error) {
            self::assertSame($driverError, $error);
        }

        self::assertSame(Task::SUSPENDED, $task->getState());
        $task->cancel();
        $loop->run();
        self::assertSame(Task::CANCELLED, $task->getState());
        fclose($reader);
        fclose($writer);
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
