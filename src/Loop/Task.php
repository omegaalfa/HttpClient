<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Loop;

use Fiber;
use LogicException;
use Throwable;

final class Task
{
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const SUSPENDED = 'suspended';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    private static int $nextId = 1;
    private readonly int $id;
    private string $state = self::QUEUED;
    private mixed $result = null;
    private ?Throwable $error = null;
    private bool $cancellationRequested = false;
    private bool $cancellationDelivered = false;
    private bool $observed = false;
    /** @var list<WaitHandle> */
    private array $waiters = [];
    /** @var Fiber<null, mixed, void, mixed> */
    private readonly Fiber $fiber;

    public function __construct(callable $fn)
    {
        $this->id = self::$nextId++;
        $this->fiber = new Fiber(function () use ($fn): void {
            try {
                $this->result = $fn();
                $this->state = self::COMPLETED;
            } catch (TaskCancelled $e) {
                $this->error = $e;
                $this->state = self::CANCELLED;
            } catch (Throwable $e) {
                $this->error = $e;
                $this->state = self::FAILED;
            }

            Loop::get()->taskCompleted($this, $this->state === self::FAILED ? $this->error : null);
            Loop::get()->completeWaiters($this);
        });

        Loop::get()->registerTask($this, $this->fiber);
        Loop::get()->startTask($this);
    }

    public function start(): void
    {
        if ($this->state === self::CANCELLED) {
            return;
        }
        if ($this->state !== self::QUEUED || $this->fiber->isStarted()) {
            throw new LogicException("Task #{$this->id} cannot start from state {$this->state}.");
        }

        $this->state = self::RUNNING;
        $this->fiber->start();
        $this->markSuspendedIfNeeded();
    }

    public function resume(): void
    {
        if ($this->state !== self::SUSPENDED || !$this->fiber->isSuspended()) {
            throw new LogicException("Task #{$this->id} cannot resume from state {$this->state}.");
        }

        $this->state = self::RUNNING;
        $this->fiber->resume();
        $this->markSuspendedIfNeeded();
    }

    private function markSuspendedIfNeeded(): void
    {
        if ($this->state === self::RUNNING && $this->fiber->isSuspended()) {
            $this->state = self::SUSPENDED;
        }
    }

    public function await(): mixed
    {
        $this->markObserved();
        if (!$this->isDone()) {
            $current = Fiber::getCurrent();
            if ($current === null) {
                // Top-level code: drive the loop until this task completes.
                Loop::get()->run(fn () => $this->isDone());
                if (!in_array($this->getState(), [self::COMPLETED, self::FAILED, self::CANCELLED], true)) {
                    throw new LogicException("Task #{$this->id} did not complete while the loop was running.");
                }
            } else {
                Loop::get()->suspendOnTask($this);
            }
        }

        $this->markObserved();
        return $this->error === null ? $this->result : throw $this->error;
    }

    /** @internal Mark a failure as handled by a group operation before it awaits children one by one. */
    public function markObserved(): void
    {
        $this->observed = true;
        Loop::get()->taskObserved($this);
    }

    /** Request cooperative cancellation. Returns false if already terminal/requested. */
    public function cancel(): bool
    {
        if ($this->isDone() || $this->cancellationRequested) {
            return false;
        }

        $this->cancellationRequested = true;
        if ($this->state === self::QUEUED) {
            $this->state = self::CANCELLED;
            $this->error = new TaskCancelled("Task #{$this->id} was cancelled before it started.");
            Loop::get()->taskCompleted($this, null);
            return true;
        }

        if ($this->state === self::SUSPENDED) {
            Loop::get()->cancelTask($this);
        }
        // A running Fiber observes this request at its next loop suspension point.
        return true;
    }

    /** @internal */
    public function deliverCancellation(): void
    {
        if (!$this->cancellationRequested || $this->cancellationDelivered || $this->state !== self::SUSPENDED) {
            return;
        }
        if (!$this->fiber->isSuspended()) {
            return;
        }

        $this->cancellationDelivered = true;
        $this->state = self::RUNNING;
        $this->fiber->throw(new TaskCancelled("Task #{$this->id} was cancelled."));
        $this->markSuspendedIfNeeded();
    }

    /** @internal Deliver a driver error at the suspended I/O operation. */
    public function throwFromWait(Throwable $error): void
    {
        if ($this->state !== self::SUSPENDED || !$this->fiber->isSuspended()) {
            throw new LogicException("Task #{$this->id} cannot receive an I/O error from state {$this->state}.");
        }
        $this->state = self::RUNNING;
        $this->fiber->throw($error);
        $this->markSuspendedIfNeeded();
    }

    /** @internal Throw at the next cooperative checkpoint in the running Fiber. */
    public function throwIfCancellationRequested(): void
    {
        if ($this->cancellationRequested && !$this->cancellationDelivered) {
            $this->cancellationDelivered = true;
            throw new TaskCancelled("Task #{$this->id} was cancelled.");
        }
    }

    /** @internal */
    public function addWaiter(WaitHandle $waiter): void
    {
        $this->waiters[] = $waiter;
    }

    /** @internal */
    public function removeWaiter(WaitHandle $waiter): void
    {
        $this->waiters = array_values(array_filter(
            $this->waiters,
            static fn (WaitHandle $candidate): bool => $candidate !== $waiter,
        ));
    }

    /**
     * @return list<WaitHandle>
     * @internal
     */
    public function takeWaiters(): array
    {
        $waiters = $this->waiters;
        $this->waiters = [];
        return $waiters;
    }

    /**
     * @return Fiber<null, mixed, void, mixed>
     * @internal
     */
    public function getFiber(): Fiber
    {
        return $this->fiber;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function isDone(): bool
    {
        return $this->state === self::COMPLETED || $this->state === self::FAILED || $this->state === self::CANCELLED;
    }

    public function getError(): ?Throwable
    {
        return $this->error;
    }

    /** @internal */
    public function isObserved(): bool
    {
        return $this->observed;
    }

    /** @internal Cleanup code may keep using cooperative waits during shutdown. */
    public function isCancellationCleanup(): bool
    {
        return $this->cancellationRequested && $this->cancellationDelivered && !$this->isDone();
    }
}
