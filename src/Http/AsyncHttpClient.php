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

    private TransportInterface $transport;

    public function __construct(
        ?FiberEventLoop $loop = null,
        ?RequestOptions $options = null,
        ?ConnectionPool $connectionPool = null,
        ?TransportInterface $transport = null
    ) {
        $this->loop = $loop ?? new HttpClientEventLoop();
        $this->options = $options ?? new RequestOptions();
        $this->connectionPool = $connectionPool ?? new ConnectionPool();
        $this->transport = $transport ?? new SocketTransport($this->loop, $this->connectionPool);
        $this->concurrentExecutor = new ConcurrentExecutor($this->loop);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string|list<string>> $headers
     */
    public function get(string $url, array $query = [], array $headers = []): Future
    {
        return $this->request('GET', $url, query: $query, headers: $headers);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string|list<string>> $headers
     */
    public function head(string $url, array $query = [], array $headers = []): Future
    {
        return $this->request('HEAD', $url, query: $query, headers: $headers);
    }

    /**
     * @param array<string, string|list<string>> $headers
     * @param array<string, string> $files
     */
    public function post(string $url, mixed $body = null, array $headers = [], array $files = [], ?MultipartBuilder $multipart = null): Future
    {
        return $this->request('POST', $url, headers: $headers, body: $body, files: $files, multipart: $multipart);
    }

    /**
     * @param array<string, string|list<string>> $headers
     * @param array<string, string> $files
     */
    public function put(string $url, mixed $body = null, array $headers = [], array $files = [], ?MultipartBuilder $multipart = null): Future
    {
        return $this->request('PUT', $url, headers: $headers, body: $body, files: $files, multipart: $multipart);
    }

    /**
     * @param array<string, string|list<string>> $headers
     * @param array<string, string> $files
     */
    public function patch(string $url, mixed $body = null, array $headers = [], array $files = [], ?MultipartBuilder $multipart = null): Future
    {
        return $this->request('PATCH', $url, headers: $headers, body: $body, files: $files, multipart: $multipart);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string|list<string>> $headers
     */
    public function delete(string $url, array $query = [], array $headers = []): Future
    {
        return $this->request('DELETE', $url, query: $query, headers: $headers);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string|list<string>> $headers
     * @param array<string, string> $files
     */
    public function request(
        string            $method,
        string            $url,
        array             $query = [],
        array             $headers = [],
        mixed             $body = null,
        array             $files = [],
        ?MultipartBuilder $multipart = null
    ): Future {
        $request = new Request(
            strtoupper($method),
            $this->resolveUrl($url),
            $headers === []
                ? Headers::from($this->options->headers)
                : Headers::from($this->options->headers)->merge($headers),
            $body,
            $query,
            $files,
            $multipart,
            $this->options
        );

        return $this->loop->async(fn () => $this->sendWithRetries($request));
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string|list<string>> $headers
     */
    public function streamGet(string $url, array $query = [], array $headers = []): Future
    {
        return $this->streamRequest('GET', $url, query: $query, headers: $headers);
    }

    /**
     * @param array<string, string|list<string>> $headers
     * @param array<string, string> $files
     */
    public function streamPost(string $url, mixed $body = null, array $headers = [], array $files = [], ?MultipartBuilder $multipart = null): Future
    {
        return $this->streamRequest('POST', $url, headers: $headers, body: $body, files: $files, multipart: $multipart);
    }

    /**
     * @param array<string, string|list<string>> $headers
     * @param array<string, string> $files
     */
    public function streamPut(string $url, mixed $body = null, array $headers = [], array $files = [], ?MultipartBuilder $multipart = null): Future
    {
        return $this->streamRequest('PUT', $url, headers: $headers, body: $body, files: $files, multipart: $multipart);
    }

    /**
     * @param array<string, string|list<string>> $headers
     * @param array<string, string> $files
     */
    public function streamPatch(string $url, mixed $body = null, array $headers = [], array $files = [], ?MultipartBuilder $multipart = null): Future
    {
        return $this->streamRequest('PATCH', $url, headers: $headers, body: $body, files: $files, multipart: $multipart);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string|list<string>> $headers
     */
    public function streamDelete(string $url, array $query = [], array $headers = []): Future
    {
        return $this->streamRequest('DELETE', $url, query: $query, headers: $headers);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string|list<string>> $headers
     * @param array<string, string> $files
     */
    public function streamRequest(
        string            $method,
        string            $url,
        array             $query = [],
        array             $headers = [],
        mixed             $body = null,
        array             $files = [],
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

        return $this->loop->async(fn () => $this->sendStreamWithRetries($request));
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string|list<string>> $headers
     */
    public function streamSseGet(
        string    $url,
        array     $query = [],
        array     $headers = [],
        bool      $requireDone = false,
        ?callable $completionDetector = null
    ): Future {
        return $this->streamSseRequest(
            'GET',
            url: $url,
            query: $query,
            headers: $headers,
            requireDone: $requireDone,
            completionDetector: $completionDetector,
        );
    }

    /** @param array<string, string|list<string>> $headers */
    public function streamSsePost(
        string    $url,
        mixed     $body = null,
        array     $headers = [],
        bool      $requireDone = false,
        ?callable $completionDetector = null,
    ): Future {
        return $this->streamSseRequest(
            'POST',
            $url,
            headers: $headers,
            body: $body,
            requireDone: $requireDone,
            completionDetector: $completionDetector,
        );
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string|list<string>> $headers
     * @param array<string, string> $files
     */
    public function streamSseRequest(
        string            $method,
        string            $url,
        array             $query = [],
        array             $headers = [],
        mixed             $body = null,
        array             $files = [],
        ?MultipartBuilder $multipart = null,
        bool              $requireDone = false,
        ?callable         $completionDetector = null
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

        return $this->loop->async(fn () => $this->sendSseStreamWithRetries($request, $requireDone, $completionDetector));
    }

    /** @param array<array-key, callable(): mixed> $tasks */
    public function concurrent(array $tasks): Future
    {
        return $this->concurrentExecutor->named($tasks);
    }

    /** @param array<array-key, Future> $futures */
    public function parallel(array $futures): Future
    {
        return $this->concurrentExecutor->all($futures);
    }

    /** @param array<array-key, Future> $futures */
    public function awaitAny(array $futures): Future
    {
        return $this->concurrentExecutor->any($futures);
    }

    /** @param array<array-key, Future> $futures */
    public function race(array $futures): Future
    {
        return $this->concurrentExecutor->race($futures);
    }

    /** @param array<array-key, Future> $futures */
    public function settled(array $futures): Future
    {
        return $this->concurrentExecutor->settled($futures);
    }

    public function withHeader(string $name, string $value): self
    {
        return $this->withOptions($this->options->withHeader($name, $value));
    }

    /** @param array<string, string|list<string>> $headers */
    public function withHeaders(array $headers): self
    {
        return $this->withOptions($this->options->withHeaders($headers));
    }

    public function withCookie(string $name, string $value, string $domain = '', string $path = '/'): self
    {
        return $this->withOptions($this->options->withCookie($name, $value, $domain, $path));
    }

    /** @param array<string, scalar> $cookies */
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

    public function withCompression(bool $enabled = true): self
    {
        return $this->withOptions($this->options->withCompression($enabled));
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

    public function withAllowInsecureRedirects(bool $allow = true): self
    {
        return $this->withOptions($this->options->withAllowInsecureRedirects($allow));
    }

    /** @param list<string> $headers */
    public function withSensitiveHeaders(array $headers): self
    {
        return $this->withOptions($this->options->withSensitiveHeaders($headers));
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
        // Derived clients get an independent cookie store unless a builder
        // already supplied a cloned jar (withCookie/withCookies).
        if ($options->cookieJar === $this->options->cookieJar) {
            $options->cookieJar = clone $options->cookieJar;
        }

        return new self($this->loop, $options, $this->connectionPool, $this->transport);
    }

    private function resolveUrl(string $url): string
    {
        if ($this->options->baseUrl === null) {
            return $url;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1) {
            return $url;
        }

        $reference = parse_url($url);
        if ($reference === false) {
            return $url;
        }

        $base = parse_url($this->options->baseUrl);
        if ($base === false || !isset($base['scheme'], $base['host'])) {
            throw new \InvalidArgumentException('baseUrl must be an absolute URI with a scheme and host');
        }

        $scheme = (string) $base['scheme'];
        $authorityParts = $base;

        if (isset($reference['host'])) {
            $authorityParts = $reference;
            $path = self::removeDotSegments((string) ($reference['path'] ?? ''));
            $query = $reference['query'] ?? null;
        } else {
            $basePath = (string) ($base['path'] ?? '');
            $referencePath = (string) ($reference['path'] ?? '');

            if ($referencePath === '') {
                $path = $basePath;
                $query = array_key_exists('query', $reference)
                    ? $reference['query']
                    : ($base['query'] ?? null);
            } else {
                $path = self::removeDotSegments(
                    str_starts_with($referencePath, '/')
                        ? $referencePath
                        : self::mergeUriPaths($basePath, $referencePath)
                );
                $query = $reference['query'] ?? null;
            }
        }

        $resolved = $scheme . '://' . self::uriAuthority($authorityParts) . $path;
        if ($query !== null) {
            $resolved .= '?' . $query;
        }
        if (array_key_exists('fragment', $reference)) {
            $resolved .= '#' . $reference['fragment'];
        }

        return $resolved;
    }

    /** @param array<string, mixed> $parts */
    private static function uriAuthority(array $parts): string
    {
        $authority = '';
        if (isset($parts['user'])) {
            $authority .= $parts['user'];
            if (isset($parts['pass'])) {
                $authority .= ':' . $parts['pass'];
            }
            $authority .= '@';
        }

        $authority .= (string) $parts['host'];
        if (isset($parts['port'])) {
            $authority .= ':' . $parts['port'];
        }

        return $authority;
    }

    private static function mergeUriPaths(string $basePath, string $referencePath): string
    {
        if ($basePath === '') {
            return '/' . $referencePath;
        }

        $lastSlash = strrpos($basePath, '/');
        return ($lastSlash === false ? '' : substr($basePath, 0, $lastSlash + 1)) . $referencePath;
    }

    private static function removeDotSegments(string $path): string
    {
        $input = $path;
        $output = '';

        while ($input !== '') {
            if (str_starts_with($input, '../')) {
                $input = substr($input, 3);
            } elseif (str_starts_with($input, './')) {
                $input = substr($input, 2);
            } elseif (str_starts_with($input, '/./')) {
                $input = substr($input, 2);
            } elseif ($input === '/.') {
                $input = '/';
            } elseif (str_starts_with($input, '/../')) {
                $input = substr($input, 3);
                $output = preg_replace('~/[^/]*$~', '', $output) ?? $output;
            } elseif ($input === '/..') {
                $input = '/';
                $output = preg_replace('~/[^/]*$~', '', $output) ?? $output;
            } elseif ($input === '.' || $input === '..') {
                $input = '';
            } else {
                $nextSlash = strpos($input, '/', $input[0] === '/' ? 1 : 0);
                if ($nextSlash === false) {
                    $output .= $input;
                    $input = '';
                } else {
                    $output .= substr($input, 0, $nextSlash);
                    $input = substr($input, $nextSlash);
                }
            }
        }

        return $output;
    }

    private function sendStreamWithRetries(Request $request): StreamResponse
    {
        $attempt = 0;
        $options = $request->options ?? $this->options;
        $options = $options->withTotalDeadlineNs($options->totalDeadlineNs ?? hrtime(true) + (int) ($options->totalTimeout * 1_000_000_000));
        $maxAttempts = $options->retries;

        do {
            $this->ensureDeadline($options);
            $attempt++;

            try {
                $response = $this->transport->sendStream($request, $options);

                if ($response->status() >= 500 && $attempt <= $maxAttempts) {
                    $exception = new HttpException(
                        'HTTP request failed with status ' . $response->status(),
                        $request
                    );

                    if ($this->shouldRetry($request, $exception)) {
                        $response->close();
                        $this->sleepRetryDelay($request, $attempt, $options);
                        continue;
                    }
                }

                return $response;
            } catch (HttpException|TimeoutException|ConnectionException $exception) {
                if ($attempt > $maxAttempts || !$this->shouldRetry($request, $exception)) {
                    throw $exception;
                }

                $this->sleepRetryDelay($request, $attempt, $options);
            }
        } while (true);
    }

    private function sendSseStreamWithRetries(Request $request, bool $requireDone, ?callable $completionDetector = null): SseStream
    {
        return new SseStream($this->sendStreamWithRetries($request), new SseParser(), $requireDone, $completionDetector);
    }

    public static function doneMarkerCompletionDetector(string $marker = '[DONE]'): callable
    {
        return static fn (SseEvent $event): bool => trim($event->data()) === $marker;
    }

    private function sleepRetryDelay(Request $request, int $attempt, ?RequestOptions $options = null): void
    {
        $options ??= $request->options ?? $this->options;
        $delayMs = $options->retryDelayMs;
        if ($options->exponentialBackoff) {
            $delayMs *= 2 ** ($attempt - 1);
        }

        if ($options->totalDeadlineNs !== null) {
            $remainingMs = max(0, (int) (($options->totalDeadlineNs - hrtime(true)) / 1_000_000));
            $delayMs = min($delayMs, $remainingMs);
        }
        $this->loop->sleep($delayMs / 1000);
        $this->ensureDeadline($options);
    }

    private function sendWithRetries(Request $request): Response
    {
        $attempt = 0;
        $options = $request->options ?? $this->options;
        $options = $options->withTotalDeadlineNs($options->totalDeadlineNs ?? hrtime(true) + (int) ($options->totalTimeout * 1_000_000_000));
        $maxAttempts = $options->retries;

        do {
            $this->ensureDeadline($options);
            $attempt++;

            try {
                $response = $this->transport->send($request, $options);

                if ($response->status() >= 500 && $attempt <= $maxAttempts) {
                    $exception = new HttpException(
                        'HTTP request failed with status ' . $response->status(),
                        $request,
                        $response
                    );

                    if ($this->shouldRetry($request, $exception)) {
                        $this->sleepRetryDelay($request, $attempt, $options);
                        continue;
                    }
                }

                return $response;
            } catch (HttpException $exception) {
                if ($attempt > $maxAttempts || !$this->shouldRetry($request, $exception)) {
                    throw $exception;
                }

                $this->sleepRetryDelay($request, $attempt, $options);
            }
        } while (true);
    }

    private function ensureDeadline(RequestOptions $options): void
    {
        if ($options->totalDeadlineNs !== null && hrtime(true) >= $options->totalDeadlineNs) {
            throw new TimeoutException('Total request timeout exceeded');
        }
    }

    private function shouldRetry(Request $request, HttpException $exception): bool
    {
        $isIdempotent = in_array($request->method, ['GET', 'HEAD', 'OPTIONS', 'PUT', 'DELETE'], true);

        if ($exception instanceof TimeoutException || $exception instanceof ConnectionException) {
            return $isIdempotent;
        }

        $response = $exception->response();
        if ($response !== null && $response->status() >= 500) {
            return $isIdempotent;
        }

        return $isIdempotent;
    }
}
