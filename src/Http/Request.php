<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Http;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly Headers $headers,
        public readonly mixed $body = null,
        public readonly array $query = [],
        public readonly array $files = [],
        public readonly ?MultipartBuilder $multipart = null,
        public readonly ?RequestOptions $options = null
    ) {
    }

    public function withUrl(string $url): self
    {
        return new self(
            $this->method,
            $url,
            clone $this->headers,
            $this->body,
            $this->query,
            $this->files,
            $this->multipart,
            $this->options
        );
    }
}