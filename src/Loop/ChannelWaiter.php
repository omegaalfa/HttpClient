<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Loop;

/** @internal State for one suspended channel send or receive. */
final class ChannelWaiter
{
    public ?int $queueId = null;
    public string $state = 'new';
    public mixed $value;
    public bool $hasValue = false;

    public function __construct(
        public readonly BoundedChannel $channel,
        public readonly string $direction,
        public ?WaitHandle $handle,
        mixed $value = null,
    ) {
        $this->value = $value;
    }
}
