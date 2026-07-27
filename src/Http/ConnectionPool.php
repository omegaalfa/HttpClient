<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Http;

final class ConnectionPool
{
    /**
     * @var array<string, list<resource>>
     */
    private array $connections = [];

    private int $maxPerOrigin;

    private int $maxTotal;

    public function __construct(int $maxPerOrigin = 8, int $maxTotal = 128)
    {
        $this->maxPerOrigin = max(1, $maxPerOrigin);
        $this->maxTotal = max(1, $maxTotal);
    }

    /**
     * @return resource|null
     */
    public function acquire(string $scheme, string $host, int $port, ?string $poolKey = null)
    {
        $key = $poolKey ?? $this->key($scheme, $host, $port);
        if (!isset($this->connections[$key])) {
            return null;
        }

        while ($this->connections[$key] !== []) {
            $socket = array_pop($this->connections[$key]);
            if (!is_resource($socket)) {
                continue;
            }

            if (!$this->isReusable($socket)) {
                fclose($socket);
                continue;
            }

            return $socket;
        }

        unset($this->connections[$key]);
        return null;
    }

    /**
     * @param resource $socket
     */
    public function release($socket, bool $keepAlive = false, ?string $poolKey = null): void
    {
        if (!is_resource($socket)) {
            return;
        }

        if (!$keepAlive) {
            fclose($socket);
            return;
        }

        $key = $poolKey;
        if ($key === null) {
            $meta = stream_get_meta_data($socket);
            $uri = (string) ($meta['uri'] ?? '');
            if ($uri === '') {
                fclose($socket);
                return;
            }

            $parts = parse_url($uri);
            if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
                fclose($socket);
                return;
            }

            $scheme = strtolower((string) $parts['scheme']);
            $host = strtolower((string) $parts['host']);
            $port = (int) ($parts['port'] ?? ($scheme === 'tls' ? 443 : 80));
            $key = $this->key($scheme, $host, $port);
        }

        $this->evictIfNeeded();
        $bucket = $this->connections[$key] ?? [];
        if (count($bucket) >= $this->maxPerOrigin) {
            fclose($socket);
            return;
        }

        $bucket[] = $socket;
        $this->connections[$key] = $bucket;
    }

    public function __destruct()
    {
        foreach ($this->connections as $bucket) {
            foreach ($bucket as $socket) {
                if (is_resource($socket)) {
                    fclose($socket);
                }
            }
        }
    }

    /**
     * A readable idle socket contains leftover bytes or was closed by the peer.
     * Neither case is safe to reuse.
     *
     * @param resource $socket
     */
    private function isReusable($socket): bool
    {
        if (feof($socket)) {
            return false;
        }

        $read = [$socket];
        $write = [];
        $except = [];
        $ready = @stream_select($read, $write, $except, 0, 0);

        return $ready === 0;
    }

    private function evictIfNeeded(): void
    {
        $total = 0;
        foreach ($this->connections as $bucket) {
            $total += count($bucket);
        }

        if ($total < $this->maxTotal) {
            return;
        }

        foreach ($this->connections as $key => $bucket) {
            if ($bucket === []) {
                unset($this->connections[$key]);
                continue;
            }

            $socket = array_shift($bucket);
            if (is_resource($socket)) {
                fclose($socket);
            }

            if ($bucket === []) {
                unset($this->connections[$key]);
            } else {
                $this->connections[$key] = $bucket;
            }

            return;
        }
    }

    private function key(string $scheme, string $host, int $port): string
    {
        return strtolower($scheme) . '://' . strtolower($host) . ':' . $port;
    }
}