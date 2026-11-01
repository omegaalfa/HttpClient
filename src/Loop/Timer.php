<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Loop;

use UnderflowException;

/**
 * @internal Stable SplHeap with O(1) timer invalidation and bounded tombstones.
 * @extends \SplHeap<mixed>
 */
final class Timer extends \SplHeap
{
    private int $activeCount = 0;
    private int $tombstoneCount = 0;

    public function schedule(int $at, int $sequence, \Closure $callback): TimerNode
    {
        $node = new TimerNode($at, $sequence, $callback);
        $this->insert($node);

        return $node;
    }

    #[\ReturnTypeWillChange]
    public function insert(mixed $value)
    {
        if (is_array($value)) {
            if (!isset($value[0], $value[1]) || !(($value[2] ?? null) instanceof \Closure)) {
                throw new \InvalidArgumentException('Timer entries must contain a deadline, sequence, and Closure.');
            }
            $value = new TimerNode((int)$value[0], (int)$value[1], $value[2]);
        }
        if (!$value instanceof TimerNode || !$value->active) {
            throw new \InvalidArgumentException('Timer heap entries must be active TimerNode instances or legacy timer tuples.');
        }

        parent::insert($value);
        $this->activeCount++;

        return true;
    }

    public function extract(): mixed
    {
        $node = $this->extractNext();
        return [$node->at, $node->sequence, $node->callback];
    }

    public function top(): mixed
    {
        $node = $this->peekNext() ?? throw new UnderflowException('Cannot inspect an empty timer queue.');
        return [$node->at, $node->sequence, $node->callback];
    }

    public function isEmpty(): bool
    {
        return !$this->hasActiveTimers();
    }

    public function count(): int
    {
        return max(0, $this->activeCount);
    }

    public function peekNext(): ?TimerNode
    {
        while (!parent::isEmpty()) {
            /** @var TimerNode $node */
            $node = parent::top();
            if ($node->active) {
                return $node;
            }

            parent::extract();
            $this->tombstoneCount--;
        }

        return null;
    }

    public function extractNext(): TimerNode
    {
        $node = $this->peekNext();
        if ($node === null) {
            throw new UnderflowException('Cannot extract a timer from an empty queue.');
        }

        parent::extract();
        $node->active = false;
        $this->activeCount--;

        return $node;
    }

    /** Invalidate a pending timer and release its closure immediately. */
    public function remove(TimerNode $node): bool
    {
        if (!$node->active) {
            return false;
        }

        $node->active = false;
        $node->callback = null;
        $this->activeCount--;
        $this->tombstoneCount++;
        $this->compactIfNeeded();

        return true;
    }

    public function hasActiveTimers(): bool
    {
        return $this->peekNext() !== null;
    }

    /** Clear every pending callback and heap node during shutdown. */
    public function clear(): void
    {
        while (!parent::isEmpty()) {
            /** @var TimerNode $node */
            $node = parent::extract();
            $node->active = false;
            $node->callback = null;
        }

        $this->activeCount = 0;
        $this->tombstoneCount = 0;
    }

    /** Remove all pending entries using this callback. Kept for Timer API compatibility. */
    public function removeCallback(\Closure $callback): void
    {
        $retained = [];
        while (!parent::isEmpty()) {
            /** @var TimerNode $node */
            $node = parent::extract();
            if (!$node->active) {
                $this->tombstoneCount--;
                continue;
            }

            if ($node->callback === $callback) {
                $node->active = false;
                $node->callback = null;
                $this->activeCount--;
                continue;
            }

            $retained[] = $node;
        }

        foreach ($retained as $node) {
            parent::insert($node);
        }
        $this->tombstoneCount = 0;
    }

    private function compactIfNeeded(): void
    {
        // Bound stale nodes by the number of live timers. This avoids an
        // arbitrary heap-size threshold and guarantees an empty live queue
        // is physically compacted as its last entries are invalidated.
        if ($this->tombstoneCount < $this->activeCount) {
            return;
        }

        $active = [];
        while (!parent::isEmpty()) {
            /** @var TimerNode $node */
            $node = parent::extract();
            if ($node->active) {
                $active[] = $node;
            } else {
                $node->callback = null;
            }
        }

        foreach ($active as $node) {
            parent::insert($node);
        }

        $this->tombstoneCount = 0;
    }

    protected function compare(mixed $value1, mixed $value2): int
    {
        /** @var TimerNode $value1 */
        /** @var TimerNode $value2 */
        if ($value1->at === $value2->at) {
            return $value2->sequence <=> $value1->sequence;
        }

        return $value2->at <=> $value1->at;
    }
}
