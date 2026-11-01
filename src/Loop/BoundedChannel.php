<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Loop;

use InvalidArgumentException;
use LogicException;
use SplDoublyLinkedList;

/** A FIFO channel with bounded buffering and cooperatively suspended Tasks. */
final class BoundedChannel
{
    /** @var SplDoublyLinkedList<mixed> */
    private SplDoublyLinkedList $messages;
    /** @var array<int, ChannelWaiter> Insertion-ordered FIFO waiter queues. */
    private array $senders = [];
    /** @var array<int, ChannelWaiter> */
    private array $receivers = [];
    private int $nextWaiterId = 0;
    private int $reservedReceives = 0;
    private int $peakQueueDepth = 0;
    private bool $closed = false;

    public function __construct(public readonly int $capacity)
    {
        if ($capacity < 1) {
            throw new InvalidArgumentException('Channel capacity must be at least one.');
        }
        $this->messages = new SplDoublyLinkedList();
    }

    /** Send a value; suspend until it is accepted if the channel is full. */
    public function send(mixed $value): void
    {
        if ($this->closed) {
            throw new ChannelClosedException('Cannot send to a closed channel.');
        }

        $this->pump();
        if ($this->senders === [] && $this->occupancy() < $this->capacity) {
            $receiver = $this->takeReceiver();
            if ($receiver !== null) {
                $this->reserveReceive($receiver, $value);
                $this->schedule($receiver);
                return;
            }

            $this->messages->push($value);
            $this->recordDepth();
            return;
        }

        Loop::get()->suspendOnChannel($this, 'send', $value);
    }

    /** Receive the oldest value, suspending while the channel is empty. */
    public function receive(): mixed
    {
        $this->pump();
        if (!$this->messages->isEmpty() && $this->receivers === []) {
            $value = $this->messages->shift();
            $this->pump();
            return $value;
        }

        if ($this->closed) {
            throw new ChannelClosedException('Cannot receive from a closed and drained channel.');
        }

        if ($this->receivers === [] && $this->senders !== [] && $this->occupancy() < $this->capacity) {
            $sender = $this->takeSender();
            if ($sender !== null) {
                $value = $sender->value;
                $this->acceptSender($sender);
                $this->schedule($sender);
                return $value;
            }
        }

        return Loop::get()->suspendOnChannel($this, 'receive');
    }

    /**
     * Stop accepting sends. Accepted buffered values remain available to
     * receivers; blocked sends fail and empty receivers fail after drain.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        $senders = $this->senders;
        $this->senders = [];
        foreach ($senders as $waiter) {
            $waiter->queueId = null;
            $waiter->state = 'closed';
            $waiter->value = null;
            $this->fail($waiter, new ChannelClosedException('Channel closed before the send was accepted.'));
        }

        $this->pump();

        $receivers = $this->receivers;
        $this->receivers = [];
        foreach ($receivers as $waiter) {
            $waiter->queueId = null;
            $waiter->state = 'closed';
            $this->fail($waiter, new ChannelClosedException('Channel closed while waiting for a message.'));
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function getQueueDepth(): int
    {
        return $this->messages->count();
    }

    public function getPeakQueueDepth(): int
    {
        return $this->peakQueueDepth;
    }

    public function getWaitingSenders(): int
    {
        return count($this->senders);
    }

    public function getWaitingReceivers(): int
    {
        return count($this->receivers);
    }

    /** @internal Attach a suspended send/receive operation. */
    public function enqueue(ChannelWaiter $waiter): void
    {
        if ($waiter->direction === 'send' && $this->closed) {
            throw new ChannelClosedException('Cannot send to a closed channel.');
        }
        if ($waiter->direction === 'receive' && $this->closed && $this->messages->isEmpty()) {
            throw new ChannelClosedException('Cannot receive from a closed and drained channel.');
        }

        $id = $this->nextWaiterId++;
        $waiter->queueId = $id;
        $waiter->state = 'queued';
        if ($waiter->direction === 'send') {
            $this->senders[$id] = $waiter;
        } else {
            $this->receivers[$id] = $waiter;
        }
        $this->pump();
    }

    /** @internal Cancel a queued operation or restore a reserved receive. */
    public function removeWaiter(ChannelWaiter $waiter): void
    {
        if ($waiter->state === 'queued') {
            $queueId = $waiter->queueId;
            if ($queueId === null) {
                throw new LogicException('A queued channel waiter must have an identifier.');
            }
            if ($waiter->direction === 'send') {
                unset($this->senders[$queueId]);
                if ($this->senders === []) {
                    $this->senders = [];
                }
                $waiter->value = null;
            } else {
                unset($this->receivers[$queueId]);
                if ($this->receivers === []) {
                    $this->receivers = [];
                }
            }
            $waiter->queueId = null;
            $waiter->state = 'cancelled';
            $this->pump();
        } elseif ($waiter->state === 'reserved' && $waiter->direction === 'receive') {
            if (!$waiter->hasValue || $this->reservedReceives < 1) {
                throw new LogicException('A reserved channel message could not be restored.');
            }
            $this->reservedReceives--;
            $this->messages->unshift($waiter->value);
            $waiter->value = null;
            $waiter->hasValue = false;
            $waiter->state = 'cancelled';
            $this->pump();
        } elseif ($waiter->state === 'reserved' && $waiter->direction === 'send') {
            // Reservation is the send commit point. Cancellation cannot retract
            // a value already buffered or assigned to a consumer.
            $waiter->value = null;
            $waiter->state = 'accepted_cancelled';
        }

        $waiter->queueId = null;
        $waiter->handle = null;
    }

    /** @internal Finalize a reservation before Loop resumes its Fiber. */
    public function consumeWaiter(ChannelWaiter $waiter): void
    {
        if ($waiter->state !== 'reserved') {
            throw new LogicException('A channel operation resumed without a reservation.');
        }
        if ($waiter->direction === 'receive') {
            if (!$waiter->hasValue || $this->reservedReceives < 1) {
                throw new LogicException('A channel receive reservation is inconsistent.');
            }
            $this->reservedReceives--;
            $this->pump();
        } else {
            $waiter->value = null;
        }
        $waiter->state = 'delivered';
        $waiter->queueId = null;
    }

    private function pump(): void
    {
        while (true) {
            if (!$this->messages->isEmpty() && $this->receivers !== []) {
                $receiver = $this->takeReceiver();
                if ($receiver === null) {
                    continue;
                }
                $this->reserveReceive($receiver, $this->messages->shift());
                $this->schedule($receiver);
                continue;
            }

            if ($this->senders !== [] && $this->occupancy() < $this->capacity) {
                $sender = $this->takeSender();
                if ($sender === null) {
                    continue;
                }
                if ($this->messages->isEmpty() && $this->receivers !== []) {
                    $receiver = $this->takeReceiver();
                    if ($receiver !== null) {
                        $this->reserveReceive($receiver, $sender->value);
                        $this->acceptSender($sender);
                        $this->schedule($receiver);
                        $this->schedule($sender);
                        continue;
                    }
                }
                $this->messages->push($sender->value);
                $this->recordDepth();
                $this->acceptSender($sender);
                $this->schedule($sender);
                continue;
            }

            break;
        }
    }

    private function takeSender(): ?ChannelWaiter
    {
        while ($this->senders !== []) {
            $id = array_key_first($this->senders);
            $waiter = $this->senders[$id];
            unset($this->senders[$id]);
            if ($this->senders === []) {
                $this->senders = [];
            }
            $waiter->queueId = null;
            if ($waiter->handle?->active && $waiter->state === 'queued') {
                return $waiter;
            }
        }
        return null;
    }

    private function takeReceiver(): ?ChannelWaiter
    {
        while ($this->receivers !== []) {
            $id = array_key_first($this->receivers);
            $waiter = $this->receivers[$id];
            unset($this->receivers[$id]);
            if ($this->receivers === []) {
                $this->receivers = [];
            }
            $waiter->queueId = null;
            if ($waiter->handle?->active && $waiter->state === 'queued') {
                return $waiter;
            }
        }
        return null;
    }

    private function reserveReceive(ChannelWaiter $receiver, mixed $value): void
    {
        $receiver->value = $value;
        $receiver->hasValue = true;
        $receiver->state = 'reserved';
        $this->reservedReceives++;
    }

    private function acceptSender(ChannelWaiter $sender): void
    {
        $sender->value = null;
        $sender->state = 'reserved';
    }

    private function schedule(ChannelWaiter $waiter): void
    {
        if ($waiter->handle === null) {
            throw new LogicException('Cannot schedule a detached channel operation.');
        }
        Loop::get()->scheduleWake($waiter->handle);
    }

    private function fail(ChannelWaiter $waiter, ChannelClosedException $error): void
    {
        if ($waiter->handle === null) {
            throw new LogicException('Cannot fail a detached channel operation.');
        }
        Loop::get()->failWait($waiter->handle, $error);
    }

    private function occupancy(): int
    {
        return $this->messages->count() + $this->reservedReceives;
    }

    private function recordDepth(): void
    {
        $this->peakQueueDepth = max($this->peakQueueDepth, $this->messages->count());
    }
}
