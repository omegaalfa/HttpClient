<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Loop;

use Closure;

/** @internal One timer and its current heap location. */
final class TimerNode
{
    public bool $active = true;

    public function __construct(
        public readonly int $at,
        public readonly int $sequence,
        public ?Closure $callback,
    ) {
    }
}
