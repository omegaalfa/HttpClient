<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Support;

use RuntimeException;

final class SelfSignedCertificate
{
    private string $directory;

    private string $combinedPem;

    public function __construct(string $commonName = '127.0.0.1')
    {
        $this->directory = sys_get_temp_dir() . '/httpclient-tls-' . bin2hex(random_bytes(6));
        if (!mkdir($concurrentDirectory = $this->directory, 0777, true) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException('Unable to create temporary certificate directory');
        }

        $keyPath = $this->directory . '/key.pem';
        $certPath = $this->directory . '/cert.pem';
        $this->combinedPem = $this->directory . '/combined.pem';

        $command = sprintf(
            'openssl req -x509 -newkey rsa:2048 -nodes -keyout %s -out %s -subj %s -days 1',
            escapeshellarg($keyPath),
            escapeshellarg($certPath),
            escapeshellarg('/CN=' . $commonName)
        );

        $this->runCommand($command);

        $combined = file_get_contents($certPath) . file_get_contents($keyPath);
        if (file_put_contents($this->combinedPem, $combined) === false) {
            throw new RuntimeException('Unable to write combined certificate');
        }
    }

    public function combinedPem(): string
    {
        return $this->combinedPem;
    }

    private function runCommand(string $command): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to run openssl command');
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException('OpenSSL certificate generation failed with exit code ' . $exitCode);
        }
    }
}
