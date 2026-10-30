<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Http;

use Omegaalfa\FiberEventLoop\FiberEventLoop;
use Omegaalfa\HttpClient\Http\Exceptions\ConnectionException;
use Omegaalfa\HttpClient\Http\Exceptions\TimeoutException;
use RuntimeException;

final class StreamResponse
{
    private bool $closed = false;

    private bool $completed = false;

    private bool $chunked = false;

    private ?int $contentLength = null;

    private int $bytesRead = 0;

    private string $decodedBuffer = '';

    private ChunkedDecoder $chunkedDecoder;

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
        $this->chunked = $chunked;
        $this->contentLength = $contentLength;
        $this->chunkedDecoder = new ChunkedDecoder();

        if ($this->chunked) {
            $this->chunkedDecoder->push($initialBody);
        } else {
            $this->decodedBuffer = $initialBody;
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

        $length = max(1, $length);

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
        $decoded = $this->chunkedDecoder->takeDecoded();
        if ($decoded !== '') {
            $this->decodedBuffer .= $decoded;
            return;
        }

        if ($this->chunkedDecoder->isCompleted()) {
            $this->completed = true;
            $this->close();
            return;
        }

        $this->waitForReadable();
        $chunk = @fread($this->socket, 65536);

        if ($chunk === false) {
            throw new ConnectionException('Failed to read streamed chunked response body');
        }

        if ($chunk === '') {
            if (feof($this->socket)) {
                $this->chunkedDecoder->finish();
            }

            $this->loop->next();
            return;
        }

        $this->chunkedDecoder->push($chunk);
    }

    private function waitForReadable(): void
    {
        $deadlineNs = hrtime(true) + (int) ($this->options->readTimeout * 1_000_000_000);
        $this->ensureTotalTimeout();

        if (hrtime(true) >= $deadlineNs) {
            throw new TimeoutException('Read timed out');
        }

        $read = [$this->socket];
        $write = $except = [];
        $ready = @stream_select($read, $write, $except, 0, 0);
        if ($ready === false) {
            throw new ConnectionException('stream_select failed while waiting for stream data');
        }
        if ($ready > 0) {
            return;
        }

        if ($this->loop instanceof SocketReadinessInterface) {
            $this->loop->awaitStream($this->socket, true, false, min($deadlineNs, $this->totalDeadlineNs));
        } else {
            $this->pollReadableFallback($deadlineNs);
        }
        $this->ensureTotalTimeout();
        if (hrtime(true) >= $deadlineNs) {
            throw new TimeoutException('Read timed out');
        }
    }

    private function pollReadableFallback(int $deadlineNs): void
    {
        while (true) {
            $this->ensureTotalTimeout();
            if (hrtime(true) >= $deadlineNs) {
                throw new TimeoutException('Read timed out');
            }
            $read = [$this->socket];
            $write = $except = [];
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
