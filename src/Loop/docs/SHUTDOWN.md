# Event Loop shutdown

The Loop has three lifecycle states: `active`, `shutting_down`, and `shut_down`.

- `stop()` interrupts the current `run()` call. The Loop stays `active`; queued callbacks, timers, I/O waits, and tasks remain available to a later `run()`.
- `shutdown()` transitions to `shutting_down`, discards queued user callbacks and pending timers, removes all current read/write watches, and requests cancellation of every active task. The Loop drains cancellation delivery and cooperative cleanup, then becomes `shut_down` once no task remains.
- A shutdown Loop is terminal. Repeated `shutdown()` calls are harmless; `run()`, task creation, `defer()`, and public `after()` calls are rejected after shutdown begins.

When called outside `run()`, `shutdown()` drives cleanup synchronously. When called from a task Fiber, deferred callback, or timer callback, it only begins shutdown; the current stack unwinds normally and the active `run()` drains cleanup. If called from a Fiber, that task receives cancellation at its next Loop suspension point. A synchronous callback can continue executing after the call until it returns or reaches such a point.

Existing tasks may use `sleep()`, `pause()`, `waitReadable()`, and `waitWritable()` while unwinding cancellation so asynchronous `finally` cleanup can finish. These waits belong to the cancellation cleanup of an existing task; creating new tasks, scheduling public callbacks/timers, or starting unrelated I/O waits is rejected. Cleanup that suspends waits for its source. If it never becomes ready, shutdown can remain in `shutting_down`; a Fiber suspended outside Loop primitives is diagnosed as stranded. There is no forced interruption or time limit. A task that catches cancellation may continue and delay shutdown. Synchronous blocking PHP code likewise cannot be interrupted.

Exceptions thrown by cleanup are retained as task failures. Once all tasks have settled and the Loop is terminal, `shutdown()` reports an unobserved cleanup failure; the original exception is available as the previous exception and remains available from the failed `Task`.

Shutdown releases the Loop's references to timer callbacks and stream registrations. It never closes streams supplied by callers. Cleanup exceptions remain task failures and are surfaced by `shutdown()` after the Loop reaches its terminal state. The I/O driver contract and stream portability limits are documented in [IO_DRIVERS.md](IO_DRIVERS.md).
