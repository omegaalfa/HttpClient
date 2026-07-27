<?php

declare(strict_types=1);

$host = $argv[1] ?? '127.0.0.1';
$port = (int) ($argv[2] ?? 0);
$targetHost = $argv[3] ?? '127.0.0.1';
$targetPort = (int) ($argv[4] ?? 0);

if ($port <= 0 || $targetPort <= 0) {
    fwrite(STDERR, "Invalid proxy arguments\n");
    exit(1);
}

$server = stream_socket_server(sprintf('tcp://%s:%d', $host, $port), $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "Unable to start CONNECT proxy: {$errstr} ({$errno})\n");
    exit(1);
}

while ($client = @stream_socket_accept($server, 1)) {
    $request = '';
    while (!str_contains($request, "\r\n\r\n")) {
        $chunk = fread($client, 8192);
        if ($chunk === '' || $chunk === false) {
            break;
        }

        $request .= $chunk;
    }

    $lines = preg_split('/\r\n|\n|\r/', trim($request)) ?: [];
    $requestLine = array_shift($lines) ?? '';

    if (!preg_match('#^CONNECT\s+([^\s]+)\s+HTTP/1\.[01]$#i', $requestLine, $matches)) {
        fwrite($client, "HTTP/1.1 405 Method Not Allowed\r\nConnection: close\r\n\r\n");
        fclose($client);
        continue;
    }

    $upstream = stream_socket_client(sprintf('tcp://%s:%d', $targetHost, $targetPort), $upstreamErrno, $upstreamErrstr, 1);
    if ($upstream === false) {
        fwrite($client, "HTTP/1.1 502 Bad Gateway\r\nConnection: close\r\n\r\n");
        fclose($client);
        continue;
    }

    fwrite($client, "HTTP/1.1 200 Connection Established\r\nProxy-Agent: local-test-proxy\r\n\r\n");

    stream_set_blocking($client, false);
    stream_set_blocking($upstream, false);

    while (is_resource($client) && is_resource($upstream)) {
        $read = [$client, $upstream];
        $write = [];
        $except = [];
        $ready = @stream_select($read, $write, $except, 1);
        if ($ready === false) {
            break;
        }

        foreach ($read as $stream) {
            $data = fread($stream, 8192);
            if ($data === '' || $data === false) {
                continue;
            }

            if ($stream === $client) {
                fwrite($upstream, $data);
            } else {
                fwrite($client, $data);
            }
        }

        if (feof($client) || feof($upstream)) {
            break;
        }
    }

    fclose($upstream);
    fclose($client);
}
