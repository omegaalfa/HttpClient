<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Http;

use RuntimeException;

final class Response
{
    /**
     * @var array<string, mixed>
     */
    private array $jsonCache = [];

    /**
     * @param array<string, mixed> $cookies
     */
    public function __construct(
        private readonly int $status,
        private readonly Headers $headers,
        private readonly string $body,
        private readonly array $cookies = [],
        private readonly bool $redirected = false
    ) {
    }

    public function status(): int
    {
        return $this->status;
    }

    public function headers(): Headers
    {
        return clone $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function text(): string
    {
        return $this->body;
    }

    public function json(bool $assoc = true): mixed
    {
        $key = $assoc ? 'assoc' : 'object';
        if (!array_key_exists($key, $this->jsonCache)) {
            $this->jsonCache[$key] = json_decode($this->body, $assoc, 512, JSON_THROW_ON_ERROR);
        }

        return $this->jsonCache[$key];
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function failed(): bool
    {
        return !$this->successful();
    }

    public function redirected(): bool
    {
        return $this->redirected;
    }

    /**
     * @return array<string, mixed>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function save(string $path): void
    {
        if (@file_put_contents($path, $this->body) === false) {
            throw new RuntimeException("Could not save response body to {$path}");
        }
    }
}