# Scheduler fairness

Each Loop tick processes at most 256 ready callbacks, 256 due timers, and 256 pending I/O events. These are service quanta, not queue capacities: work stays FIFO in the callback queue, timer-ordered in the heap, or in the driver's event order until a later tick. No item is rejected because of a budget. Keeping separate budgets lets continuously replenished callbacks or zero-delay timers make progress without holding I/O behind a full batch.

An I/O driver may return a large readiness batch. The Loop retains that batch and checks each event's active `WaitHandle` generation when dispatching it. Cancellation and shutdown invalidate handles, so stale events are discarded. `stop()` leaves pending events for a later `run()`; shutdown clears them.

These budgets are not backpressure. `defer()`, task creation, timers, and driver event batches can still consume unbounded memory if producers outpace service. Applications must control concurrency or production at their own boundary; this change does not silently cap or drop work. A single callback or Fiber segment that runs synchronous CPU or blocking I/O also cannot be preempted by the cooperative scheduler. A small local comparison of 128, 256, and 512 item quanta showed the expected trade-off: 128 reduced median latency in some runs but increased drain-time tails, while 512 worsened timer and I/O tail latency. The independent 256 quantum gave the more balanced result below; it is a service policy, not a capacity limit.

`StreamSelectDriver` rebuilds PHP read/write arrays from active watches on each wait, at O(R + W) for R read and W write registrations, in addition to `stream_select()` costs. This remains unchanged. A local probe with 600 socket pairs exceeded this PHP build's `FD_SETSIZE`; no alternative data structure was introduced because the measurements do not isolate set construction as the dominant cost.

## Reproduction

Run `php benchmarks/scheduler_fairness.php [rounds] [callbacks-per-round] [streams-per-round]`. The benchmark first registers the readers, queues a callback backlog, makes every stream ready, and measures timer and I/O callback latency from the start of the loaded run. It reports p50/p95/p99 plus total drain time. To compare without yielding within this workload, temporarily raise all three Loop budget constants above the callback count, run the same command, then restore them.

Recorded on PHP 8.5.4 CLI in the current WSL environment, with 10,000 callbacks, one stream, and 50 rounds. The comparison uses the same code and environment: the first run sets each budget to 100,000 (effectively unbudgeted for this workload), the second uses 256:

| Metric | 100,000-item budgets p50 / p95 / p99 | 256-item budgets p50 / p95 / p99 |
| --- | --- | --- |
| Timer latency (us) | 4,983 / 5,451 / 8,515 | 138 / 275 / 285 |
| I/O latency (us) | 5,034 / 5,496 / 8,987 | 168 / 335 / 338 |
| Drain time (ms) | 5.09 / 5.55 / 9.08 | 5.19 / 5.71 / 6.68 |

The 300-stream run uses 1,000 callbacks for 10 rounds and measures I/O p50/p95/p99 of approximately 3.3 / 6.3 / 6.8 ms in this environment. Repeated runs show noticeable tail variation on the shared host; treat these as local observations, not portable performance guarantees.
