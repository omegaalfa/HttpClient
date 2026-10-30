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
        $built = $this->buildToStream();
        rewind($built['stream']);
        $body = stream_get_contents($built['stream']);
        fclose($built['stream']);

        return [
            'body' => $body === false ? '' : $body,
            'content_type' => $built['content_type'],
        ];
    }

    /**
     * Constrói o corpo por partes em um buffer php://temp (memória até 2MB,
     * resto em disco), permitindo uploads grandes sem materializar o arquivo.
     *
     * @return array{stream: resource, content_type: string, content_length: int}
     */
    public function buildToStream(): array
    {
        $boundary = 'fiber-event-loop-' . bin2hex(random_bytes(12));
        $stream = fopen('php://temp/maxmemory:2097152', 'r+b');

        if ($stream === false) {
            throw new RuntimeException('Could not open multipart buffer');
        }

        foreach ($this->parts as $part) {
            $head = "--{$boundary}\r\n";

            if ($part['type'] === 'field') {
                $head .= 'Content-Disposition: form-data; name="' . $this->encodeDispositionParam($part['name']) . "\"\r\n\r\n";
                $this->writeToStream($stream, $head);
                $this->writeToStream($stream, ($part['value'] ?? '') . "\r\n");
                continue;
            }

            $head .= 'Content-Disposition: form-data; name="' . $this->encodeDispositionParam($part['name']) . '"; filename="' . $this->encodeDispositionParam($part['filename'] ?? 'file') . "\"\r\n";
            $head .= 'Content-Type: ' . ($part['contentType'] ?? 'application/octet-stream') . "\r\n\r\n";
            $this->writeToStream($stream, $head);
            $path = $part['path'] ?? null;
            if (!is_string($path)) {
                throw new RuntimeException('Multipart file path is missing');
            }

            $this->copyFileToStream($stream, $path);
            $this->writeToStream($stream, "\r\n");
        }

        $this->writeToStream($stream, "--{$boundary}--\r\n");

        return [
            'stream' => $stream,
            'content_type' => 'multipart/form-data; boundary=' . $boundary,
            'content_length' => (int)ftell($stream),
        ];
    }

    /**
     * @param resource $stream
     */
    private function writeToStream($stream, string $data): void
    {
        $offset = 0;
        $total = strlen($data);

        while ($offset < $total) {
            $written = fwrite($stream, substr($data, $offset, 65536));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not buffer multipart payload');
            }

            $offset += $written;
        }
    }

    /**
     * @param resource $stream
     */
    private function copyFileToStream($stream, string $path): void
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Multipart file not found: {$path}");
        }

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 65536);
                if ($chunk === false) {
                    throw new RuntimeException("Could not read multipart file: {$path}");
                }

                if ($chunk !== '') {
                    $this->writeToStream($stream, $chunk);
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Neutraliza aspas e caracteres de controle para impedir quebra do
     * parâmetro citado e injeção de headers no próprio multipart (RFC 7578).
     */
    private function encodeDispositionParam(string $value): string
    {
        return str_replace(
            ['"', "\r", "\n", "\0"],
            ['%22', '%0D', '%0A', '%00'],
            $value
        );
    }
}
