<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Loop;

use InvalidArgumentException;
use LogicException;

/** FIFO asynchronous concurrency limiter for operations running in Tasks. */
final class Semaphore
{
    private int $active = 0;
    private int $nextWaiterId = 0;
    /** @var array<int, WaitHandle> Insertion-ordered FIFO; keyed removal is O(1). */
    private array $waiters = [];
    private bool $closed = false;

    public function __construct(public readonly int $capacity)
    {
        if ($capacity < 1) {
            throw new InvalidArgumentException('Semaphore capacity must be at least one.');
        }
    }

    /** Run an operation while holding one slot; release is guaranteed by finally. */
    public function withPermit(callable $operation): mixed
    {
        $this->acquire();
        try {
            return $operation();
        } finally {
            $this->release();
        }
    }

    /** Close once and fail every task that is still queued for a slot. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $waiters = $this->waiters;
        $this->waiters = [];
        foreach ($waiters as $handle) {
            $handle->semaphoreQueueId = null;
            Loop::get()->failWait($handle, new SemaphoreClosedException('Semaphore is closed.'));
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function getActiveCount(): int
    {
        return $this->active;
    }

    public function getWaitingCount(): int
    {
        return count($this->waiters);
    }

    /** @internal Called by Loop when a Task suspends waiting for capacity. */
    public function enqueue(WaitHandle $handle): void
    {
        if ($this->closed) {
            throw new SemaphoreClosedException('Semaphore is closed.');
        }
        $id = $this->nextWaiterId++;
        $handle->semaphore = $this;
        $handle->semaphoreQueueId = $id;
        $this->waiters[$id] = $handle;
    }

    /** @internal Remove a canceled waiter or return its reserved slot. */
    public function removeWaiter(WaitHandle $handle): void
    {
        if ($handle->semaphoreQueueId !== null) {
            unset($this->waiters[$handle->semaphoreQueueId]);
            $handle->semaphoreQueueId = null;
            if ($this->waiters === []) {
                $this->waiters = [];
            }
        }
        if ($handle->semaphoreGranted) {
            $handle->semaphoreGranted = false;
            $handle->semaphore = null;
            $this->grantNextOrRelease();
        }
    }

    /** @internal Mark the handoff consumed before Loop removes the wait handle. */
    public function consumeGrant(WaitHandle $handle): void
    {
        if (!$handle->semaphoreGranted || $handle->semaphore !== $this) {
            throw new LogicException('Semaphore waiter resumed without a granted slot.');
        }
        $handle->semaphoreGranted = false;
        $handle->semaphoreQueueId = null;
        $handle->semaphore = null;
    }

    private function acquire(): void
    {
        if ($this->closed) {
            throw new SemaphoreClosedException('Semaphore is closed.');
        }
        if ($this->active < $this->capacity && $this->waiters === []) {
            $this->active++;
            return;
        }

        Loop::get()->suspendOnSemaphore($this);
    }

    private function release(): void
    {
        if ($this->active < 1) {
            throw new LogicException('Cannot release a semaphore slot that is not held.');
        }
        if (!$this->closed && $this->grantNext()) {
            return;
        }
        $this->active--;
    }

    private function grantNextOrRelease(): void
    {
        if (!$this->closed && $this->grantNext()) {
            return;
        }
        $this->active--;
    }

    private function grantNext(): bool
    {
        while ($this->waiters !== []) {
            $id = array_key_first($this->waiters);
            $handle = $this->waiters[$id];
            unset($this->waiters[$id]);
            if ($this->waiters === []) {
                $this->waiters = [];
            }
            $handle->semaphoreQueueId = null;
            if (!$handle->active) {
                continue;
            }
            $handle->semaphoreGranted = true;
            Loop::get()->scheduleWake($handle);
            return true;
        }
        return false;
    }
}
