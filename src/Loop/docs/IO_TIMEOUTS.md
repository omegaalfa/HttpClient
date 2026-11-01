# Timeouts for I/O waits

`Loop::waitReadable()` and `Loop::waitWritable()` accept an optional timeout in
seconds:

```php
$task = new Task(static function () use ($socket): string {
    try {
        Loop::get()->waitReadable($socket, 0.25);
    } catch (IoTimeoutException) {
        return 'deadline expired';
    }

    return (string) fread($socket, 4096);
});
```

Omitting the argument or passing `null` keeps the existing unbounded wait.
Timeouts use the Loop's monotonic `hrtime()` timer heap. A timeout throws
`IoTimeoutException` at the suspended operation; it is an ordinary task failure
if the task does not catch it.

Zero is valid and means an immediate timeout. Negative, `INF`, `-INF`, `NAN`,
and finite values outside the representable monotonic timer range throw
`InvalidArgumentException` before an I/O watch is registered. Values are in
seconds and may be integers or floats.

I/O readiness, timeout, and task cancellation all compete to deactivate the
same `WaitHandle`. The first completion the Loop processes wins. It removes the
other registration before resuming or throwing into the task, so stale I/O
events and invalidated timers cannot resume the Fiber a second time. If a
readiness event is returned by the driver at the timeout boundary, the Loop
dispatches that event in the current cycle; if the timeout callback has already
run, the wait has expired. Scheduler ordering therefore determines a boundary
race, but completion remains exactly once.

Canceling a timed I/O wait removes both its driver watch and timer. Shutdown
clears timer callbacks and I/O registrations, then delivers the existing
cooperative task cancellation. Streams remain owned by their caller and are
never closed by timeout, cancellation, or shutdown. Cancellation cleanup may
register new waits according to the existing shutdown contract.
