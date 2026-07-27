<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Support;

use RuntimeException;

final class LocalConnectProxyServer
{
    /**
     * @var resource|false|null
     */
    private $process = null;

    private int $port;

    private string $host;

    public function __construct(private readonly string $targetHost, private readonly int $targetPort, string $host = '127.0.0.1')
    {
        $this->host = $host;
        $this->port = $this->reservePort();
        $this->start();
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

    private function start(): void
    {
        $script = __DIR__ . '/../Fixtures/connect-proxy.php';
        $command = sprintf(
            '%s %s %s %d %s %d',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
            escapeshellarg($this->host),
            $this->port,
            escapeshellarg($this->targetHost),
            $this->targetPort
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', sys_get_temp_dir() . '/httpclient-proxy.log', 'a'],
            2 => ['file', sys_get_temp_dir() . '/httpclient-proxy-error.log', 'a'],
        ];

        $this->process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($this->process)) {
            throw new RuntimeException('Failed to start local CONNECT proxy');
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $this->waitUntilReady();
    }

    private function waitUntilReady(): void
    {
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client(sprintf('tcp://%s:%d', $this->host, $this->port), $errno, $errstr, 0.1);
            if (is_resource($socket)) {
                fclose($socket);
                return;
            }

            usleep(20_000);
        }

        throw new RuntimeException('Local CONNECT proxy did not become ready in time');
    }

    private function reservePort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException('Could not reserve local port for CONNECT proxy');
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if ($name === false || !str_contains($name, ':')) {
            throw new RuntimeException('Could not detect reserved local port for CONNECT proxy');
        }

        return (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);
    }
}
