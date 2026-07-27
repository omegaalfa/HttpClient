<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Support;

use RuntimeException;

final class LocalHttpServer
{
    /**
     * @var resource|null
     */
    private $process = null;

    private int $port;

    private string $host;

    public function __construct(string $host = '127.0.0.1')
    {
        $this->host = $host;
        $this->port = $this->reservePort();
    }

    public function start(string $routerPath, string $workingDirectory): void
    {
        $phpBinary = PHP_BINARY;
        $command = sprintf(
            '%s -n -S %s:%d %s',
            escapeshellarg($phpBinary),
            $this->host,
            $this->port,
            escapeshellarg($routerPath)
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', '/tmp/httpclient-test-server.log', 'a'],
            2 => ['file', '/tmp/httpclient-test-server-error.log', 'a'],
        ];

        $this->process = proc_open($command, $descriptors, $pipes, $workingDirectory);

        if (!is_resource($this->process)) {
            throw new RuntimeException('Failed to start local HTTP test server');
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $this->waitUntilReady();
    }

    public function stop(): void
    {
        if (!is_resource($this->process)) {
            return;
        }

        proc_terminate($this->process);
        proc_close($this->process);
        $this->process = null;
    }

    public function baseUrl(): string
    {
        return sprintf('http://%s:%d', $this->host, $this->port);
    }

    private function waitUntilReady(): void
    {
        $deadline = microtime(true) + 2.0;

        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client(sprintf('tcp://%s:%d', $this->host, $this->port), $errno, $errstr, 0.1);
            if (is_resource($socket)) {
                fclose($socket);
                return;
            }

            usleep(20_000);
        }

        throw new RuntimeException('Local HTTP test server did not become ready in time');
    }

    private function reservePort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException('Could not reserve local port for tests');
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if ($name === false || !str_contains($name, ':')) {
            throw new RuntimeException('Could not detect reserved local port');
        }

        return (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);
    }
}
