<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Http;

use RuntimeException;

final class MultipartBuilder
{
    /**
     * @var list<array{type: string, name: string, value?: string, path?: string, filename?: string, contentType?: string}>
     */
    private array $parts = [];

    public static function make(): self
    {
        return new self();
    }

    public function field(string $name, string|int|float|bool|null $value): self
    {
        $clone = clone $this;
        $clone->parts[] = [
            'type' => 'field',
            'name' => $name,
            'value' => $value === null ? '' : (string) $value,
        ];

        return $clone;
    }

    public function file(string $name, string $path, ?string $filename = null, ?string $contentType = null): self
    {
        if (!is_file($path)) {
            throw new RuntimeException("Multipart file not found: {$path}");
        }

        $clone = clone $this;
        $clone->parts[] = [
            'type' => 'file',
            'name' => $name,
            'path' => $path,
            'filename' => $filename ?? basename($path),
            'contentType' => $contentType ?? 'application/octet-stream',
        ];

        return $clone;
    }

    /**
     * @return array{body: string, content_type: string}
     */
    public function build(): array
    {
        $boundary = 'fiber-event-loop-' . bin2hex(random_bytes(12));
        $body = '';

        foreach ($this->parts as $part) {
            $body .= "--{$boundary}\r\n";

            if ($part['type'] === 'field') {
                $body .= 'Content-Disposition: form-data; name="' . $part['name'] . "\"\r\n\r\n";
                $body .= ($part['value'] ?? '') . "\r\n";
                continue;
            }

            $body .= 'Content-Disposition: form-data; name="' . $part['name'] . '"; filename="' . ($part['filename'] ?? 'file') . "\"\r\n";
            $body .= 'Content-Type: ' . ($part['contentType'] ?? 'application/octet-stream') . "\r\n\r\n";
            $body .= file_get_contents($part['path']) . "\r\n";
        }

        $body .= "--{$boundary}--\r\n";

        return [
            'body' => $body,
            'content_type' => 'multipart/form-data; boundary=' . $boundary,
        ];
    }
}