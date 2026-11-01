# Semaphore and operation backpressure

`Omegaalfa\Loop\Semaphore` limits the number of operations that have acquired a
slot. Construct it with a positive capacity and run each asynchronous operation
inside `withPermit()`:

```php
$semaphore = new Omegaalfa\Loop\Semaphore(20);

$task = new Omegaalfa\Loop\Task(static function () use ($semaphore): void {
    $result = $semaphore->withPermit(static function (): mixed {
        // Start/use one bounded operation. Loop waits are cooperative here.
        Omegaalfa\Loop\Loop::get()->sleep(0.01);
        return null;
    });
});
```

If capacity is available, the operation enters immediately. Otherwise the
current Task suspends cooperatively in an insertion-ordered FIFO queue. A
released slot is handed to the oldest active waiter and its Fiber is resumed
through the Loop's ready queue; no polling or process-wide blocking is used.
Cancellation removes a queued waiter in O(1). Cancellation after acquisition
unwinds `withPermit()` and releases the slot from `finally`. Exceptions also
release the slot. Do not use the semaphore's private accounting as a substitute
for `withPermit()`; manual permit ownership is intentionally not exposed.
The callback must cover the full lifetime of the operation: if it starts work
that continues asynchronously after returning, the slot is released at return
and will not limit that detached work.

`close()` is idempotent, prevents new acquisitions, and delivers
`SemaphoreClosedException` to tasks still queued. Operations that already hold
a slot may finish or unwind normally and release it. Acquiring on a closed
semaphore throws immediately. Call `close()` from ordinary application cleanup
when no more work should enter.

This limits **active operations**, not Task creation, callback queues, or the
number of tasks waiting for capacity. Creating a very large number of Tasks
still consumes memory for those Tasks and their suspended Fibers. Producers
that need bounded memory must also control Task creation or use an application
queue with an explicit admission/backpressure policy. The Event Loop itself has
no new global queue cap.

Use `getActiveCount()` and `getWaitingCount()` for diagnostics. Shutdown
cooperatively cancels Tasks using the semaphore; active operation cleanup runs
through `finally`, while queued acquisitions are removed with their Task's
WaitHandle. A semaphore is not automatically closed by Loop shutdown because
it may be an application-owned object; Loop shutdown prevents the canceled
Tasks from continuing to use the Loop after cleanup.

## Benchmarks

Compare a burst of 1,000 1 ms asynchronous operations without a limit and with
limits of 10, 50, and 100:

```sh
php benchmarks/run.php \
  --scenario=backpressure_unbounded,backpressure_10,backpressure_50,backpressure_100 \
  --rounds=10 --implementations=omegaalfa --no-xdebug --timeout=60 \
  --output=backpressure
```

The worker creates all 1,000 Tasks before the Loop drains them. This models a
producer burst faster than the consumer; because Task admission is not bounded,
peak memory includes every created Task even when active operations are
limited. Latency is submission-to-completion, so limited runs intentionally
include queueing delay. Lower throughput is expected for smaller capacities
and is not by itself a regression: the cap trades peak concurrency for more
predictable active resource use. These cases compare Omegaalfa's limiter to its
own unbounded path; ReactPHP and Fiber Event Loop do not expose the same
semaphore contract in this suite.
