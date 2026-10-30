<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Http;

interface SocketReadinessInterface
{
    /** Suspend the active Fiber until the stream is ready, or its deadline passes. */
    public function awaitStream(mixed $stream, bool $read, bool $write, int $deadlineNs): void;
}
