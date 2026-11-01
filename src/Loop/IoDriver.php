<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Loop;

interface IoDriver
{
    /** @param resource $stream */
    public function registerRead(int $streamId, int $generation, $stream): void;

    /** @param resource $stream */
    public function registerWrite(int $streamId, int $generation, $stream): void;

    public function removeRead(int $streamId, int $generation): void;

    public function removeWrite(int $streamId, int $generation): void;

    /**
     * Wait for readiness or the supplied monotonic timeout.
     *
     * @return list<IoEvent>
     */
    public function wait(?int $timeoutNs): array;

    /** Drop driver references without closing externally owned streams. */
    public function clear(): void;
}
