# Cooperative task cancellation

`Task::cancel(): bool` requests cancellation. It returns `true` when the request is accepted and `false` when the task is already terminal or already has a request pending. A task ends in the `cancelled` state when the cancellation exception escapes its callable; a callable may catch `TaskCancelled` and finish normally.

Queued tasks are marked cancelled without starting their Fiber. For a suspended task, the loop removes its timer, I/O watch, or awaited-task waiter, then injects `TaskCancelled` at that suspension point with `Fiber::throw()`. This lets `finally` blocks run. Stale timer, pause, I/O, and await callbacks are invalidated and cannot resume that Fiber again. Canceling a task waiting on another task removes only that wait relationship; it does not cancel the awaited task.

If cancellation is requested while a Fiber is running, it is delivered at its next Loop suspension checkpoint. Synchronous PHP code cannot be interrupted. A cancellation request made by a task itself has the same checkpoint behavior.

`await()` throws `TaskCancelled` for a cancelled task. Ordinary task exceptions retain their existing `failed` state and propagation. `stop()` pauses the active run and remains resumable; `shutdown()` performs terminal cooperative cleanup as described in [SHUTDOWN.md](SHUTDOWN.md). Cancellation does not forcibly terminate arbitrary PHP code or guarantee that cleanup completes if that cleanup blocks or waits forever.
