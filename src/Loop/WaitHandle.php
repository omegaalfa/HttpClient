<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Loop;

use Fiber;

/** @internal One cancellable suspension point owned by a task. */
final class WaitHandle
{
    public bool $active = true;
    public ?TimerNode $timerEntry = null;
    public ?TimerNode $timeoutEntry = null;
    public ?Task $awaitedTask = null;
    public ?Semaphore $semaphore = null;
    public ?int $semaphoreQueueId = null;
    public bool $semaphoreGranted = false;
    public ?ChannelWaiter $channelWaiter = null;

    /**
     * @param Fiber<null, mixed, void, mixed> $fiber
     */
    public function __construct(
        public readonly Task   $task,
        public readonly Fiber  $fiber,
        public readonly string $kind,
        public readonly ?int   $streamId = null,
        public readonly int    $generation = 0,
    ) {
    }
}
