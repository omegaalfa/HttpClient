<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Http;

use Fiber;
use Omegaalfa\FiberEventLoop\FiberEventLoop;
use Omegaalfa\HttpClient\Http\Exceptions\ConnectionException;
use Throwable;

/** FiberEventLoop with non-consuming, deadline-aware socket readiness. */
final class HttpClientEventLoop extends FiberEventLoop implements SocketReadinessInterface
{
    /** @var array<int, array{stream: resource, read: bool, write: bool, deadline: int}> */
    private array $waiters = [];

    /** @var array<int, resource> */
    private array $readSet = [];

    /** @var array<int, resource> */
    private array $writeSet = [];

    /** @var array<int, array<int, true>> resource id => fiber ids */
    private array $readOwners = [];

    /** @var array<int, array<int, true>> resource id => fiber ids */
    private array $writeOwners = [];

    /** @var array<int, true> */
    private array $ready = [];

    /** @var array<int, Throwable> */
    private array $waitErrors = [];

    public function awaitStream(mixed $stream, bool $read, bool $write, int $deadlineNs): void
    {
        if (!is_resource($stream)) {
            throw new \RuntimeException('Invalid stream resource');
        }
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            $this->waitSynchronously($stream, $read, $write, $deadlineNs);
            return;
        }

        $fiberId = spl_object_id($fiber);
        $streamId = (int) $stream;
        $this->waiters[$fiberId] = [
            'stream' => $stream,
            'read' => $read,
            'write' => $write,
            'deadline' => $deadlineNs,
        ];
        if ($read) {
            $this->readSet[$streamId] = $stream;
            $this->readOwners[$streamId][$fiberId] = true;
        }
        if ($write) {
            $this->writeSet[$streamId] = $stream;
            $this->writeOwners[$streamId][$fiberId] = true;
        }

        try {
            Fiber::suspend();
            if (isset($this->waitErrors[$fiberId])) {
                throw $this->waitErrors[$fiberId];
            }
        } finally {
            $this->removeWaiter($fiberId);
        }
    }

    public function cancel(int $id): void
    {
        $this->removeWaiter($id);
        parent::cancel($id);
    }

    protected function processPrioritizedWorkCycle(): bool
    {
        $hadWork = false;
        if ($this->acceptStreams !== []) {
            $hadWork = $this->execAcceptStreams() > 0;
        }
        if ($this->readStreams !== []) {
            $hadWork = $this->execReadStreams() > 0 || $hadWork;
        }
        if ($this->writeStreams !== []) {
            $hadWork = $this->execWriteStreams() > 0 || $hadWork;
        }
        if ($this->fibers !== []) {
            $hadWork = $this->execFibers() > 0 || $hadWork;
        }
        if ($this->timers !== []) {
            $hadWork = $this->execTimers() > 0 || $hadWork;
        }
        if ($this->deferred !== []) {
            $hadWork = $this->execDeferred() > 0 || $hadWork;
        }
        while ($this->waiters !== []) {
            $readyCount = $this->selectReadiness();
            if ($readyCount === 0) {
                break;
            }

            // Dispatch every Fiber made runnable by this select result before
            // asking the kernel for readiness again.
            $hadWork = true;
            $this->drainReadyFibers();
        }

        return $hadWork;
    }

    protected function execFibers(): int
    {
        $processed = 0;
        foreach ($this->fibers as $id => $fiber) {
            if ($this->isCancelled($id)) {
                $this->removeWaiter($id);
                unset($this->fibers[$id]);
                continue;
            }

            if ($fiber->isStarted() && $fiber->isSuspended()
                && isset($this->waiters[$id]) && !isset($this->ready[$id])) {
                continue;
            }

            try {
                if (!$fiber->isStarted()) {
                    $fiber->start($id);
                    $processed++;
                } elseif ($fiber->isSuspended()) {
                    $fiber->resume();
                    $processed++;
                }

                if ($fiber->isTerminated()) {
                    unset($this->fibers[$id]);
                }
            } catch (Throwable $exception) {
                $this->errors[$id] = $exception->getMessage();
                $this->removeWaiter($id);
                unset($this->fibers[$id]);
            }
        }

        return $processed;
    }

    private function drainReadyFibers(): int
    {
        $processed = 0;
        foreach (array_keys($this->ready) as $id) {
            $fiber = $this->fibers[$id] ?? null;
            if ($fiber === null || !$fiber->isSuspended()) {
                unset($this->ready[$id]);
                continue;
            }

            try {
                $fiber->resume();
                $processed++;
                if ($fiber->isTerminated()) {
                    unset($this->fibers[$id]);
                }
            } catch (Throwable $exception) {
                $this->errors[$id] = $exception->getMessage();
                $this->removeWaiter($id);
                unset($this->fibers[$id]);
            }
        }

        return $processed;
    }

    private function selectReadiness(): int
    {
        $now = hrtime(true);
        foreach ($this->waiters as $id => $waiter) {
            if ($waiter['deadline'] <= $now || !is_resource($waiter['stream'])) {
                $this->ready[$id] = true;
            }
        }

        $read = $this->readSet;
        $write = $this->writeSet;
        $except = [];
        if ($read === [] && $write === []) {
            return $this->markExpiredWaiters();
        }

        $block = $this->canBlockForReadiness();
        [$seconds, $microseconds] = $this->nextWakeupTimeout($block);
        $selected = @stream_select($read, $write, $except, $seconds, $microseconds);
        if ($selected === false) {
            foreach ($this->waiters as $id => $_waiter) {
                $this->waitErrors[$id] = new ConnectionException('stream_select failed while waiting for socket readiness');
                $this->ready[$id] = true;
            }
            return count($this->waiters);
        }

        $woken = 0;
        foreach ($read as $stream) {
            $streamId = (int) $stream;
            foreach ($this->readOwners[$streamId] ?? [] as $fiberId => $_) {
                if (!isset($this->ready[$fiberId])) {
                    $this->ready[$fiberId] = true;
                    $woken++;
                }
            }
        }
        foreach ($write as $stream) {
            $streamId = (int) $stream;
            foreach ($this->writeOwners[$streamId] ?? [] as $fiberId => $_) {
                if (!isset($this->ready[$fiberId])) {
                    $this->ready[$fiberId] = true;
                    $woken++;
                }
            }
        }

        return $woken + $this->markExpiredWaiters();
    }

    private function canBlockForReadiness(): bool
    {
        if ($this->ready !== [] || $this->deferred !== [] || $this->acceptStreams !== [] || $this->readStreams !== [] || $this->writeStreams !== []) {
            return false;
        }
        if (count($this->waiters) !== count($this->fibers)) {
            return false;
        }
        foreach ($this->fibers as $id => $fiber) {
            if (!$fiber->isStarted() || !$fiber->isSuspended() || !isset($this->waiters[$id])) {
                return false;
            }
        }
        return true;
    }

    /** @return array{0: ?int, 1: ?int} */
    private function nextWakeupTimeout(bool $block): array
    {
        if (!$block) {
            return [0, 0];
        }

        $remainingNs = PHP_INT_MAX;
        foreach ($this->waiters as $waiter) {
            $remainingNs = min($remainingNs, $waiter['deadline'] - hrtime(true));
        }
        $now = microtime(true);
        foreach ($this->timers as $timer) {
            $remainingNs = min($remainingNs, (int) (($timer['time'] - $now) * 1_000_000_000));
        }
        if ($remainingNs === PHP_INT_MAX) {
            return [null, null];
        }
        $remainingNs = max(0, (int) $remainingNs);
        return [intdiv($remainingNs, 1_000_000_000), intdiv($remainingNs % 1_000_000_000, 1_000)];
    }

    private function markExpiredWaiters(): int
    {
        $now = hrtime(true);
        $woken = 0;
        foreach ($this->waiters as $id => $waiter) {
            if (($waiter['deadline'] <= $now || !is_resource($waiter['stream'])) && !isset($this->ready[$id])) {
                $this->ready[$id] = true;
                $woken++;
            }
        }
        return $woken;
    }

    private function removeWaiter(int $fiberId): void
    {
        $waiter = $this->waiters[$fiberId] ?? null;
        if ($waiter === null) {
            unset($this->ready[$fiberId], $this->waitErrors[$fiberId]);
            return;
        }
        $streamId = (int) $waiter['stream'];
        if ($waiter['read']) {
            unset($this->readOwners[$streamId][$fiberId]);
            if (($this->readOwners[$streamId] ?? []) === []) {
                unset($this->readOwners[$streamId], $this->readSet[$streamId]);
            }
        }
        if ($waiter['write']) {
            unset($this->writeOwners[$streamId][$fiberId]);
            if (($this->writeOwners[$streamId] ?? []) === []) {
                unset($this->writeOwners[$streamId], $this->writeSet[$streamId]);
            }
        }
        unset($this->waiters[$fiberId], $this->ready[$fiberId], $this->waitErrors[$fiberId]);
    }

    /** @param resource $stream */
    private function waitSynchronously($stream, bool $read, bool $write, int $deadlineNs): void
    {
        while (hrtime(true) < $deadlineNs) {
            $remainingNs = $deadlineNs - hrtime(true);
            $readStreams = $read ? [$stream] : [];
            $writeStreams = $write ? [$stream] : [];
            $except = [];
            if (@stream_select($readStreams, $writeStreams, $except, intdiv($remainingNs, 1_000_000_000), intdiv($remainingNs % 1_000_000_000, 1_000)) !== 0) {
                return;
            }
        }
    }
}
