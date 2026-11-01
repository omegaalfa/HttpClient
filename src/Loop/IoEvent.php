<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Loop;

use Throwable;

final class IoEvent
{
    public function __construct(
        public readonly int $streamId,
        public readonly int $generation,
        public readonly string $operation,
        public readonly ?Throwable $error = null,
    ) {
    }
}
