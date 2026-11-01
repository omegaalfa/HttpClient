<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Loop;

use Closure;
use Fiber;
use LogicException;
use SplQueue;
use SplObjectStorage;
use WeakMap;
use Throwable;

final class Loop
{
    private const CALLBACKS_PER_TICK = 256;
    private const TIMERS_PER_TICK = 256;
    private const IO_EVENTS_PER_TICK = 256;
    public const ACTIVE = 'active';
    public const SHUTTING_DOWN = 'shutting_down';
    public const SHUT_DOWN = 'shut_down';

    private static ?self $instance = null;
    /** @var SplQueue<Closure> */
    private SplQueue $ready;
    private Timer $timers;
    private int $timerSeq = 0;
    /** @var array<int, WaitHandle> */
    private array $readers = [];
    /** @var array<int, WaitHandle> */
    private array $writers = [];
    private IoDriver $ioDriver;
    private int $watchGeneration = 0;
    /** @var list<IoEvent> */
    private array $pendingIoEvents = [];
    private int $pendingIoOffset = 0;
    private bool $stopRequested = false;
    private bool $running = false;
    private string $state = self::ACTIVE;
    /** @var SplObjectStorage<Task, null> */
    private SplObjectStorage $tasks;
    /** @var WeakMap<Fiber<null, mixed, void, mixed>, Task> */
    private WeakMap $fiberTasks;
    /** @var WeakMap<Fiber<null, mixed, void, mixed>, WaitHandle> */
    private WeakMap $fiberWaits;
    /** @var array<int, array{Task, Throwable}> */
    private array $unobservedErrors = [];

    public static function get(?IoDriver $driver = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($driver ?? new StreamSelectDriver());
        } elseif ($driver !== null) {
            throw new LogicException('The event loop driver is already configured.');
        }
        return self::$instance;
    }

    private function __construct(IoDriver $driver)
    {
        $this->ioDriver = $driver;
        $this->ready = new SplQueue();
        $this->timers = new Timer();
        $this->tasks = new SplObjectStorage();
        $this->fiberTasks = new WeakMap();
        $this->fiberWaits = new WeakMap();
    }

    public function defer(Closure $callback): void
    {
        $this->assertActive('defer callbacks');
        $this->enqueueInternal($callback);
    }

    /**
     * @param Fiber<null, mixed, void, mixed> $fiber
     * @internal Register a task-owned Fiber for lifecycle diagnostics.
     */
    public function registerTask(Task $task, Fiber $fiber): void
    {
        $this->assertActive('create tasks');
        $this->tasks->offsetSet($task, null);
        $this->fiberTasks[$fiber] = $task;
    }

    /** @internal */
    public function startTask(Task $task): void
    {
        $this->enqueueInternal(static fn () => $task->start());
    }

    /**
     * @param Fiber<null, mixed, void, mixed> $fiber
     * @internal Resume only a suspended task Fiber.
     */
    public function resumeFiber(Fiber $fiber): void
    {
        $task = $this->fiberTasks[$fiber] ?? null;
        if ($task === null) {
            throw new LogicException('Cannot resume a Fiber not owned by an active task.');
        }
        $task->resume();
    }

    /** @internal */
    public function taskCompleted(Task $task, ?Throwable $error): void
    {
        $this->tasks->offsetUnset($task);
        unset($this->fiberTasks[$task->getFiber()]);
        if ($error !== null && !$task->isObserved()) {
            $this->unobservedErrors[$task->getId()] = [$task, $error];
        }
    }

    /** @internal */
    public function taskObserved(Task $task): void
    {
        unset($this->unobservedErrors[$task->getId()]);
    }

    /** @internal Cancel the current suspension without resuming stale callbacks. */
    public function cancelTask(Task $task): void
    {
        $handle = $this->fiberWaits[$task->getFiber()] ?? null;
        if ($handle !== null && $handle->active) {
            $this->removeWaitHandle($handle);
        }
        $this->enqueueInternal(static fn () => $task->deliverCancellation());
    }

    /** @internal A task awaiting another task is suspended at this checkpoint. */
    public function suspendOnTask(Task $target): void
    {
        $fiber = $this->currentFiber();
        $owner = $this->fiberTasks[$fiber] ?? throw new LogicException('Current Fiber is not owned by a task.');
        $owner->throwIfCancellationRequested();
        $handle = new WaitHandle($owner, $fiber, 'await');
        $handle->awaitedTask = $target;
        $this->fiberWaits[$fiber] = $handle;
        $target->addWaiter($handle);
        Fiber::suspend();
    }

    /** @internal Resume a task only if this exact wait is still active. */
    public function wake(WaitHandle $handle): void
    {
        if (!$handle->active) {
            return;
        }
        if ($handle->semaphoreGranted && $handle->semaphore !== null) {
            $handle->semaphore->consumeGrant($handle);
        }
        if ($handle->channelWaiter !== null) {
            $handle->channelWaiter->channel->consumeWaiter($handle->channelWaiter);
        }
        $this->removeWaitHandle($handle);
        $handle->task->resume();
    }

    /** @internal Queue a wait resumption to avoid nested Fiber execution. */
    public function scheduleWake(WaitHandle $handle): void
    {
        $this->enqueueInternal(static fn () => Loop::get()->wake($handle));
    }

    /** @internal Deliver an error to a suspended wait through the ready queue. */
    public function failWait(WaitHandle $handle, Throwable $error): void
    {
        $this->enqueueInternal(static function () use ($handle, $error): void {
            $loop = Loop::get();
            if (!$handle->active) {
                return;
            }
            $loop->removeWaitHandle($handle);
            $handle->task->throwFromWait($error);
        });
    }

    /** @internal Suspend the current task until a semaphore transfers it a slot. */
    public function suspendOnSemaphore(Semaphore $semaphore): void
    {
        $fiber = $this->currentFiber();
        $owner = $this->fiberTasks[$fiber] ?? throw new LogicException('Current Fiber is not owned by a task.');
        $owner->throwIfCancellationRequested();
        $handle = new WaitHandle($owner, $fiber, 'semaphore');
        $handle->semaphore = $semaphore;
        $this->fiberWaits[$fiber] = $handle;
        try {
            $semaphore->enqueue($handle);
        } catch (Throwable $error) {
            $this->removeWaitHandle($handle);
            throw $error;
        }
        Fiber::suspend();
    }

    /** @internal Suspend the current task on a bounded channel operation. */
    public function suspendOnChannel(BoundedChannel $channel, string $operation, mixed $value = null): mixed
    {
        $fiber = $this->currentFiber();
        $owner = $this->fiberTasks[$fiber] ?? throw new LogicException('Current Fiber is not owned by an active task.');
        $owner->throwIfCancellationRequested();
        if ($operation !== 'send' && $operation !== 'receive') {
            throw new LogicException('Unknown bounded channel operation.');
        }

        $handle = new WaitHandle($owner, $fiber, 'channel_' . $operation);
        $waiter = new ChannelWaiter($channel, $operation, $handle, $operation === 'send' ? $value : null);
        $handle->channelWaiter = $waiter;
        $this->fiberWaits[$fiber] = $handle;
        try {
            $channel->enqueue($waiter);
        } catch (Throwable $error) {
            $this->removeWaitHandle($handle);
            throw $error;
        }

        Fiber::suspend();
        if ($operation === 'send') {
            return null;
        }
        if (!$waiter->hasValue) {
            throw new LogicException('A channel receive resumed without a reserved message.');
        }
        $received = $waiter->value;
        $waiter->value = null;
        $waiter->hasValue = false;
        return $received;
    }

    /** @internal */
    public function completeWaiters(Task $task): void
    {
        foreach ($task->takeWaiters() as $handle) {
            $this->enqueueInternal(static fn () => Loop::get()->wake($handle));
        }
    }

    private function removeWaitHandle(WaitHandle $handle): void
    {
        $handle->active = false;
        unset($this->fiberWaits[$handle->fiber]);
        if ($handle->timeoutEntry !== null) {
            $this->timers->remove($handle->timeoutEntry);
            $handle->timeoutEntry = null;
        }
        if ($handle->kind === 'read' && $handle->streamId !== null) {
            $this->ioDriver->removeRead($handle->streamId, $handle->generation);
            if (($this->readers[$handle->streamId] ?? null) === $handle) {
                unset($this->readers[$handle->streamId]);
            }
        } elseif ($handle->kind === 'write' && $handle->streamId !== null) {
            $this->ioDriver->removeWrite($handle->streamId, $handle->generation);
            if (($this->writers[$handle->streamId] ?? null) === $handle) {
                unset($this->writers[$handle->streamId]);
            }
        } elseif ($handle->kind === 'timer' && $handle->timerEntry !== null) {
            $this->timers->remove($handle->timerEntry);
            $handle->timerEntry = null;
        } elseif ($handle->kind === 'await' && $handle->awaitedTask !== null) {
            $handle->awaitedTask->removeWaiter($handle);
        } elseif ($handle->kind === 'semaphore' && $handle->semaphore !== null) {
            $handle->semaphore->removeWaiter($handle);
        } elseif ($handle->channelWaiter !== null) {
            $waiter = $handle->channelWaiter;
            $waiter->channel->removeWaiter($waiter);
            $handle->channelWaiter = null;
        }
    }

    public function after(float $seconds, Closure $callback): void
    {
        $this->assertActive('schedule timers');
        $this->insertTimer($seconds, $callback);
    }

    private function insertTimer(float $seconds, Closure $callback): TimerNode
    {
        $at = hrtime(true) + (int)($seconds * 1_000_000_000);
        return $this->timers->schedule($at, $this->timerSeq++, $callback);
    }

    /** Suspend the current fiber and put it at the back of the ready queue.
     * @throws Throwable
     */
    public function pause(): void
    {
        $this->suspendCurrent('pause');
    }

    /**
     * @throws Throwable
     */
    public function sleep(float $seconds): void
    {
        $this->suspendCurrent('timer', null, $seconds);
    }

    /**
     * Suspend the current task until the stream is readable.
     *
     * @param resource $stream
     * @param float|int|null $timeout Seconds; null means no deadline, zero expires immediately.
     * @throws IoTimeoutException When the optional deadline expires first.
     * @throws \InvalidArgumentException For negative, non-finite, or unrepresentable timeouts.
     * @throws StreamClosedException
     * @throws StreamSelectException
     * @throws Throwable
     */
    public function waitReadable($stream, float|int|null $timeout = null): void
    {
        $this->assertCanWaitForIo();
        $timeout = $this->normalizeIoTimeout($timeout);
        $id = (int)$stream;
        isset($this->readers[$id]) && throw new LogicException('Another task already waits on this stream.');
        $this->suspendCurrent('read', $stream, $timeout);
    }

    /**
     * Suspend the current task until the stream is writable.
     *
     * @param resource $stream
     * @param float|int|null $timeout Seconds; null means no deadline, zero expires immediately.
     * @throws IoTimeoutException When the optional deadline expires first.
     * @throws \InvalidArgumentException For negative, non-finite, or unrepresentable timeouts.
     * @throws StreamClosedException
     * @throws StreamSelectException
     * @throws Throwable
     */
    public function waitWritable($stream, float|int|null $timeout = null): void
    {
        $this->assertCanWaitForIo();
        $timeout = $this->normalizeIoTimeout($timeout);
        $id = (int)$stream;
        isset($this->writers[$id]) && throw new LogicException('Another task already waits on this stream.');
        $this->suspendCurrent('write', $stream, $timeout);
    }

    private function normalizeIoTimeout(float|int|null $timeout): ?float
    {
        if ($timeout === null) {
            return null;
        }

        $seconds = (float)$timeout;
        if (!is_finite($seconds)) {
            throw new \InvalidArgumentException('I/O timeout must be a finite number.');
        }
        if ($seconds < 0.0) {
            throw new \InvalidArgumentException('I/O timeout must be greater than or equal to zero.');
        }
        if ($seconds >= (PHP_INT_MAX - hrtime(true)) / 1_000_000_000) {
            throw new \InvalidArgumentException('I/O timeout exceeds the supported monotonic timer range.');
        }

        return $seconds;
    }

    /**
     * @throws Throwable
     */
    private function suspendCurrent(string $kind, mixed $stream = null, float|int|null $seconds = null): void
    {
        $fiber = $this->currentFiber();
        $task = $this->fiberTasks[$fiber] ?? throw new LogicException('Current Fiber is not owned by a task.');
        $task->throwIfCancellationRequested();
        $streamId = $stream === null ? null : (int)$stream;
        $handle = new WaitHandle($task, $fiber, $kind, $streamId, ++$this->watchGeneration);
        $this->fiberWaits[$fiber] = $handle;

        try {
            if ($kind === 'timer') {
                $handle->timerEntry = $this->insertTimer($seconds ?? 0.0, static fn () => Loop::get()->wake($handle));
            } elseif ($kind === 'read') {
                if ($streamId === null) {
                    throw new LogicException('Read readiness requires a stream resource.');
                }
                $this->ioDriver->registerRead($streamId, $handle->generation, $stream);
                $this->readers[$streamId] = $handle;
                if ($seconds !== null) {
                    $handle->timeoutEntry = $this->insertTimer($seconds, static fn () => Loop::get()->timeoutIoWait($handle));
                }
            } elseif ($kind === 'write') {
                if ($streamId === null) {
                    throw new LogicException('Write readiness requires a stream resource.');
                }
                $this->ioDriver->registerWrite($streamId, $handle->generation, $stream);
                $this->writers[$streamId] = $handle;
                if ($seconds !== null) {
                    $handle->timeoutEntry = $this->insertTimer($seconds, static fn () => Loop::get()->timeoutIoWait($handle));
                }
            } elseif ($kind === 'pause') {
                $this->enqueueInternal(static fn () => Loop::get()->wake($handle));
            }
        } catch (Throwable $error) {
            $this->removeWaitHandle($handle);
            throw $error;
        }

        Fiber::suspend();
    }

    /** @internal Complete an I/O wait with its timeout, if it is still current. */
    private function timeoutIoWait(WaitHandle $handle): void
    {
        if (!$handle->active) {
            return;
        }

        $operation = $handle->kind === 'read' ? 'readability' : 'writability';
        $this->removeWaitHandle($handle);
        $handle->task->throwFromWait(new IoTimeoutException(
            "Timed out waiting for stream {$handle->streamId} to become {$operation}."
        ));
    }

    /** Request that the active run() call return after the current callback. */
    public function stop(): void
    {
        $this->stopRequested = true;
    }

    /** Begin terminal cooperative shutdown; outside run(), drive cleanup to completion. */
    public function shutdown(): void
    {
        if ($this->state === self::SHUT_DOWN) {
            return;
        }

        if ($this->state === self::ACTIVE) {
            $this->beginShutdown();
        }

        if (!$this->running) {
            $this->run();
        }
    }

    public function getState(): string
    {
        return $this->state;
    }

    private function beginShutdown(): void
    {
        $this->state = self::SHUTTING_DOWN;
        $this->stopRequested = false;
        $this->clearQueue();
        $this->timers->clear();

        $tasks = [];
        foreach ($this->tasks as $task) {
            $tasks[] = $task;
        }
        foreach ($tasks as $task) {
            $task->cancel();
        }

        // Drop the driver's references without closing user-owned resources.
        $this->ioDriver->clear();
        $this->readers = [];
        $this->writers = [];
        $this->clearPendingIoEvents();
    }

    private function finishShutdownIfIdle(): bool
    {
        if ($this->state !== self::SHUTTING_DOWN || $this->tasks->count() !== 0) {
            return false;
        }

        $this->clearQueue();
        $this->timers->clear();
        $this->readers = [];
        $this->writers = [];
        $this->ioDriver->clear();
        $this->clearPendingIoEvents();
        $this->fiberWaits = new WeakMap();
        $this->state = self::SHUT_DOWN;
        return true;
    }

    private function clearQueue(): void
    {
        while (!$this->ready->isEmpty()) {
            $this->ready->dequeue();
        }
    }

    private function clearPendingIoEvents(): void
    {
        $this->pendingIoEvents = [];
        $this->pendingIoOffset = 0;
    }

    private function enqueueInternal(Closure $callback): void
    {
        $this->ready->enqueue($callback);
    }

    private function assertActive(string $operation): void
    {
        if ($this->state !== self::ACTIVE) {
            throw new LogicException("Cannot {$operation}: the event loop is {$this->state}.");
        }
    }

    private function assertCanWaitForIo(): void
    {
        $fiber = $this->currentFiber();
        $task = $this->fiberTasks[$fiber] ?? null;
        $task?->throwIfCancellationRequested();
        if ($this->state === self::ACTIVE) {
            return;
        }
        if ($this->state === self::SHUTTING_DOWN && $task?->isCancellationCleanup()) {
            return;
        }
        $this->assertActive('register I/O waits');
    }

    /**
     * Run until $stop returns true, or until nothing is left to wait for.
     *
     * @phpstan-impure
     */
    public function run(?Closure $stop = null): void
    {
        if ($this->state === self::SHUT_DOWN) {
            throw new LogicException('Cannot run an event loop after shutdown.');
        }
        if ($this->running) {
            throw new LogicException('The event loop is already running.');
        }
        $this->running = true;
        $this->stopRequested = false;
        try {
            while (true) {
                if ($this->finishShutdownIfIdle()) {
                    $this->throwIfUnobservedErrors();
                    return;
                }
                if ($this->stopRequested) {
                    return;
                }
                if ($this->state !== self::SHUTTING_DOWN && $stop !== null && $stop()) {
                    return;
                }
                $tickStop = $this->state === self::SHUTTING_DOWN ? null : $stop;
                if (!$this->tick($tickStop)) {
                    if ($this->finishShutdownIfIdle()) {
                        $this->throwIfUnobservedErrors();
                        return;
                    }
                    $this->throwIfUnobservedErrors();
                    $this->throwIfSuspendedTasksAreStranded();
                    return;
                }
            }
        } finally {
            $this->running = false;
        }
    }

    private function throwIfUnobservedErrors(): void
    {
        if ($this->unobservedErrors === []) {
            return;
        }
        [, $error] = reset($this->unobservedErrors);
        throw new LogicException('An asynchronous task failed and its error was not observed.', 0, $error);
    }

    private function throwIfSuspendedTasksAreStranded(): void
    {
        $stranded = [];
        foreach ($this->tasks as $task) {
            if ($task->getState() === Task::SUSPENDED) {
                $stranded[] = '#' . $task->getId();
            }
        }
        if ($stranded !== []) {
            throw new LogicException('Deadlock: suspended task(s) have no stream or timer wake-up source: ' . implode(', ', $stranded));
        }
    }

    /**
     * @return bool false when the loop has nothing left to do
     * @phpstan-impure
     */
    private function tick(?Closure $stop): bool
    {
        // Bound each work class independently so a continuously replenished
        // callback, timer, or readiness queue cannot starve the other classes.
        for ($n = 0; $n < self::CALLBACKS_PER_TICK && !$this->ready->isEmpty(); $n++) {
            ($this->ready->dequeue())();
            if ($this->stopRequested) {
                return true;
            }
        }
        $now = hrtime(true);
        for ($n = 0; $n < self::TIMERS_PER_TICK; $n++) {
            $nextTimer = $this->timers->peekNext();
            if ($nextTimer === null || $nextTimer->at > $now) {
                break;
            }

            $timer = $this->timers->extractNext();
            $callback = $timer->callback;
            $timer->callback = null;
            if ($callback !== null) {
                $callback();
            }
            if ($this->stopRequested) {
                return true;
            }
        }
        if ($stop !== null && $stop()) {
            return true;
        }

        $this->dispatchPendingIoEvents();
        if ($this->stopRequested || $this->pendingIoEvents !== []) {
            return true;
        }

        $waitNs = null;
        $now = hrtime(true);
        $nextTimer = $this->timers->peekNext();
        if (!$this->ready->isEmpty() || ($nextTimer !== null && $nextTimer->at <= $now)) {
            $waitNs = 0;
        } elseif ($nextTimer !== null) {
            $remainingNs = $nextTimer->at - hrtime(true);
            $waitNs = (int)($remainingNs > 0 ? $remainingNs : 0);
        }
        if ($waitNs === null && !$this->readers && !$this->writers) {
            return false;
        }
        // The driver may return a large ready set. Keep its FIFO order and
        // dispatch it over bounded cycles instead of monopolizing this tick.
        $this->pendingIoEvents = $this->ioDriver->wait($waitNs);
        $this->pendingIoOffset = 0;
        $this->dispatchPendingIoEvents();
        return true;
    }

    private function dispatchPendingIoEvents(): void
    {
        $processed = 0;
        while ($this->pendingIoOffset < count($this->pendingIoEvents) && $processed < self::IO_EVENTS_PER_TICK) {
            $event = $this->pendingIoEvents[$this->pendingIoOffset++];
            $processed++;
            $handle = $event->operation === 'read'
                ? ($this->readers[$event->streamId] ?? null)
                : ($this->writers[$event->streamId] ?? null);
            if ($handle === null || !$handle->active || $handle->generation !== $event->generation) {
                continue;
            }

            if ($event->error !== null) {
                $this->removeWaitHandle($handle);
                $handle->task->throwFromWait($event->error);
            } else {
                $this->wake($handle);
            }
            if ($this->stopRequested) {
                break;
            }
        }

        if ($this->pendingIoOffset >= count($this->pendingIoEvents)) {
            $this->clearPendingIoEvents();
        }
    }

    /** @return Fiber<null, mixed, void, mixed> */
    private function currentFiber(): Fiber
    {
        return Fiber::getCurrent() ?? throw new LogicException('This call must run inside a task.');
    }
}
