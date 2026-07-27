<?php

declare(strict_types=1);

$host = $argv[1] ?? '127.0.0.1';
$port = (int) ($argv[2] ?? 0);
$certPath = $argv[3] ?? '';

if ($port <= 0 || $certPath === '' || !is_file($certPath)) {
    fwrite(STDERR, "Invalid HTTPS server arguments\n");
    exit(1);
}

$context = stream_context_create([
    'ssl' => [
        'local_cert' => $certPath,
        'allow_self_signed' => true,
        'verify_peer' => false,
    ],
]);

$server = stream_socket_server(
    sprintf('tcp://%s:%d', $host, $port),
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context
);

if ($server === false) {
    fwrite(STDERR, "Unable to start HTTPS server: {$errstr} ({$errno})\n");
    exit(1);
}

while ($client = @stream_socket_accept($server, 1)) {
    stream_set_blocking($client, true);
    while (($crypto = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) === 0) {
        usleep(10_000);
    }

    if ($crypto !== true) {
        fclose($client);
        continue;
    }

    $request = '';
    while (!str_contains($request, "\r\n\r\n")) {
        $chunk = fread($client, 8192);
        if ($chunk === '' || $chunk === false) {
            break;
        }

        $request .= $chunk;
    }

    $lines = preg_split('/\r\n|\n|\r/', trim($request)) ?: [];
    $requestLine = array_shift($lines) ?? 'GET / HTTP/1.1';
    [$method, $path] = array_pad(explode(' ', $requestLine, 3), 2, '/');

    $payload = json_encode([
        'ok' => true,
        'method' => $method,
        'path' => $path,
    ], JSON_THROW_ON_ERROR);

    $response = "HTTP/1.1 200 OK\r\n" .
        "Content-Type: application/json\r\n" .
        'Content-Length: ' . strlen($payload) . "\r\n" .
        "Connection: close\r\n\r\n" .
        $payload;

    fwrite($client, $response);
    fclose($client);
}
