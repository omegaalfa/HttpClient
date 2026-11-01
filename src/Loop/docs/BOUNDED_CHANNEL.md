# BoundedChannel

`BoundedChannel` transfers values between Tasks through a FIFO buffer with a
fixed positive capacity. When the buffer is full, `send()` suspends its Task;
when it is empty, `receive()` suspends its Task. Neither operation polls or
blocks the PHP process.

```php
use Omegaalfa\Loop\BoundedChannel;
use Omegaalfa\Loop\ChannelClosedException;
use function Omegaalfa\Loop\async;
use function Omegaalfa\Loop\awaitAll;

$channel = new BoundedChannel(2);

$producer = async(static function () use ($channel): void {
    foreach (['red', 'green', 'blue'] as $color) {
        $channel->send($color); // waits when two messages are buffered
    }
    $channel->close();
});

$consumer = async(static function () use ($channel): array {
    $values = [];
    try {
        while (true) $values[] = $channel->receive();
    } catch (ChannelClosedException) {
        // Closed and drained: no more values will arrive.
    }
    return $values;
});

[$unused, $colors] = awaitAll($producer, $consumer);
```

In real programs, add the Composer autoloader and import the APIs needed by the
example. The snippet shows one producer and one consumer; any number of Tasks
may call the same channel.

## Operation contract

- `new BoundedChannel(int $capacity)` requires `capacity >= 1`.
- `send(mixed $value): void` returns once the value is accepted into the
  buffer or reserved for one waiting receiver. If no capacity is available,
  the calling Task suspends in FIFO order.
- `receive(): mixed` returns the oldest accepted value. If the buffer is empty,
  the calling Task suspends in FIFO order.
- `close(): void` is idempotent and prevents future sends.
- Values already accepted are preserved. Consumers may drain them after
  `close()`; once the channel is both closed and empty, `receive()` throws
  `ChannelClosedException`.
- Producers still blocked when `close()` happens receive
  `ChannelClosedException`; their unaccepted values are discarded.
- A send is committed at its acceptance/reservation point. If cancellation
  arrives after that point but before `send()` returns to its Fiber, the message
  remains accepted even though the producer Task may observe `TaskCancelled`.
- A receive reserves exactly one message for one consumer. If that Task is
  canceled after reservation but before resuming, the message is restored to
  the front of the buffer for the next receiver.

## Linearization and cancellation

Each operation has a synchronous commit point inside the Event Loop; PHP Fibers
do not execute concurrently on multiple threads, but queued callbacks can still
make cancellation or close run before a reserved wake callback.

- `send()` linearizes when its value is appended to the buffer or assigned to a
  waiting receiver. A blocked sender is accepted when `pump()` performs that
  same transfer. Before this point cancellation removes the waiter and clears
  its value. After it, cancellation affects the producer Task but cannot retract
  the accepted message.
- A direct `receive()` linearizes when it removes the head of the buffer. For a
  suspended receiver, assignment to its waiter is a reservation; delivery
  linearizes when the Loop consumes that active reservation immediately before
  resuming the Fiber. Cancellation before then returns the value to the head of
  the buffer. A stale queued wake sees an inactive WaitHandle and does nothing.
- `close()` linearizes when the closed flag is set. It rejects all still-queued
  senders, then hands buffered values to waiting receivers before failing any
  remaining empty receivers. Existing receive reservations and already accepted
  buffered messages remain committed. Repeating `close()` has no effect.
  A receiver still queued when `close()` reaches it is failed if the buffer is
  empty at that point; if a different receiver is later canceled and restores a
  reserved value, a new receive may drain that value from the closed channel.

The capacity invariant is `buffered messages + reserved receives <= capacity`.
An accepted sender is represented either by a buffered message or by a receiver
reservation, so a sender waiting for its wake callback does not create a second
copy or consume extra capacity. The public depth counters report only buffered
values; they intentionally exclude reserved receives.

Message order follows acceptance order. Producer and consumer wait queues each
select the oldest active waiter. Cancellation removes that waiter without
reordering the remaining waiters. If a reserved receiver is canceled, its value
returns to the front, preserving order among values still available; however,
another receiver may already have received a later value before that restoration
is processed. This is the documented exception to observable delivery order
under cancellation.

`TaskCancelled` and `ChannelClosedException` are distinct: cancellation belongs
to the calling Task, while channel closure is an application-level result.
Catch either at the point where the application can decide what to do.

```php
use Omegaalfa\Loop\ChannelClosedException;
use Omegaalfa\Loop\TaskCancelled;
use function Omegaalfa\Loop\async;

$consumer = async(static function () use ($channel): void {
    try {
        $value = $channel->receive();
        process($value);
    } catch (ChannelClosedException) {
        // Normal end of input.
    } catch (TaskCancelled) {
        // This consumer was canceled; release its own resources in finally.
        throw new TaskCancelled('consumer stopped');
    }
});
```

## FIFO, capacity, and backpressure

The channel preserves message order and separately serves blocked producers
and consumers in FIFO order. A new caller cannot jump ahead of callers already
waiting in the corresponding queue. The Loop schedules resumed Tasks through
its normal ready queue, so channel handoff does not recursively execute another
Fiber inside `send()` or `receive()`.

The capacity bounds buffered plus temporarily reserved messages. A reserved
message counts against capacity until the receiver resumes or cancellation
returns it to the buffer. `getQueueDepth()` and `getPeakQueueDepth()` report
buffered messages; reserved handoffs are not counted in those buffer metrics.

Backpressure acts on producers at `send()`: they stop advancing until the
channel accepts their value. It does not automatically limit how many producer
Tasks have already been created. Thousands of Tasks may be suspended waiting to
send and still consume memory. To bound Task memory too, keep a fixed number of
producer/consumer Tasks and exchange values through the channel rather than
creating one Task per value.

## Close and Loop shutdown

`close()` does not shut down the Event Loop. It wakes blocked operations with
`ChannelClosedException` through the ready queue. A close does not drop values
already in the buffer.

Loop `shutdown()` cooperatively cancels Tasks blocked on the channel and
removes their WaitHandles. A receive reservation canceled before resumption is
restored, and a send canceled before acceptance is removed. Accepted messages
remain owned by the channel; Loop shutdown neither closes the channel nor
destroys application-owned values. Call `close()` when the application wants
to stop producers and signal end-of-stream.

No operation can forcibly interrupt synchronous blocking PHP code. Producer
or consumer callbacks should use cooperative Loop waits and should not keep a
message reservation while doing unrelated, unbounded work.

## Diagnostics

```php
$channel->capacity;            // configured positive integer
$channel->getQueueDepth();     // currently buffered values
$channel->getPeakQueueDepth(); // maximum buffered values observed
$channel->getWaitingSenders();
$channel->getWaitingReceivers();
$channel->isClosed();
```

These counts describe channel state, not the total number of Tasks in the
process. A blocked sender queue can be large even though the message buffer
never exceeds its configured capacity.

## Benchmark

The reproducible comparison in
[`benchmarks/results/channel-comparison.md`](benchmarks/results/channel-comparison.md)
uses 10,000 items, capacity 100, four producer Tasks, and 100 fixed consumer
Tasks. It contrasts the eager Task-per-item Semaphore model with the fixed
producer/consumer BoundedChannel model. See
[`benchmarks/README.md`](benchmarks/README.md) for the command and full
methodology.
