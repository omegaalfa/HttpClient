<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Http;

use Omegaalfa\HttpClient\Http\Exceptions\ConnectionException;

/**
 * Framing incremental de Transfer-Encoding: chunked (RFC 9110 §7.1).
 *
 * Compartilhado pelo caminho materializado (Response) e pelo streaming
 * (StreamResponse) para que ambos validem o framing da mesma forma.
 */
final class ChunkedDecoder
{
    private string $rawBuffer = '';

    private string $decoded = '';

    private ?int $pendingChunkLength = null;

    private bool $waitingForTerminator = false;

    private bool $completed = false;

    public function push(string $data): void
    {
        $this->rawBuffer .= $data;
        $this->parse();
    }

    public function takeDecoded(): string
    {
        $chunk = $this->decoded;
        $this->decoded = '';

        return $chunk;
    }

    public function isCompleted(): bool
    {
        return $this->completed;
    }

    public function finish(): void
    {
        if (!$this->completed) {
            throw new ConnectionException('Incomplete chunked response body');
        }
    }

    private function parse(): void
    {
        while (!$this->completed) {
            if ($this->waitingForTerminator) {
                $lineEnd = strpos($this->rawBuffer, "\r\n");
                if ($lineEnd === false) {
                    return;
                }

                // Trailer opcional; linha em branco encerra o corpo.
                $line = substr($this->rawBuffer, 0, $lineEnd);
                $this->rawBuffer = substr($this->rawBuffer, $lineEnd + 2);

                if ($line === '') {
                    $this->completed = true;
                }

                continue;
            }

            if ($this->pendingChunkLength === null) {
                $lineEnd = strpos($this->rawBuffer, "\r\n");
                if ($lineEnd === false) {
                    return;
                }

                $line = substr($this->rawBuffer, 0, $lineEnd);
                $this->rawBuffer = substr($this->rawBuffer, $lineEnd + 2);
                $line = trim(explode(';', $line, 2)[0]);

                if ($line === '' || preg_match('/^[0-9a-fA-F]+$/', $line) !== 1) {
                    throw new ConnectionException('Invalid chunked response length');
                }

                $chunkLength = hexdec($line);
                if (!is_int($chunkLength)) {
                    throw new ConnectionException('Chunked response length is too large');
                }

                $this->pendingChunkLength = $chunkLength;
                continue;
            }

            if ($this->pendingChunkLength === 0) {
                $this->pendingChunkLength = null;
                $this->waitingForTerminator = true;
                continue;
            }

            if (strlen($this->rawBuffer) < $this->pendingChunkLength + 2) {
                return;
            }

            $payload = substr($this->rawBuffer, 0, $this->pendingChunkLength);
            $terminator = substr($this->rawBuffer, $this->pendingChunkLength, 2);

            if ($terminator !== "\r\n") {
                throw new ConnectionException('Invalid chunked response framing');
            }

            $this->rawBuffer = substr($this->rawBuffer, $this->pendingChunkLength + 2);
            $this->pendingChunkLength = null;
            $this->decoded .= $payload;
        }
    }
}
