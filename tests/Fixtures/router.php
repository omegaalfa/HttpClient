<?php

declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($uri === '/json') {
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'query' => $_GET,
    ], JSON_THROW_ON_ERROR);
    return;
}

if ($uri === '/post') {
    $raw = file_get_contents('php://input') ?: '';
    $files = [];
    foreach ($_FILES as $name => $file) {
        if (!is_array($file)) {
            continue;
        }

        $tmpName = $file['tmp_name'] ?? null;
        $files[$name] = [
            'name' => $file['name'] ?? null,
            'type' => $file['type'] ?? null,
            'size' => $file['size'] ?? null,
            'content' => is_string($tmpName) && is_file($tmpName)
                ? (string) file_get_contents($tmpName)
                : null,
        ];
    }

    header('Content-Type: application/json');
    echo json_encode([
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'POST',
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
        'raw' => $raw,
        'post' => $_POST,
        'files' => $files,
    ], JSON_THROW_ON_ERROR);
    return;
}

if ($uri === '/redirect') {
    header('Location: /json', true, 302);
    echo 'redirecting';
    return;
}

if ($uri === '/set-cookie') {
    header('Set-Cookie: session_id=abc123; Path=/');
    header('Content-Type: text/plain');
    echo 'cookie set';
    return;
}

if ($uri === '/check-cookie') {
    header('Content-Type: application/json');
    echo json_encode([
        'cookie' => $_SERVER['HTTP_COOKIE'] ?? '',
    ], JSON_THROW_ON_ERROR);
    return;
}

if ($uri === '/retry-500') {
    $counterFile = sys_get_temp_dir() . '/httpclient-retry-counter.txt';
    $counter = 0;
    if (is_file($counterFile)) {
        $counter = (int) file_get_contents($counterFile);
    }
    $counter++;
    file_put_contents($counterFile, (string) $counter);

    header('Content-Type: application/json', true, 500);
    echo json_encode(['attempt' => $counter], JSON_THROW_ON_ERROR);
    return;
}

if ($uri === '/retry-reset') {
    $counterFile = sys_get_temp_dir() . '/httpclient-retry-counter.txt';
    if (is_file($counterFile)) {
        unlink($counterFile);
    }

    header('Content-Type: application/json');
    echo json_encode(['reset' => true], JSON_THROW_ON_ERROR);
    return;
}

if ($uri === '/retry-counter') {
    $counterFile = sys_get_temp_dir() . '/httpclient-retry-counter.txt';
    $counter = is_file($counterFile) ? (int) file_get_contents($counterFile) : 0;
    header('Content-Type: application/json');
    echo json_encode(['count' => $counter], JSON_THROW_ON_ERROR);
    return;
}

if ($uri === '/slow-read') {
    usleep(300_000);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
    return;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode([
    'error' => 'not found',
    'path' => $uri,
], JSON_THROW_ON_ERROR);
