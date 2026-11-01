<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Loop;

use RuntimeException;

/** Thrown into a task when a timed I/O wait expires before readiness. */
final class IoTimeoutException extends RuntimeException
{
}
