<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Http;

use Omegaalfa\FiberEventLoop\FiberEventLoop;
use Omegaalfa\HttpClient\Http\Exceptions\ConnectionException;
use Omegaalfa\HttpClient\Http\Exceptions\TimeoutException;

/**
 * Transporte real sobre sockets TCP/TLS, com proxy HTTP/CONNECT, keep-alive
 * com pool por origem e suporte a corpo em streaming.
 */
final class SocketTransport implements TransportInterface
{
    private FiberEventLoop $loop;

    private ConnectionPool $connectionPool;

    public function __construct(FiberEventLoop $loop, ?ConnectionPool $connectionPool = null)
    {
        $this->loop = $loop;
        $this->connectionPool = $connectionPool ?? new ConnectionPool();
    }

    public function send(Request $request, RequestOptions $options): Response
    {
        return $this->sendWithRedirects($request, $options, $options->followRedirects);
    }

    public function sendStream(Request $request, RequestOptions $options): StreamResponse
    {
        return $this->sendStreamWithRedirects($request, $options, $options->followRedirects);
    }

    private function sendStreamWithRedirects(Request $request, RequestOptions $options, int $redirectsRemaining): StreamResponse
    {
        $totalDeadlineNs = hrtime(true) + (int)($options->totalTimeout * 1_000_000_000);
        $prepared = $this->prepareRequest($request, $options);
        $url = parse_url($prepared['url']);

        if ($url === false || !isset($url['host'])) {
            throw new ConnectionException('Invalid request URL', $request);
        }

        $scheme = strtolower($url['scheme'] ?? 'http');
        $host = $url['host'];
        $port = (int)($url['port'] ?? ($scheme === 'https' ? 443 : 80));
        $path = ($url['path'] ?? '/') . (isset($url['query']) ? '?' . $url['query'] : '');
        $requestTarget = ($options->proxy !== null && $scheme === 'http')
            ? $prepared['url']
            : $path;
        $poolKey = $this->connectionPoolKey($scheme, $host, $port, $options);
        $socket = $this->openSocket($scheme, $host, $port, $options, $poolKey, $totalDeadlineNs);
        $keepAlive = false;
        $poolable = !($options->proxy !== null && $scheme === 'https');

        try {
            $this->writeRequest($socket, $prepared['method'], $requestTarget, $prepared['headers'], $prepared['body'], $options, $totalDeadlineNs);
            [$status, $headers, $bodyBuffer, $contentLength, $chunked] = $this->readResponseHead($socket, $options, $totalDeadlineNs, $prepared['method']);

            $connectionHeader = strtolower((string)($headers->get('Connection') ?? ''));
            $keepAlive = $options->keepAlive && $connectionHeader !== 'close';

            $cookies = $options->cookieJar->storeFromHeaders($headers, $host);

            if ($redirectsRemaining > 0 && $this->isRedirect($status) && $headers->has('Location')) {
                $location = $this->resolveRedirectUrl($prepared['url'], (string)$headers->get('Location'));
                $redirectedRequest = $this->buildRedirectedRequest($request, $options, $prepared['url'], $location, $status);

                $this->connectionPool->release($socket, false, $poolKey);
                return $this->sendStreamWithRedirects($redirectedRequest, $redirectedRequest->options ?? $options, $redirectsRemaining - 1);
            }

            return new StreamResponse(
                $this->loop,
                $this->connectionPool,
                $socket,
                $options,
                $poolKey,
                $keepAlive && $poolable,
                $status,
                $headers,
                $cookies,
                false,
                $bodyBuffer,
                $chunked,
                $contentLength,
                $totalDeadlineNs
            );
        } catch (\Throwable $exception) {
            if (is_resource($socket)) {
                $this->connectionPool->release($socket, false, $poolKey);
            }

            throw $exception;
        }
    }

    private function sendWithRedirects(Request $request, RequestOptions $options, int $redirectsRemaining): Response
    {
        $totalDeadlineNs = hrtime(true) + (int)($options->totalTimeout * 1_000_000_000);
        $prepared = $this->prepareRequest($request, $options);
        $url = parse_url($prepared['url']);

        if ($url === false || !isset($url['host'])) {
            throw new ConnectionException('Invalid request URL', $request);
        }

        $scheme = strtolower($url['scheme'] ?? 'http');
        $host = $url['host'];
        $port = (int)($url['port'] ?? ($scheme === 'https' ? 443 : 80));
        $path = ($url['path'] ?? '/') . (isset($url['query']) ? '?' . $url['query'] : '');
        $requestTarget = ($options->proxy !== null && $scheme === 'http')
            ? $prepared['url']
            : $path;
        $poolKey = $this->connectionPoolKey($scheme, $host, $port, $options);
        $socket = $this->openSocket($scheme, $host, $port, $options, $poolKey, $totalDeadlineNs);
        $keepAlive = false;
        $poolable = !($options->proxy !== null && $scheme === 'https');

        try {
            $this->writeRequest($socket, $prepared['method'], $requestTarget, $prepared['headers'], $prepared['body'], $options, $totalDeadlineNs);
            [$status, $headers, $body] = $this->readResponse($socket, $options, $totalDeadlineNs, $prepared['method']);

            $connectionHeader = strtolower((string)($headers->get('Connection') ?? ''));
            $keepAlive = $options->keepAlive && $connectionHeader !== 'close';

            $cookies = $options->cookieJar->storeFromHeaders($headers, $host);

            if ($redirectsRemaining > 0 && $this->isRedirect($status) && $headers->has('Location')) {
                $location = $this->resolveRedirectUrl($prepared['url'], (string)$headers->get('Location'));
                $redirectedRequest = $this->buildRedirectedRequest($request, $options, $prepared['url'], $location, $status);

                $response = $this->sendWithRedirects($redirectedRequest, $redirectedRequest->options ?? $options, $redirectsRemaining - 1);
                return new Response($response->status(), $response->headers(), $response->body(), $response->cookies(), true);
            }

            return new Response($status, $headers, $body, $cookies, false);
        } finally {
            $this->connectionPool->release($socket, $keepAlive && $poolable, $poolKey);
        }
    }

    /**
     * @return array{url: string, method: string, headers: Headers, body: string|resource}
     */
    private function prepareRequest(Request $request, RequestOptions $options): array
    {
        $headers = Headers::from($request->headers);
        $url = $request->url;

        if ($request->query !== []) {
            $queryString = http_build_query($request->query);
            $url .= str_contains($url, '?') ? '&' . $queryString : '?' . $queryString;
        }

        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '/';
        $isSecure = strtolower($parsed['scheme'] ?? 'http') === 'https';
        $requestTarget = $path . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
        if ($options->proxy !== null && !$isSecure) {
            $requestTarget = $url;
        }
        $this->assertRequestLinePart(strtoupper($request->method), true);
        $this->assertRequestLinePart($requestTarget, false);

        $hostHeader = $host;
        if (isset($parsed['port'])) {
            $defaultPort = $isSecure ? 443 : 80;
            if ((int)$parsed['port'] !== $defaultPort) {
                $hostHeader .= ':' . $parsed['port'];
            }
        }

        if ($options->userAgent !== '') {
            $headers->set('User-Agent', $options->userAgent);
        }

        if ($options->compression && !$headers->has('Accept-Encoding')) {
            $headers->set('Accept-Encoding', 'gzip');
        }

        if ($options->bearerToken !== null) {
            $headers->set('Authorization', 'Bearer ' . $options->bearerToken);
        }

        if ($options->basicAuth !== null) {
            $headers->set('Authorization', 'Basic ' . base64_encode($options->basicAuth[0] . ':' . $options->basicAuth[1]));
        }

        $cookieHeader = $options->cookieJar->headerFor($host, $path, $isSecure);
        if ($cookieHeader !== null) {
            $headers->set('Cookie', $cookieHeader);
        }

        $headers->set('Host', $hostHeader);
        $headers->set('Connection', $options->keepAlive ? 'keep-alive' : 'close');

        $body = '';
        if ($request->multipart !== null || $request->files !== []) {
            $multipart = $request->multipart ?? MultipartBuilder::make();
            foreach ($request->files as $name => $filePath) {
                $multipart = $multipart->file((string)$name, (string)$filePath);
            }

            if (is_array($request->body)) {
                foreach ($request->body as $name => $value) {
                    $multipart = $multipart->field((string)$name, is_scalar($value) || $value === null ? $value : json_encode($value));
                }
            }

            $built = $multipart->buildToStream();
            $body = $built['stream'];
            $headers->set('Content-Type', $built['content_type']);
            $headers->set('Content-Length', (string)$built['content_length']);
        } elseif (is_array($request->body)) {
            if (($headers->get('Content-Type') ?? '') === 'application/x-www-form-urlencoded') {
                $body = http_build_query($request->body);
            } else {
                $body = json_encode($request->body, JSON_THROW_ON_ERROR);
                if ($options->sendJson && !$headers->has('Content-Type')) {
                    $headers->set('Content-Type', 'application/json');
                }
                if ($options->sendJson && !$headers->has('Accept')) {
                    $headers->set('Accept', 'application/json');
                }
            }
        } elseif (is_string($request->body)) {
            $body = $request->body;
        } elseif ($request->body !== null) {
            $body = json_encode($request->body, JSON_THROW_ON_ERROR);
            if (!$headers->has('Content-Type')) {
                $headers->set('Content-Type', 'application/json');
            }
        }

        if (is_string($body) && $body !== '') {
            $headers->set('Content-Length', (string)strlen($body));
        }

        return [
            'url' => $url,
            'method' => $request->method,
            'headers' => $headers,
            'body' => $body,
        ];
    }

    private function buildRequestHead(string $method, string $path, Headers $headers): string
    {
        $method = strtoupper($method);
        $path = $path === '' ? '/' : $path;

        $lines = [sprintf('%s %s HTTP/1.1', $method, $path)];
        foreach ($headers->toLines() as $line) {
            $lines[] = $line;
        }

        return implode("\r\n", $lines) . "\r\n\r\n";
    }

    /** Reject invalid method tokens and request-target control/whitespace bytes. */
    private function assertRequestLinePart(string $value, bool $method): void
    {
        $length = strlen($value);
        if ($length === 0) {
            throw new \InvalidArgumentException($method ? 'HTTP method must not be empty' : 'HTTP request target must not be empty');
        }

        for ($i = 0; $i < $length; $i++) {
            $byte = ord($value[$i]);
            if ($method) {
                $char = $value[$i];
                $valid = ($byte >= 65 && $byte <= 90)
                    || ($byte >= 97 && $byte <= 122)
                    || ($byte >= 48 && $byte <= 57)
                    || str_contains("!#$%&'*+-.^_`|~", $char);
                if (!$valid) {
                    throw new \InvalidArgumentException('Invalid character in HTTP method');
                }
            } elseif ($byte <= 32 || $byte === 127) {
                throw new \InvalidArgumentException('Invalid control or whitespace character in HTTP request target');
            }
        }
    }

    private function buildRequestPayload(string $method, string $path, Headers $headers, string $body): string
    {
        return $this->buildRequestHead($method, $path, $headers) . $body;
    }

    /**
     * Escreve a requisição no socket. Corpos em stream (multipart grande) são
     * copiados por segmentos, sem materializar o payload completo em memória.
     *
     * @param resource $socket
     * @param string|resource $body
     */
    private function writeRequest($socket, string $method, string $requestTarget, Headers $headers, $body, RequestOptions $options, ?int $totalDeadlineNs = null): void
    {
        if (is_string($body)) {
            $this->writePayload($socket, $this->buildRequestPayload($method, $requestTarget, $headers, $body), $options, $totalDeadlineNs);
            return;
        }
        if (!is_resource($body)) {
            throw new ConnectionException('Request body must be a string or stream');
        }

        try {
            $this->writePayload($socket, $this->buildRequestHead($method, $requestTarget, $headers), $options, $totalDeadlineNs);
            $this->writePayload($socket, $body, $options, $totalDeadlineNs);
        } finally {
            if (is_resource($body)) {
                fclose($body);
            }
        }
    }

    /**
     * @return resource
     */
    private function openSocket(string $scheme, string $host, int $port, RequestOptions $options, string $poolKey, ?int $totalDeadlineNs = null)
    {
        $connectHost = $host;
        $connectPort = $port;
        $connectScheme = $scheme;
        $proxy = null;

        if ($options->proxy !== null) {
            $proxy = $this->parseProxy($options->proxy);
            $connectHost = $proxy['host'];
            $connectPort = $proxy['port'];
            $connectScheme = 'http';
        }

        $transport = $connectScheme === 'https' ? 'tls' : 'tcp';
        $pooled = $this->connectionPool->acquire($transport, $connectHost, $connectPort, $poolKey);
        if (is_resource($pooled)) {
            stream_set_blocking($pooled, false);
            stream_set_read_buffer($pooled, 0);
            stream_set_write_buffer($pooled, 0);
            return $pooled;
        }

        $contextOptions = [];

        if ($connectScheme === 'https') {
            $contextOptions['ssl'] = [
                'verify_peer' => $options->verifySsl,
                'verify_peer_name' => $options->verifySsl,
                'allow_self_signed' => !$options->verifySsl,
                'peer_name' => $connectHost,
                'SNI_enabled' => true,
            ];
        }

        $context = stream_context_create($contextOptions);
        $flags = $connectScheme === 'https'
            ? STREAM_CLIENT_CONNECT
            : STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT;
        $timeout = $connectScheme === 'https' ? $options->connectTimeout : 0;

        $socket = @stream_socket_client(
            "{$transport}://{$connectHost}:{$connectPort}",
            $errno,
            $errstr,
            $timeout,
            $flags,
            $context
        );

        if ($socket === false) {
            throw new ConnectionException("Connection failed: {$errstr} ({$errno})");
        }

        stream_set_blocking($socket, false);
        stream_set_read_buffer($socket, 0);
        stream_set_write_buffer($socket, 0);

        $deadlineNs = $totalDeadlineNs ?? (hrtime(true) + (int)($options->totalTimeout * 1_000_000_000));
        if ($connectScheme !== 'https') {
            $this->waitForSocket($socket, false, true, $options->connectTimeout, 'Connection timed out', $deadlineNs);
            $this->assertSocketConnected($socket);
        }

        if ($proxy !== null && $scheme === 'https') {
            $this->establishConnectTunnel($socket, $host, $port, $options, $deadlineNs);
            $this->enableTlsOverSocket($socket, $host, $options, $deadlineNs);
        }

        return $socket;
    }

    /**
     * Connect assíncrono também sinaliza prontidão de escrita quando falha
     * (ex.: ECONNREFUSED); sem peer remoto, a conexão não foi estabelecida.
     *
     * @param resource $socket
     */
    private function assertSocketConnected($socket): void
    {
        if (@stream_socket_get_name($socket, true) !== false) {
            return;
        }

        // SO_ERROR só é consultado no caminho de erro: o Socket importado
        // compartilha o fd e o encerra ao ser destruído.
        $errorCode = 0;
        $imported = @socket_import_stream($socket);
        if ($imported !== false) {
            $errorCode = (int)@socket_get_option($imported, SOL_SOCKET, SO_ERROR);
        }

        $message = $errorCode > 0 && function_exists('socket_strerror')
            ? socket_strerror($errorCode)
            : 'unable to establish connection';

        throw new ConnectionException("Connection failed: {$message} ({$errorCode})");
    }

    private function connectionPoolKey(string $scheme, string $host, int $port, RequestOptions $options): string
    {
        if ($options->proxy === null) {
            return sprintf('%s://%s:%d', $scheme, strtolower($host), $port);
        }

        $proxy = $this->parseProxy($options->proxy);
        if ($scheme === 'http') {
            return sprintf('proxy-http://%s:%d->%s://%s:%d', $proxy['host'], $proxy['port'], $scheme, strtolower($host), $port);
        }

        return sprintf('proxy-connect://%s:%d->%s://%s:%d', $proxy['host'], $proxy['port'], $scheme, strtolower($host), $port);
    }

    /**
     * @param resource $socket
     */
    private function establishConnectTunnel($socket, string $host, int $port, RequestOptions $options, int $deadlineNs): void
    {
        $payload = sprintf(
            "CONNECT %s:%d HTTP/1.1\r\nHost: %s:%d\r\nProxy-Connection: keep-alive\r\n\r\n",
            $host,
            $port,
            $host,
            $port
        );

        $this->writePayload($socket, $payload, $options);

        $buffer = '';
        while (true) {
            $this->ensureTotalTimeout($deadlineNs);
            $remainingReadSeconds = max(0.0, ($deadlineNs - hrtime(true)) / 1_000_000_000);
            $this->waitForSocket($socket, true, false, $remainingReadSeconds, 'Proxy CONNECT timed out', $deadlineNs);
            $chunk = @fread($socket, 8192);

            if ($chunk === false) {
                throw new ConnectionException('Failed to read proxy CONNECT response');
            }

            if ($chunk === '') {
                if (feof($socket)) {
                    throw new ConnectionException('Proxy closed connection during CONNECT handshake');
                }

                $this->loop->next();
                continue;
            }

            $buffer .= $chunk;
            if (!str_contains($buffer, "\r\n\r\n")) {
                continue;
            }

            [$headerBlock] = explode("\r\n\r\n", $buffer, 2);
            $lines = preg_split('/\r\n|\n|\r/', $headerBlock) ?: [];
            $statusLine = array_shift($lines) ?? '';
            if (preg_match('/HTTP\/\d\.\d\s+(\d{3})/', $statusLine, $matches) !== 1) {
                throw new ConnectionException('Invalid proxy CONNECT response');
            }

            $status = (int)$matches[1];
            if ($status < 200 || $status >= 300) {
                throw new ConnectionException('Proxy CONNECT failed with status ' . $status);
            }

            return;
        }
    }

    /**
     * @param resource $socket
     */
    private function enableTlsOverSocket($socket, string $host, RequestOptions $options, int $deadlineNs): void
    {
        stream_context_set_option($socket, 'ssl', 'verify_peer', $options->verifySsl);
        stream_context_set_option($socket, 'ssl', 'verify_peer_name', $options->verifySsl);
        stream_context_set_option($socket, 'ssl', 'allow_self_signed', !$options->verifySsl);
        stream_context_set_option($socket, 'ssl', 'peer_name', $host);
        stream_context_set_option($socket, 'ssl', 'SNI_enabled', true);

        while (true) {
            $this->ensureTotalTimeout($deadlineNs);
            $result = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

            if ($result === true) {
                return;
            }

            if ($result === false) {
                throw new ConnectionException('Failed to establish TLS tunnel through proxy');
            }

            $this->loop->next();
        }
    }

    /**
     * @return array{host: string, port: int}
     */
    private function parseProxy(string $proxy): array
    {
        $candidate = str_contains($proxy, '://') ? $proxy : 'http://' . $proxy;
        $parts = parse_url($candidate);

        if ($parts === false || !isset($parts['host'])) {
            throw new ConnectionException('Invalid proxy URL: ' . $proxy);
        }

        $scheme = strtolower((string)($parts['scheme'] ?? 'http'));
        if (!in_array($scheme, ['http', 'tcp'], true)) {
            throw new ConnectionException('Unsupported proxy scheme: ' . $scheme);
        }

        return [
            'host' => (string)$parts['host'],
            'port' => (int)($parts['port'] ?? 8080),
        ];
    }

    /**
     * @param resource $socket
     * @param string|resource $payload
     */
    private function writePayload($socket, $payload, RequestOptions $options, ?int $totalDeadlineNs = null): void
    {
        $deadlineNs = $totalDeadlineNs ?? (hrtime(true) + (int)($options->totalTimeout * 1_000_000_000));
        $writeDeadline = hrtime(true) + (int)($options->writeTimeout * 1_000_000_000);
        $offset = 0;
        if (is_resource($payload)) {
            rewind($payload);
            $payloadLength = null;
        } elseif (is_string($payload)) {
            $payloadLength = strlen($payload);
        } else {
            throw new ConnectionException('Request payload must be a string or stream');
        }

        while ($payloadLength === null || $offset < $payloadLength) {
            if (is_resource($payload)) {
                $chunk = fread($payload, 8192);
                if ($chunk === false) {
                    throw new ConnectionException('Failed to read request payload stream');
                }

                if ($chunk === '') {
                    break;
                }
            } elseif (is_string($payload)) {
                $chunk = substr($payload, $offset, 8192);
            } else {
                throw new ConnectionException('Request payload must be a string or stream');
            }

            $chunkLength = strlen($chunk);
            $chunkOffset = 0;

            while ($chunkOffset < $chunkLength) {
                $this->ensureTotalTimeout($deadlineNs);
                if (hrtime(true) >= $writeDeadline) {
                    throw new TimeoutException('Write timed out');
                }

                $written = @fwrite($socket, substr($chunk, $chunkOffset, 8192));

                if ($written === false) {
                    throw new ConnectionException('Failed to write request payload');
                }

                if ($written === 0) {
                    $remainingWriteSeconds = max(0.0, ($writeDeadline - hrtime(true)) / 1_000_000_000);
                    $this->waitForSocket($socket, false, true, $remainingWriteSeconds, 'Write timed out', $deadlineNs);
                    continue;
                }

                $chunkOffset += $written;
                $writeDeadline = hrtime(true) + (int)($options->writeTimeout * 1_000_000_000);
            }

            $offset += $chunkLength;
        }
    }

    /**
     * @param resource $socket
     * @return array{0: int, 1: Headers, 2: string}
     */
    private function readResponse($socket, RequestOptions $options, ?int $totalDeadlineNs = null, string $method = 'GET'): array
    {
        $headerBuffer = '';
        $body = '';
        $headers = null;
        $status = null;
        $contentLength = null;
        $chunked = false;
        $decoder = null;
        $deadlineNs = $totalDeadlineNs ?? (hrtime(true) + (int)($options->totalTimeout * 1_000_000_000));
        $readDeadline = hrtime(true) + (int)($options->readTimeout * 1_000_000_000);

        while (true) {
            $this->ensureTotalTimeout($deadlineNs);
            if (hrtime(true) >= $readDeadline) {
                throw new TimeoutException('Read timed out');
            }

            $remainingReadSeconds = max(0.0, ($readDeadline - hrtime(true)) / 1_000_000_000);
            $this->waitForSocket($socket, true, false, $remainingReadSeconds, 'Read timed out', $deadlineNs);
            $chunk = @fread($socket, 65536);

            if ($chunk === false) {
                throw new ConnectionException('Failed to read response');
            }

            if ($chunk === '') {
                if (feof($socket)) {
                    break;
                }

                $this->loop->next();
                continue;
            }

            $readDeadline = hrtime(true) + (int)($options->readTimeout * 1_000_000_000);

            if ($headers === null) {
                $headerBuffer .= $chunk;
                $position = strpos($headerBuffer, "\r\n\r\n");
                if ($position === false) {
                    continue;
                }

                $rawHeaders = substr($headerBuffer, 0, $position);
                $body = substr($headerBuffer, $position + 4);
                $headerBuffer = $rawHeaders;
                $headers = Headers::fromRaw($rawHeaders);
                $status = $this->parseStatus($rawHeaders);
                $contentLengthHeader = $headers->get('Content-Length');
                $contentLength = $contentLengthHeader !== null ? max(0, (int)$contentLengthHeader) : null;
                $chunked = str_contains(strtolower((string)$headers->get('Transfer-Encoding', '')), 'chunked');

                if (!$this->responseExpectsBody($method, $status)) {
                    return [$status, $headers, ''];
                }

                if ($chunked) {
                    $decoder = new ChunkedDecoder();
                    $decoder->push($body);
                    $body = '';

                    if ($decoder->isCompleted()) {
                        break;
                    }

                    continue;
                }
            } elseif ($decoder !== null) {
                $decoder->push($chunk);

                if ($decoder->isCompleted()) {
                    break;
                }

                continue;
            } else {
                $body .= $chunk;
            }

            if ($decoder === null && $contentLength !== null && strlen($body) >= $contentLength) {
                if (strlen($body) !== $contentLength) {
                    $body = substr($body, 0, $contentLength);
                }
                break;
            }
        }

        if ($headers === null || $status === null) {
            throw new ConnectionException('Invalid HTTP response: headers not found');
        }

        if ($decoder !== null) {
            $decoder->finish();
            $body = $decoder->takeDecoded();
        } elseif ($contentLength !== null && strlen($body) < $contentLength) {
            throw new ConnectionException('Incomplete HTTP response body');
        }

        return [$status, $headers, $this->decodeContentBody($body, $headers)];
    }

    /**
     * @param resource $socket
     * @return array{0: int, 1: Headers, 2: string, 3: ?int, 4: bool}
     */
    private function readResponseHead($socket, RequestOptions $options, ?int $totalDeadlineNs = null, string $method = 'GET'): array
    {
        $headerBuffer = '';
        $bodyBuffer = '';
        $headers = null;
        $status = null;
        $contentLength = null;
        $chunked = false;
        $deadlineNs = $totalDeadlineNs ?? (hrtime(true) + (int)($options->totalTimeout * 1_000_000_000));
        $readDeadline = hrtime(true) + (int)($options->readTimeout * 1_000_000_000);

        while (true) {
            $this->ensureTotalTimeout($deadlineNs);
            if (hrtime(true) >= $readDeadline) {
                throw new TimeoutException('Read timed out');
            }

            $remainingReadSeconds = max(0.0, ($readDeadline - hrtime(true)) / 1_000_000_000);
            $this->waitForSocket($socket, true, false, $remainingReadSeconds, 'Read timed out', $deadlineNs);
            $chunk = @fread($socket, 65536);

            if ($chunk === false) {
                throw new ConnectionException('Failed to read response');
            }

            if ($chunk === '') {
                if (feof($socket)) {
                    break;
                }

                $this->loop->next();
                continue;
            }

            $readDeadline = hrtime(true) + (int)($options->readTimeout * 1_000_000_000);
            $headerBuffer .= $chunk;
            $position = strpos($headerBuffer, "\r\n\r\n");
            if ($position === false) {
                continue;
            }

            $rawHeaders = substr($headerBuffer, 0, $position);
            $bodyBuffer = substr($headerBuffer, $position + 4);
            $headerBuffer = $rawHeaders;
            $headers = Headers::fromRaw($rawHeaders);
            $status = $this->parseStatus($rawHeaders);
            $contentLengthHeader = $headers->get('Content-Length');
            $contentLength = $contentLengthHeader !== null ? max(0, (int)$contentLengthHeader) : null;
            $chunked = str_contains(strtolower((string)$headers->get('Transfer-Encoding', '')), 'chunked');

            if (!$this->responseExpectsBody($method, $status)) {
                return [$status, $headers, '', 0, false];
            }

            break;
        }

        if ($headers === null) {
            throw new ConnectionException('Invalid HTTP response: headers not found');
        }

        return [$status, $headers, $bodyBuffer, $contentLength, $chunked];
    }

    /**
     * @param resource $socket
     */
    private function waitForSocket($socket, bool $read, bool $write, float $timeoutSeconds, string $message, int $totalDeadlineNs): void
    {
        $deadlineNs = hrtime(true) + (int)($timeoutSeconds * 1_000_000_000);

        $this->ensureTotalTimeout($totalDeadlineNs);
        if (hrtime(true) >= $deadlineNs) {
            throw new TimeoutException($message);
        }

        $readStreams = $read ? [$socket] : [];
        $writeStreams = $write ? [$socket] : [];
        $except = [];
        $ready = @stream_select($readStreams, $writeStreams, $except, 0, 0);
        if ($ready === false) {
            throw new ConnectionException('stream_select failed while waiting for socket readiness');
        }
        if ($ready > 0) {
            return;
        }

        if ($this->loop instanceof SocketReadinessInterface) {
            $this->loop->awaitStream($socket, $read, $write, min($deadlineNs, $totalDeadlineNs));
        } else {
            $this->pollSocketFallback($socket, $read, $write, $deadlineNs, $totalDeadlineNs, $message);
        }
        $this->ensureTotalTimeout($totalDeadlineNs);
        if (hrtime(true) >= $deadlineNs) {
            throw new TimeoutException($message);
        }
    }

    /** @param resource $socket */
    private function pollSocketFallback($socket, bool $read, bool $write, int $deadlineNs, int $totalDeadlineNs, string $message): void
    {
        while (true) {
            $this->ensureTotalTimeout($totalDeadlineNs);
            if (hrtime(true) >= $deadlineNs) {
                throw new TimeoutException($message);
            }
            $readStreams = $read ? [$socket] : [];
            $writeStreams = $write ? [$socket] : [];
            $except = [];
            $ready = @stream_select($readStreams, $writeStreams, $except, 0, 0);
            if ($ready === false) {
                throw new ConnectionException('stream_select failed while waiting for socket readiness');
            }
            if ($ready > 0) {
                return;
            }
            $this->loop->next();
        }
    }

    private function ensureTotalTimeout(int $deadlineNs): void
    {
        if (hrtime(true) >= $deadlineNs) {
            throw new TimeoutException('Total request timeout exceeded');
        }
    }

    private function isRedirect(int $status): bool
    {
        return in_array($status, [301, 302, 303, 307, 308], true);
    }

    /**
     * Ao seguir um redirect para outra origem, credenciais não viajam:
     * headers Authorization/Cookie são removidos e bearer/basic das opções
     * são descartados (comportamento do curl/Guzzle).
     */
    private function buildRedirectedRequest(Request $request, RequestOptions $options, string $currentUrl, string $location, int $status): Request
    {
        $redirected = $request->withUrl($location);

        if (in_array($status, [301, 302, 303], true) && $request->method !== 'GET') {
            $redirected = new Request('GET', $location, Headers::from($request->headers), null, [], [], null, $options);
        }

        if ($this->sameOrigin($currentUrl, $location)) {
            return $redirected;
        }

        $headers = Headers::from($redirected->headers)
            ->remove('Authorization')
            ->remove('Cookie');

        $strippedOptions = clone $options;
        $strippedOptions->bearerToken = null;
        $strippedOptions->basicAuth = null;

        return new Request(
            $redirected->method,
            $redirected->url,
            $headers,
            $redirected->body,
            $redirected->query,
            $redirected->files,
            $redirected->multipart,
            $strippedOptions
        );
    }

    private function sameOrigin(string $fromUrl, string $toUrl): bool
    {
        $from = parse_url($fromUrl);
        $to = parse_url($toUrl);

        if ($from === false || $to === false || !isset($from['host'], $to['host'])) {
            return false;
        }

        $fromScheme = strtolower((string)($from['scheme'] ?? 'http'));
        $toScheme = strtolower((string)($to['scheme'] ?? 'http'));
        $fromPort = (int)($from['port'] ?? ($fromScheme === 'https' ? 443 : 80));
        $toPort = (int)($to['port'] ?? ($toScheme === 'https' ? 443 : 80));

        return $fromScheme === $toScheme
            && strtolower((string)$from['host']) === strtolower((string)$to['host'])
            && $fromPort === $toPort;
    }

    private function parseStatus(string $headerBlock): int
    {
        $statusLine = strtok($headerBlock, "\r\n") ?: '';
        if (strlen($statusLine) < 12
            || substr($statusLine, 0, 5) !== 'HTTP/'
            || $statusLine[6] !== '.'
            || $statusLine[8] !== ' '
            || !ctype_digit(substr($statusLine, 9, 3))
        ) {
            throw new ConnectionException('Invalid HTTP response status line');
        }

        $status = (int) substr($statusLine, 9, 3);
        if ($status < 100 || $status > 599) {
            throw new ConnectionException('Invalid HTTP response status line');
        }

        return $status;
    }

    /**
     * HEAD e respostas 204/205/304 nunca carregam corpo, ainda que o servidor
     * envie Content-Length ou Transfer-Encoding descritivos (RFC 9110).
     */
    private function responseExpectsBody(string $method, int $status): bool
    {
        return strtoupper($method) !== 'HEAD' && !in_array($status, [204, 205, 304], true);
    }

    /**
     * Decodifica Content-Encoding do corpo materializado. A decodificação é
     * sempre aplicada quando o servidor declara o encoding; withCompression()
     * controla apenas a negociação via Accept-Encoding.
     */
    private function decodeContentBody(string $body, Headers $headers): string
    {
        $encoding = strtolower(trim((string)$headers->get('Content-Encoding', '')));

        if ($body === '' || $encoding === '' || $encoding === 'identity') {
            return $body;
        }

        if (!in_array($encoding, ['gzip', 'x-gzip'], true)) {
            throw new ConnectionException('Unsupported Content-Encoding: ' . $encoding);
        }

        if (!function_exists('gzdecode')) {
            throw new ConnectionException('Cannot decode gzip response body: ext/zlib is unavailable');
        }

        $decoded = @gzdecode($body);
        if ($decoded === false) {
            throw new ConnectionException('Failed to decode gzip response body');
        }

        return $decoded;
    }

    private function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $base = parse_url($baseUrl);
        if ($base === false || !isset($base['scheme'], $base['host'])) {
            return $location;
        }

        $prefix = $base['scheme'] . '://' . $base['host'];
        if (isset($base['port'])) {
            $prefix .= ':' . $base['port'];
        }

        if (str_starts_with($location, '/')) {
            return $prefix . $location;
        }

        $path = $base['path'] ?? '/';
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');
        return $prefix . ($directory === '' ? '' : $directory) . '/' . $location;
    }

}
