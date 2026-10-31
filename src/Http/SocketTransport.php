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
    private const MAX_RESPONSE_HEADER_BYTES = 65_536;

    private const MAX_RESPONSE_HEADER_LINES = 100;
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
        $totalDeadlineNs = $options->totalDeadlineNs ?? (hrtime(true) + (int)($options->totalTimeout * 1_000_000_000));
        if ($options->totalDeadlineNs === null) {
            $options = $options->withTotalDeadlineNs($totalDeadlineNs);
        }
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
        $totalDeadlineNs = $options->totalDeadlineNs ?? (hrtime(true) + (int)($options->totalTimeout * 1_000_000_000));
        if ($options->totalDeadlineNs === null) {
            $options = $options->withTotalDeadlineNs($totalDeadlineNs);
        }
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
        if ($parsed === false || !isset($parsed['host'])) {
            throw new ConnectionException('Invalid request URL', $request);
        }
        $scheme = strtolower((string)($parsed['scheme'] ?? 'http'));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new ConnectionException('Unsupported URL scheme: ' . $scheme, $request);
        }
        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '/';
        $isSecure = $scheme === 'https';
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
            $contentType = strtolower(trim((string)($headers->get('Content-Type') ?? '')));
            $mediaTypeEnd = strpos($contentType, ';');
            if ($mediaTypeEnd !== false) {
                $contentType = trim(substr($contentType, 0, $mediaTypeEnd));
            }
            $isFormEncoded = $contentType === 'application/x-www-form-urlencoded';
            if ($isFormEncoded) {
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
        } elseif (in_array(strtoupper($request->method), ['POST', 'PUT', 'PATCH'], true)
            && !$headers->has('Content-Length')
            && !$headers->has('Transfer-Encoding')) {
            $headers->set('Content-Length', '0');
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

        $poolTransport = $connectScheme === 'https' ? 'tls' : 'tcp';
        $pooled = $this->connectionPool->acquire($poolTransport, $connectHost, $connectPort, $poolKey);
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
        $flags = STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT;
        $timeout = 0;
        $deadlineNs = $totalDeadlineNs ?? (hrtime(true) + (int)($options->totalTimeout * 1_000_000_000));
        $connectDeadlineNs = hrtime(true) + (int)($options->connectTimeout * 1_000_000_000);

        $socket = @stream_socket_client(
            "tcp://{$connectHost}:{$connectPort}",
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

        $remainingConnectSeconds = max(0.0, ($connectDeadlineNs - hrtime(true)) / 1_000_000_000);
        $this->waitForSocket($socket, false, true, $remainingConnectSeconds, 'Connection timed out', $deadlineNs);
        $this->assertSocketConnected($socket);

        if ($proxy === null && $scheme === 'https') {
            $this->enableTlsOverSocket($socket, $host, $options, min($connectDeadlineNs, $deadlineNs), $deadlineNs);
        }

        if ($proxy !== null && $scheme === 'https') {
            $connectionDeadlineNs = min($connectDeadlineNs, $deadlineNs);
            $this->establishConnectTunnel($socket, $host, $port, $options, $connectionDeadlineNs);
            $this->enableTlsOverSocket($socket, $host, $options, $connectionDeadlineNs, $deadlineNs);
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
        $tlsPolicy = $scheme === 'https' ? ($options->verifySsl ? 'tls-verify' : 'tls-insecure') : 'plain';
        if ($options->proxy === null) {
            return sprintf('%s://%s:%d|%s', $scheme, strtolower($host), $port, $tlsPolicy);
        }

        $proxy = $this->parseProxy($options->proxy);
        if ($scheme === 'http') {
            return sprintf('proxy-http://%s:%d->%s://%s:%d|%s', $proxy['host'], $proxy['port'], $scheme, strtolower($host), $port, $tlsPolicy);
        }

        return sprintf('proxy-connect://%s:%d->%s://%s:%d|%s', $proxy['host'], $proxy['port'], $scheme, strtolower($host), $port, $tlsPolicy);
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
    private function enableTlsOverSocket($socket, string $host, RequestOptions $options, int $connectDeadlineNs, int $totalDeadlineNs): void
    {
        stream_context_set_option($socket, 'ssl', 'verify_peer', $options->verifySsl);
        stream_context_set_option($socket, 'ssl', 'verify_peer_name', $options->verifySsl);
        stream_context_set_option($socket, 'ssl', 'allow_self_signed', !$options->verifySsl);
        stream_context_set_option($socket, 'ssl', 'peer_name', $host);
        stream_context_set_option($socket, 'ssl', 'SNI_enabled', true);

        while (true) {
            $this->ensureTotalTimeout($totalDeadlineNs);
            if (hrtime(true) >= $connectDeadlineNs) {
                throw new TimeoutException('TLS handshake timed out');
            }

            $result = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

            if ($result === true) {
                return;
            }

            if ($result === false) {
                throw new ConnectionException('Failed to establish TLS connection');
            }

            $remainingSeconds = max(0.0, ($connectDeadlineNs - hrtime(true)) / 1_000_000_000);
            $this->waitForSocket($socket, true, false, $remainingSeconds, 'TLS handshake timed out', $totalDeadlineNs);
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
        [$status, $headers, $body, $contentLength, $chunked] = $this->readResponseHead($socket, $options, $totalDeadlineNs, $method);
        if (!$this->responseExpectsBody($method, $status)) {
            return [$status, $headers, ''];
        }

        $decoder = $chunked ? new ChunkedDecoder() : null;
        if ($decoder !== null && $body !== '') {
            $decoder->push($body);
            $body = '';
        }

        $deadlineNs = $totalDeadlineNs ?? $options->totalDeadlineNs ?? (hrtime(true) + (int)($options->totalTimeout * 1_000_000_000));
        $readDeadline = hrtime(true) + (int)($options->readTimeout * 1_000_000_000);

        while (true) {
            if ($decoder !== null && $decoder->isCompleted()) {
                break;
            }
            if ($decoder === null && $contentLength !== null && strlen($body) >= $contentLength) {
                if (strlen($body) > $contentLength) {
                    throw new ConnectionException('Response body exceeds Content-Length');
                }
                break;
            }

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
            if ($decoder !== null) {
                $decoder->push($chunk);
            } else {
                $body .= $chunk;
            }
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
        $deadlineNs = $totalDeadlineNs ?? $options->totalDeadlineNs ?? (hrtime(true) + (int)($options->totalTimeout * 1_000_000_000));
        $readDeadline = hrtime(true) + (int)($options->readTimeout * 1_000_000_000);
        $interimResponses = 0;

        while (true) {
            while (($position = strpos($headerBuffer, "\r\n\r\n")) !== false) {
                if ($position > self::MAX_RESPONSE_HEADER_BYTES) {
                    throw new ConnectionException('HTTP response headers exceed the configured size limit');
                }
                $rawHeaders = substr($headerBuffer, 0, $position);
                if (substr_count($rawHeaders, "\r\n") + 1 > self::MAX_RESPONSE_HEADER_LINES) {
                    throw new ConnectionException('HTTP response headers exceed the configured line limit');
                }
                $headerBuffer = substr($headerBuffer, $position + 4);
                try {
                    $headers = Headers::fromRaw($rawHeaders);
                } catch (\InvalidArgumentException $exception) {
                    throw new ConnectionException('Invalid HTTP response headers', null, null, 0, $exception);
                }
                $status = $this->parseStatus($rawHeaders);

                if ($status === 101) {
                    throw new ConnectionException('HTTP protocol upgrades are not supported');
                }
                if ($status >= 100 && $status < 200) {
                    if (++$interimResponses > 16) {
                        throw new ConnectionException('Too many informational HTTP responses');
                    }
                    continue;
                }

                if (!$this->responseExpectsBody($method, $status)) {
                    return [$status, $headers, '', 0, false];
                }

                [$contentLength, $chunked] = $this->parseResponseFraming($headers);
                return [$status, $headers, $headerBuffer, $contentLength, $chunked];
            }

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
            if (strlen($headerBuffer) > self::MAX_RESPONSE_HEADER_BYTES
                && strpos($headerBuffer, "\r\n\r\n") === false) {
                throw new ConnectionException('HTTP response headers exceed the configured size limit');
            }
        }

        throw new ConnectionException('Invalid HTTP response: headers not found');
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
        $currentScheme = strtolower((string) (parse_url($currentUrl, PHP_URL_SCHEME) ?: 'http'));
        $targetScheme = strtolower((string) (parse_url($location, PHP_URL_SCHEME) ?: $currentScheme));
        if ($currentScheme === 'https' && $targetScheme === 'http' && !$options->allowInsecureRedirects) {
            throw new ConnectionException('Refusing HTTPS to HTTP redirect', $request);
        }

        $redirected = $request->withUrl($location);

        if (in_array($status, [301, 302, 303], true) && !in_array(strtoupper($request->method), ['GET', 'HEAD'], true)) {
            $redirected = new Request('GET', $location, Headers::from($request->headers), null, [], [], null, $options);
        } else {
            $redirected = new Request(
                $redirected->method,
                $redirected->url,
                $redirected->headers,
                $redirected->body,
                [],
                $redirected->files,
                $redirected->multipart,
                $redirected->options
            );
        }

        if ($this->sameOrigin($currentUrl, $location)) {
            return $redirected;
        }

        $headers = Headers::from($redirected->headers);
        $sensitiveHeaders = array_fill_keys(
            array_merge(['authorization', 'proxy-authorization', 'cookie'], $options->sensitiveHeaders),
            true
        );
        foreach ($headers->toArray() as $name => $_value) {
            if (isset($sensitiveHeaders[strtolower($name)])) {
                $headers->remove($name);
            }
        }

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

    /** @return array{0: ?int, 1: bool} */
    private function parseResponseFraming(Headers $headers): array
    {
        $lengthValues = $headers->values('Content-Length');
        $transferValues = $headers->values('Transfer-Encoding');

        if ($lengthValues !== [] && $transferValues !== []) {
            throw new ConnectionException('Ambiguous response framing: both Content-Length and Transfer-Encoding are present');
        }

        $contentLength = null;
        foreach ($lengthValues as $value) {
            foreach (explode(',', $value) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '') {
                    throw new ConnectionException('Invalid Content-Length response header');
                }

                $digits = strlen($candidate);
                for ($i = 0; $i < $digits; $i++) {
                    $byte = ord($candidate[$i]);
                    if ($byte < 48 || $byte > 57) {
                        throw new ConnectionException('Invalid Content-Length response header');
                    }
                }

                $max = (string) PHP_INT_MAX;
                $normalized = ltrim($candidate, '0');
                $normalized = $normalized === '' ? '0' : $normalized;
                if (strlen($normalized) > strlen($max)
                    || (strlen($normalized) === strlen($max) && strcmp($normalized, $max) > 0)
                ) {
                    throw new ConnectionException('Content-Length exceeds the supported range');
                }

                $parsed = (int) $normalized;
                if ($contentLength !== null && $contentLength !== $parsed) {
                    throw new ConnectionException('Conflicting Content-Length response headers');
                }
                $contentLength = $parsed;
            }
        }

        if ($transferValues === []) {
            return [$contentLength, false];
        }

        $codings = [];
        foreach ($transferValues as $value) {
            foreach (explode(',', strtolower($value)) as $coding) {
                $coding = trim($coding);
                if ($coding === '') {
                    throw new ConnectionException('Invalid Transfer-Encoding response header');
                }
                $codings[] = $coding;
            }
        }

        if ($codings !== ['chunked']) {
            throw new ConnectionException('Unsupported or invalid Transfer-Encoding response header');
        }

        return [null, true];
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
        $reference = parse_url($location);
        if ($reference === false) {
            return $location;
        }
        $base = parse_url($baseUrl);
        if ($base === false || !isset($base['scheme'], $base['host'])) {
            return $location;
        }

        if (isset($reference['scheme'])) {
            return $location;
        }

        $target = [
            'scheme' => $base['scheme'],
            'host' => $base['host'],
        ];
        if (isset($base['port'])) {
            $target['port'] = $base['port'];
        }

        if (isset($reference['host'])) {
            $target['host'] = $reference['host'];
            if (isset($reference['port'])) {
                $target['port'] = $reference['port'];
            } else {
                unset($target['port']);
            }
            $target['path'] = $this->removeDotSegments($reference['path'] ?? '');
            if (array_key_exists('query', $reference)) {
                $target['query'] = $reference['query'];
            }
        } else {
            $referencePath = $reference['path'] ?? '';
            if ($referencePath === '') {
                $target['path'] = $base['path'] ?? '/';
                if (array_key_exists('query', $reference)) {
                    $target['query'] = $reference['query'];
                } elseif (array_key_exists('query', $base)) {
                    $target['query'] = $base['query'];
                }
            } else {
                if (str_starts_with($referencePath, '/')) {
                    $mergedPath = $referencePath;
                } else {
                    $basePath = $base['path'] ?? '/';
                    $lastSlash = strrpos($basePath, '/');
                    $mergedPath = ($lastSlash === false ? '' : substr($basePath, 0, $lastSlash + 1)) . $referencePath;
                }
                $target['path'] = $this->removeDotSegments($mergedPath);
                if (array_key_exists('query', $reference)) {
                    $target['query'] = $reference['query'];
                }
            }
        }

        if (array_key_exists('fragment', $reference)) {
            $target['fragment'] = $reference['fragment'];
        }

        $url = $target['scheme'] . '://' . $target['host'];
        if (isset($target['port'])) {
            $url .= ':' . $target['port'];
        }
        $url .= $target['path'];
        if (array_key_exists('query', $target)) {
            $url .= '?' . $target['query'];
        }
        if (array_key_exists('fragment', $target)) {
            $url .= '#' . $target['fragment'];
        }
        return $url;
    }

    private function removeDotSegments(string $path): string
    {
        $segments = explode('/', $path);
        $normalized = [];
        foreach ($segments as $segment) {
            if ($segment === '.' || strtolower($segment) === '%2e') {
                continue;
            }
            if (in_array(strtolower($segment), ['..', '.%2e', '%2e.', '%2e%2e'], true)) {
                if ($normalized !== [] && end($normalized) !== '') {
                    array_pop($normalized);
                }
                continue;
            }
            $normalized[] = $segment;
        }

        $result = implode('/', $normalized);
        if ($result === '' && str_starts_with($path, '/')) {
            return '/';
        }
        return $result;
    }

}
