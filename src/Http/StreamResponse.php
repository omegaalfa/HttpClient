<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Http;

use Omegaalfa\FiberEventLoop\FiberEventLoop;
use Omegaalfa\HttpClient\Http\Exceptions\ConnectionException;
use Omegaalfa\HttpClient\Http\Exceptions\TimeoutException;
use RuntimeException;

final class StreamResponse
{
    private string $buffer;

    private bool $closed = false;

    private bool $completed = false;

    private bool $chunked = false;

    private ?int $contentLength = null;

    private int $bytesRead = 0;

    private string $rawBuffer = '';

    private string $decodedBuffer = '';

    private ?int $pendingChunkLength = null;

    private bool $waitingForFinalChunkCrlf = false;

    private int $totalDeadlineNs;

    /**
     * @param array<string, mixed> $cookies
     */
    public function __construct(
        private readonly FiberEventLoop $loop,
        private readonly ConnectionPool $connectionPool,
        /** @var resource */
        private $socket,
        private readonly RequestOptions $options,
        private readonly string $poolKey,
        private readonly bool $poolable,
        private readonly int $status,
        private readonly Headers $headers,
        private readonly array $cookies = [],
        private readonly bool $redirected = false,
        string $initialBody = '',
        bool $chunked = false,
        ?int $contentLength = null,
        ?int $totalDeadlineNs = null
    ) {
        $this->buffer = $initialBody;
        $this->rawBuffer = $initialBody;
        $this->chunked = $chunked;
        $this->contentLength = $contentLength;

        if (!$this->chunked) {
            $this->decodedBuffer = $initialBody;
            $this->rawBuffer = '';
        }

        $this->totalDeadlineNs = $totalDeadlineNs
            ?? (hrtime(true) + (int) ($this->options->totalTimeout * 1_000_000_000));
    }

    public function status(): int
    {
        return $this->status;
    }

    public function headers(): Headers
    {
        return clone $this->headers;
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

    public function isComplete(): bool
    {
        return $this->completed;
    }

    public function readChunk(int $length = 65536): ?string
    {
        if ($length <= 0) {
            throw new RuntimeException('Chunk length must be greater than zero');
        }

        try {
            while (true) {
                if ($this->chunked) {
                    if ($this->decodedBuffer !== '') {
                        $chunk = substr($this->decodedBuffer, 0, $length);
                        $this->decodedBuffer = substr($this->decodedBuffer, strlen($chunk));
                        return $chunk;
                    }

                    if ($this->completed) {
                        return null;
                    }

                    $this->pumpChunkedBuffer();
                    continue;
                }

                if ($this->decodedBuffer !== '') {
                    $chunk = substr($this->decodedBuffer, 0, $length);
                    $this->decodedBuffer = substr($this->decodedBuffer, strlen($chunk));
                    $this->bytesRead += strlen($chunk);

                    if ($this->contentLength !== null && $this->bytesRead >= $this->contentLength) {
                        $this->completed = true;
                        $this->close();
                    }

                    return $chunk;
                }

                if ($this->completed) {
                    return null;
                }

                $this->pumpPlainBuffer($length);
            }
        } catch (\Throwable $exception) {
            $this->close();
            throw $exception;
        }
    }

    public function consume(): string
    {
        $body = '';
        while (($chunk = $this->readChunk()) !== null) {
            $body .= $chunk;
        }

        return $body;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if (!is_resource($this->socket)) {
            return;
        }

        if ($this->poolable && $this->completed) {
            $this->connectionPool->release($this->socket, true, $this->poolKey);
            return;
        }

        fclose($this->socket);
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * @return resource
     */
    public function socket()
    {
        return $this->socket;
    }

    private function pumpPlainBuffer(int $length): void
    {
        if ($this->contentLength !== null) {
            $remaining = $this->contentLength - $this->bytesRead;
            if ($remaining <= 0) {
                $this->completed = true;
                $this->close();
                return;
            }

            $length = min($length, $remaining);
        }

        $this->waitForReadable();
        $chunk = @fread($this->socket, $length);

        if ($chunk === false) {
            throw new ConnectionException('Failed to read streamed response body');
        }

        if ($chunk === '') {
            if (feof($this->socket)) {
                if ($this->contentLength !== null && $this->bytesRead < $this->contentLength) {
                    throw new ConnectionException('Incomplete streamed response body');
                }

                $this->completed = true;
                $this->close();
                return;
            }

            $this->loop->next();
            return;
        }

        $this->decodedBuffer .= $chunk;
    }

    private function pumpChunkedBuffer(): void
    {
        while (true) {
            if ($this->waitingForFinalChunkCrlf) {
                if (strlen($this->rawBuffer) < 2) {
                    break;
                }

                if (substr($this->rawBuffer, 0, 2) !== "\r\n") {
                    throw new ConnectionException('Invalid chunked response terminator');
                }

                $this->rawBuffer = substr($this->rawBuffer, 2);
                $this->waitingForFinalChunkCrlf = false;
                $this->completed = true;
                $this->close();
                break;
            }

            if ($this->pendingChunkLength === null) {
                $lineEnd = strpos($this->rawBuffer, "\r\n");
                if ($lineEnd === false) {
                    break;
                }

                $line = substr($this->rawBuffer, 0, $lineEnd);
                $this->rawBuffer = substr($this->rawBuffer, $lineEnd + 2);
                $line = trim(explode(';', $line, 2)[0]);

                if ($line === '' || preg_match('/^[0-9a-fA-F]+$/', $line) !== 1) {
                    throw new ConnectionException('Invalid chunked response length');
                }

                $chunkLength = hexdec($line);

                if ($chunkLength === 0) {
                    $this->pendingChunkLength = 0;
                    $this->waitingForFinalChunkCrlf = true;
                    continue;
                }

                $this->pendingChunkLength = $chunkLength;
            }

            if ($this->pendingChunkLength === 0) {
                continue;
            }

            if (strlen($this->rawBuffer) < $this->pendingChunkLength + 2) {
                break;
            }

            $payload = substr($this->rawBuffer, 0, $this->pendingChunkLength);
            $terminator = substr($this->rawBuffer, $this->pendingChunkLength, 2);

            if ($terminator !== "\r\n") {
                throw new ConnectionException('Invalid chunked response framing');
            }

            $this->rawBuffer = substr($this->rawBuffer, $this->pendingChunkLength + 2);
            $this->pendingChunkLength = null;
            $this->decodedBuffer .= $payload;

            if ($this->decodedBuffer !== '') {
                return;
            }
        }

        if ($this->completed) {
            return;
        }

        $this->waitForReadable();
        $chunk = @fread($this->socket, 65536);

        if ($chunk === false) {
            throw new ConnectionException('Failed to read streamed chunked response body');
        }

        if ($chunk === '') {
            if (feof($this->socket)) {
                throw new ConnectionException('Incomplete chunked response body');
            }

            $this->loop->next();
            return;
        }

        $this->rawBuffer .= $chunk;
        $this->pumpChunkedBuffer();
    }

    private function waitForReadable(): void
    {
        $deadlineNs = hrtime(true) + (int) ($this->options->readTimeout * 1_000_000_000);

        while (true) {
            $this->ensureTotalTimeout();

            if (hrtime(true) >= $deadlineNs) {
                throw new TimeoutException('Read timed out');
            }

            $read = [$this->socket];
            $write = [];
            $except = [];
            $ready = @stream_select($read, $write, $except, 0, 0);

            if ($ready === false) {
                throw new ConnectionException('stream_select failed while waiting for stream data');
            }

            if ($ready > 0) {
                return;
            }

            $this->loop->next();
        }
    }

    private function ensureTotalTimeout(): void
    {
        if (hrtime(true) >= $this->totalDeadlineNs) {
            throw new TimeoutException('Total request timeout exceeded');
        }
    }
}