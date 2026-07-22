<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Http;

use Omegaalfa\FiberEventLoop\FiberEventLoop;
use Omegaalfa\FiberEventLoop\Future;
use Omegaalfa\HttpClient\Http\Exceptions\ConnectionException;
use Omegaalfa\HttpClient\Http\Exceptions\HttpException;
use Omegaalfa\HttpClient\Http\Exceptions\TimeoutException;

final class AsyncHttpClient
{
    private FiberEventLoop $loop;

    private RequestOptions $options;

    private ConnectionPool $connectionPool;

    private ConcurrentExecutor $concurrentExecutor;

    public function __construct(
        ?FiberEventLoop $loop = null,
        ?RequestOptions $options = null,
        ?ConnectionPool $connectionPool = null
    ) {
        $this->loop = $loop ?? new FiberEventLoop();
        $this->options = $options ?? new RequestOptions();
        $this->connectionPool = $connectionPool ?? new ConnectionPool();
        $this->concurrentExecutor = new ConcurrentExecutor($this->loop);
    }

    public function get(string $url, array $query = [], array $headers = []): Future
    {
        return $this->request('GET', $url, query: $query, headers: $headers);
    }

    public function post(string $url, mixed $body = null, array $headers = [], array $files = [], ?MultipartBuilder $multipart = null): Future
    {
        return $this->request('POST', $url, headers: $headers, body: $body, files: $files, multipart: $multipart);
    }

    public function put(string $url, mixed $body = null, array $headers = [], array $files = [], ?MultipartBuilder $multipart = null): Future
    {
        return $this->request('PUT', $url, headers: $headers, body: $body, files: $files, multipart: $multipart);
    }

    public function patch(string $url, mixed $body = null, array $headers = [], array $files = [], ?MultipartBuilder $multipart = null): Future
    {
        return $this->request('PATCH', $url, headers: $headers, body: $body, files: $files, multipart: $multipart);
    }

    public function delete(string $url, array $query = [], array $headers = []): Future
    {
        return $this->request('DELETE', $url, query: $query, headers: $headers);
    }

    public function request(
        string $method,
        string $url,
        array $query = [],
        array $headers = [],
        mixed $body = null,
        array $files = [],
        ?MultipartBuilder $multipart = null
    ): Future {
        $request = new Request(
            strtoupper($method),
            $this->resolveUrl($url),
            Headers::from($this->options->headers)->merge($headers),
            $body,
            $query,
            $files,
            $multipart,
            $this->options
        );

        return $this->loop->async(fn() => $this->sendWithRetries($request));
    }

    public function concurrent(array $tasks): Future
    {
        return $this->concurrentExecutor->named($tasks);
    }

    public function parallel(array $futures): Future
    {
        return $this->concurrentExecutor->all($futures);
    }

    public function awaitAny(array $futures): Future
    {
        return $this->concurrentExecutor->any($futures);
    }

    public function race(array $futures): Future
    {
        return $this->concurrentExecutor->race($futures);
    }

    public function settled(array $futures): Future
    {
        return $this->concurrentExecutor->settled($futures);
    }

    public function withHeader(string $name, string $value): self
    {
        return $this->withOptions($this->options->withHeader($name, $value));
    }

    public function withHeaders(array $headers): self
    {
        return $this->withOptions($this->options->withHeaders($headers));
    }

    public function withCookie(string $name, string $value, string $domain = '', string $path = '/'): self
    {
        return $this->withOptions($this->options->withCookie($name, $value, $domain, $path));
    }

    public function withCookies(array $cookies, string $domain = '', string $path = '/'): self
    {
        return $this->withOptions($this->options->withCookies($cookies, $domain, $path));
    }

    public function withProxy(string $proxy): self
    {
        return $this->withOptions($this->options->withProxy($proxy));
    }

    public function withUserAgent(string $userAgent): self
    {
        return $this->withOptions($this->options->withUserAgent($userAgent));
    }

    public function withJson(bool $enabled = true): self
    {
        return $this->withOptions($this->options->withJson($enabled));
    }

    public function withBearerToken(string $token): self
    {
        return $this->withOptions($this->options->withBearerToken($token));
    }

    public function withBasicAuth(string $username, string $password): self
    {
        return $this->withOptions($this->options->withBasicAuth($username, $password));
    }

    public function withRetries(int $retries): self
    {
        return $this->withOptions($this->options->withRetries($retries));
    }

    public function withRetryDelay(int $milliseconds): self
    {
        return $this->withOptions($this->options->withRetryDelay($milliseconds));
    }

    public function withExponentialBackoff(bool $enabled = true): self
    {
        return $this->withOptions($this->options->withExponentialBackoff($enabled));
    }

    public function withFollowRedirects(int|bool $value): self
    {
        return $this->withOptions($this->options->withFollowRedirects($value));
    }

    public function withVerifySSL(bool $verify): self
    {
        return $this->withOptions($this->options->withVerifySSL($verify));
    }

    public function withKeepAlive(bool $enabled = true): self
    {
        return $this->withOptions($this->options->withKeepAlive($enabled));
    }

    public function withBaseUrl(string $baseUrl): self
    {
        return $this->withOptions($this->options->withBaseUrl($baseUrl));
    }

    public function withTimeout(float $seconds): self
    {
        return $this->withOptions($this->options->withTimeout($seconds));
    }

    public function connectTimeout(float $seconds): self
    {
        return $this->withOptions($this->options->withConnectTimeout($seconds));
    }

    public function readTimeout(float $seconds): self
    {
        return $this->withOptions($this->options->withReadTimeout($seconds));
    }

    public function writeTimeout(float $seconds): self
    {
        return $this->withOptions($this->options->withWriteTimeout($seconds));
    }

    public function totalTimeout(float $seconds): self
    {
        return $this->withOptions($this->options->withTotalTimeout($seconds));
    }

    private function withOptions(RequestOptions $options): self
    {
        return new self($this->loop, $options, $this->connectionPool);
    }

    private function resolveUrl(string $url): string
    {
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        if ($this->options->baseUrl === null) {
            return $url;
        }

        return rtrim($this->options->baseUrl, '/') . '/' . ltrim($url, '/');
    }

    private function sendWithRetries(Request $request): Response
    {
        $attempt = 0;
        $maxAttempts = $request->options?->retries ?? 0;

        do {
            $attempt++;

            try {
                $response = $this->send($request, $request->options?->followRedirects ?? 0);

                if ($response->status() >= 500 && $attempt <= $maxAttempts) {
                    $exception = new HttpException(
                        'HTTP request failed with status ' . $response->status(),
                        $request,
                        $response
                    );

                    if ($this->shouldRetry($request, $exception)) {
                        $delayMs = $request->options?->retryDelayMs ?? 100;
                        if ($request->options?->exponentialBackoff) {
                            $delayMs *= 2 ** ($attempt - 1);
                        }

                        $this->loop->sleep($delayMs / 1000);
                        continue;
                    }
                }

                return $response;
            } catch (HttpException $exception) {
                if ($attempt > $maxAttempts || !$this->shouldRetry($request, $exception)) {
                    throw $exception;
                }

                $delayMs = $request->options?->retryDelayMs ?? 100;
                if ($request->options?->exponentialBackoff) {
                    $delayMs *= 2 ** ($attempt - 1);
                }

                $this->loop->sleep($delayMs / 1000);
            }
        } while (true);
    }

    private function shouldRetry(Request $request, HttpException $exception): bool
    {
        if ($exception instanceof TimeoutException || $exception instanceof ConnectionException) {
            return true;
        }

        $response = $exception->response();
        if ($response !== null && $response->status() >= 500) {
            return true;
        }

        return in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    private function send(Request $request, int $redirectsRemaining): Response
    {
        $options = $request->options ?? $this->options;
        $prepared = $this->prepareRequest($request, $options);
        $url = parse_url($prepared['url']);

        if ($url === false || !isset($url['host'])) {
            throw new ConnectionException('Invalid request URL', $request);
        }

        $scheme = strtolower($url['scheme'] ?? 'http');
        $host = $url['host'];
        $port = (int) ($url['port'] ?? ($scheme === 'https' ? 443 : 80));
        $path = ($url['path'] ?? '/') . (isset($url['query']) ? '?' . $url['query'] : '');
        $requestTarget = ($options->proxy !== null && $scheme === 'http')
            ? $prepared['url']
            : $path;
        $poolKey = $this->connectionPoolKey($scheme, $host, $port, $options);
        $socket = $this->openSocket($scheme, $host, $port, $options, $poolKey);
        $keepAlive = false;
        $poolable = !($options->proxy !== null && $scheme === 'https');

        try {
            $payload = $this->buildRequestPayload($prepared['method'], $requestTarget, $prepared['headers'], $prepared['body']);
            $this->writePayload($socket, $payload, $options);
            [$status, $headers, $body] = $this->readResponse($socket, $options);

            $connectionHeader = strtolower((string) ($headers->get('Connection') ?? ''));
            $keepAlive = $options->keepAlive && $connectionHeader !== 'close';

            $options->cookieJar->storeFromHeaders($headers, $host);
            $cookies = $this->extractCookies($headers);

            if ($redirectsRemaining > 0 && $this->isRedirect($status) && $headers->has('Location')) {
                $location = $this->resolveRedirectUrl($prepared['url'], (string) $headers->get('Location'));
                $redirectedRequest = $request->withUrl($location);

                if (in_array($status, [301, 302, 303], true) && $request->method !== 'GET') {
                    $redirectedRequest = new Request('GET', $location, $request->headers, null, [], [], null, $options);
                }

                $response = $this->send($redirectedRequest, $redirectsRemaining - 1);
                return new Response($response->status(), $response->headers(), $response->body(), $response->cookies(), true);
            }

            return new Response($status, $headers, $body, $cookies, false);
        } finally {
            $this->connectionPool->release($socket, $keepAlive && $poolable, $poolKey);
        }
    }

    /**
     * @return array{url: string, method: string, headers: Headers, body: string}
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

        if ($options->userAgent !== '') {
            $headers->set('User-Agent', $options->userAgent);
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

        $headers->set('Host', $host);
        $headers->set('Connection', $options->keepAlive ? 'keep-alive' : 'close');

        $body = '';
        if ($request->multipart !== null || $request->files !== []) {
            $multipart = $request->multipart ?? MultipartBuilder::make();
            foreach ($request->files as $name => $filePath) {
                $multipart = $multipart->file((string) $name, (string) $filePath);
            }

            if (is_array($request->body)) {
                foreach ($request->body as $name => $value) {
                    $multipart = $multipart->field((string) $name, is_scalar($value) || $value === null ? $value : json_encode($value));
                }
            }

            $built = $multipart->build();
            $body = $built['body'];
            $headers->set('Content-Type', $built['content_type']);
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

        if ($body !== '') {
            $headers->set('Content-Length', (string) strlen($body));
        }

        return [
            'url' => $url,
            'method' => $request->method,
            'headers' => $headers,
            'body' => $body,
        ];
    }

    private function buildRequestPayload(string $method, string $path, Headers $headers, string $body): string
    {
        $lines = [sprintf('%s %s HTTP/1.1', strtoupper($method), $path === '' ? '/' : $path)];
        foreach ($headers->toLines() as $line) {
            $lines[] = $line;
        }

        return implode("\r\n", $lines) . "\r\n\r\n" . $body;
    }

    /**
     * @return resource
     */
    private function openSocket(string $scheme, string $host, int $port, RequestOptions $options, string $poolKey)
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

        $deadlineNs = hrtime(true) + (int) ($options->totalTimeout * 1_000_000_000);
        if ($connectScheme !== 'https') {
            $this->waitForSocket($socket, false, true, $options->connectTimeout, 'Connection timed out', $deadlineNs);
        }

        if ($proxy !== null && $scheme === 'https') {
            $this->establishConnectTunnel($socket, $host, $port, $options, $deadlineNs);
            $this->enableTlsOverSocket($socket, $host, $options, $deadlineNs);
        }

        return $socket;
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

            $status = (int) $matches[1];
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

        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        if (!in_array($scheme, ['http', 'tcp'], true)) {
            throw new ConnectionException('Unsupported proxy scheme: ' . $scheme);
        }

        return [
            'host' => (string) $parts['host'],
            'port' => (int) ($parts['port'] ?? 8080),
        ];
    }

    /**
     * @param resource $socket
     */
    private function writePayload($socket, string $payload, RequestOptions $options): void
    {
        $offset = 0;
        $deadlineNs = hrtime(true) + (int) ($options->totalTimeout * 1_000_000_000);
        $writeDeadline = hrtime(true) + (int) ($options->writeTimeout * 1_000_000_000);
        $payloadLength = strlen($payload);

        while ($offset < $payloadLength) {
            $this->ensureTotalTimeout($deadlineNs);
            if (hrtime(true) >= $writeDeadline) {
                throw new TimeoutException('Write timed out');
            }

            $remainingWriteSeconds = max(0.0, ($writeDeadline - hrtime(true)) / 1_000_000_000);
            $this->waitForSocket($socket, false, true, $remainingWriteSeconds, 'Write timed out', $deadlineNs);
            $chunk = substr($payload, $offset, 8192);
            $written = @fwrite($socket, $chunk);

            if ($written === false) {
                throw new ConnectionException('Failed to write request payload');
            }

            if ($written === 0) {
                $this->loop->next();
                continue;
            }

            $offset += $written;
            $writeDeadline = hrtime(true) + (int) ($options->writeTimeout * 1_000_000_000);
        }
    }

    /**
     * @param resource $socket
     * @return array{0: int, 1: Headers, 2: string}
     */
    private function readResponse($socket, RequestOptions $options): array
    {
        $buffer = '';
        $headerEndPos = null;
        $contentLength = null;
        $chunked = false;
        $deadlineNs = hrtime(true) + (int) ($options->totalTimeout * 1_000_000_000);
        $readDeadline = hrtime(true) + (int) ($options->readTimeout * 1_000_000_000);

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

            $buffer .= $chunk;
            $readDeadline = hrtime(true) + (int) ($options->readTimeout * 1_000_000_000);

            if ($headerEndPos === null) {
                $position = strpos($buffer, "\r\n\r\n");
                if ($position !== false) {
                    $headerEndPos = $position + 4;
                    $headers = Headers::fromRaw(substr($buffer, 0, $position));
                    $contentLengthHeader = $headers->get('Content-Length');
                    $contentLength = $contentLengthHeader !== null ? (int) $contentLengthHeader : null;
                    $chunked = str_contains(strtolower($headers->get('Transfer-Encoding', '') ?? ''), 'chunked');
                }
            }

            if ($headerEndPos !== null) {
                $body = substr($buffer, $headerEndPos);
                if ($contentLength !== null && strlen($body) >= $contentLength) {
                    $buffer = substr($buffer, 0, $headerEndPos + $contentLength);
                    break;
                }

                if ($chunked && str_contains($body, "\r\n0\r\n\r\n")) {
                    break;
                }
            }
        }

        $position = strpos($buffer, "\r\n\r\n");
        if ($position === false) {
            throw new ConnectionException('Invalid HTTP response: headers not found');
        }

        $statusLine = strtok(substr($buffer, 0, $position), "\r\n") ?: '';
        if (preg_match('/HTTP\/\d\.\d\s+(\d{3})/', $statusLine, $matches) !== 1) {
            throw new ConnectionException('Invalid HTTP response status line');
        }

        $status = (int) $matches[1];
        $headers = Headers::fromRaw(substr($buffer, 0, $position));
        $body = substr($buffer, $position + 4);

        if (str_contains(strtolower($headers->get('Transfer-Encoding', '') ?? ''), 'chunked')) {
            $body = $this->decodeChunkedBody($body);
        }

        return [$status, $headers, $body];
    }

    /**
     * @param resource $socket
     */
    private function waitForSocket($socket, bool $read, bool $write, float $timeoutSeconds, string $message, int $totalDeadlineNs): void
    {
        $deadlineNs = hrtime(true) + (int) ($timeoutSeconds * 1_000_000_000);

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

    /**
     * @return array<string, string>
     */
    private function extractCookies(Headers $headers): array
    {
        $cookies = [];
        foreach ($headers->values('Set-Cookie') as $line) {
            $pair = trim((string) strtok($line, ';'));
            if (!str_contains($pair, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $pair, 2);
            $cookies[trim($name)] = trim($value);
        }

        return $cookies;
    }

    private function decodeChunkedBody(string $body): string
    {
        $decoded = '';
        $offset = 0;

        while (true) {
            $lineEnd = strpos($body, "\r\n", $offset);
            if ($lineEnd === false) {
                break;
            }

            $lengthHex = trim(substr($body, $offset, $lineEnd - $offset));
            if ($lengthHex === '') {
                break;
            }

            $length = hexdec($lengthHex);
            $offset = $lineEnd + 2;
            if ($length === 0) {
                break;
            }

            $decoded .= substr($body, $offset, $length);
            $offset += $length + 2;
        }

        return $decoded;
    }
}
