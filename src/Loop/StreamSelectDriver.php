<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Loop;

use InvalidArgumentException;
use Throwable;

final class StreamSelectDriver implements IoDriver
{
    /** @var array<int, array{stream: resource, generation: int}> */
    private array $readers = [];
    /** @var array<int, array{stream: resource, generation: int}> */
    private array $writers = [];

    public function registerRead(int $streamId, int $generation, $stream): void
    {
        $this->assertStream($stream);
        $this->readers[$streamId] = ['stream' => $stream, 'generation' => $generation];
    }

    public function registerWrite(int $streamId, int $generation, $stream): void
    {
        $this->assertStream($stream);
        $this->writers[$streamId] = ['stream' => $stream, 'generation' => $generation];
    }

    public function removeRead(int $streamId, int $generation): void
    {
        if (($this->readers[$streamId]['generation'] ?? null) === $generation) {
            unset($this->readers[$streamId]);
        }
    }

    public function removeWrite(int $streamId, int $generation): void
    {
        if (($this->writers[$streamId]['generation'] ?? null) === $generation) {
            unset($this->writers[$streamId]);
        }
    }

    public function wait(?int $timeoutNs): array
    {
        $events = $this->removeInvalidStreams();
        if ($events !== []) {
            return $events;
        }

        $read = [];
        foreach ($this->readers as $watch) {
            $read[] = $watch['stream'];
        }
        $write = [];
        foreach ($this->writers as $watch) {
            $write[] = $watch['stream'];
        }

        if ($read === [] && $write === []) {
            if ($timeoutNs !== null && $timeoutNs > 0) {
                time_nanosleep(intdiv($timeoutNs, 1_000_000_000), $timeoutNs % 1_000_000_000);
            }
            return [];
        }

        if ($timeoutNs === null) {
            $seconds = null;
            $microseconds = null;
        } else {
            $totalMicroseconds = (int)ceil($timeoutNs / 1000);
            $seconds = intdiv($totalMicroseconds, 1_000_000);
            $microseconds = $totalMicroseconds % 1_000_000;
        }

        $warning = null;
        $except = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            if (($severity & E_WARNING) !== 0) {
                $warning = $message;
                return true;
            }
            return false;
        });
        try {
            $selected = stream_select($read, $write, $except, $seconds, $microseconds);
        } catch (Throwable $error) {
            throw new StreamSelectException('stream_select() failed: ' . $error->getMessage(), 0, $error);
        } finally {
            restore_error_handler();
        }

        if ($selected === false) {
            if ($this->wasInterrupted($warning)) {
                return [];
            }
            throw new StreamSelectException($warning ?? 'stream_select() failed without a warning.');
        }

        foreach ($read as $stream) {
            $id = (int)$stream;
            if (isset($this->readers[$id])) {
                $events[] = new IoEvent($id, $this->readers[$id]['generation'], 'read');
            }
        }
        foreach ($write as $stream) {
            $id = (int)$stream;
            if (isset($this->writers[$id])) {
                $events[] = new IoEvent($id, $this->writers[$id]['generation'], 'write');
            }
        }

        return $events;
    }

    public function clear(): void
    {
        $this->readers = [];
        $this->writers = [];
    }

    private function assertStream(mixed $stream): void
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('I/O waits require an open stream resource.');
        }
    }

    /** @return list<IoEvent> */
    private function removeInvalidStreams(): array
    {
        $events = [];
        foreach ($this->readers as $id => $watch) {
            if (!is_resource($watch['stream']) || get_resource_type($watch['stream']) !== 'stream') {
                unset($this->readers[$id]);
                $events[] = new IoEvent($id, $watch['generation'], 'read', new StreamClosedException('Stream closed while waiting for readability.'));
            }
        }
        foreach ($this->writers as $id => $watch) {
            if (!is_resource($watch['stream']) || get_resource_type($watch['stream']) !== 'stream') {
                unset($this->writers[$id]);
                $events[] = new IoEvent($id, $watch['generation'], 'write', new StreamClosedException('Stream closed while waiting for writability.'));
            }
        }
        return $events;
    }

    private function wasInterrupted(?string $warning): bool
    {
        return $warning !== null && preg_match('/interrupted(?: system call| by signal)/i', $warning) === 1;
    }
}
