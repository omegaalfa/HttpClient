<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Http;

use JsonException;
use RuntimeException;

final class SseEvent
{
    public function __construct(
        private readonly string $data,
        private readonly ?string $event = null,
        private readonly ?string $id = null,
        private readonly ?int $retry = null,
        private readonly bool $done = false
    ) {
    }

    public function data(): string
    {
        return $this->data;
    }

    public function event(): ?string
    {
        return $this->event;
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function retry(): ?int
    {
        return $this->retry;
    }

    public function done(): bool
    {
        return $this->done;
    }

    public function json(bool $assoc = true): mixed
    {
        try {
            return json_decode($this->data, $assoc, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $preview = mb_substr($this->data, 0, 200);
            throw new RuntimeException('Invalid SSE JSON payload: ' . $preview, 0, $exception);
        }
    }
}