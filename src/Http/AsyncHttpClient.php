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
        return new self($this->loop, $options, $this->connectionPool, $this->transport);
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

    private function sendStreamWithRetries(Request $request): StreamResponse
    {
        $attempt = 0;
        $options = $request->options ?? $this->options;
        $maxAttempts = $options->retries;

        do {
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
                        $this->sleepRetryDelay($request, $attempt);
                        continue;
                    }
                }

                return $response;
            } catch (HttpException|TimeoutException|ConnectionException $exception) {
                if ($attempt > $maxAttempts || !$this->shouldRetry($request, $exception)) {
                    throw $exception;
                }

                $this->sleepRetryDelay($request, $attempt);
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

    private function sleepRetryDelay(Request $request, int $attempt): void
    {
        $options = $request->options ?? $this->options;
        $delayMs = $options->retryDelayMs;
        if ($options->exponentialBackoff) {
            $delayMs *= 2 ** ($attempt - 1);
        }

        $this->loop->sleep($delayMs / 1000);
    }

    private function sendWithRetries(Request $request): Response
    {
        $attempt = 0;
        $options = $request->options ?? $this->options;
        $maxAttempts = $options->retries;

        do {
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
                        $this->sleepRetryDelay($request, $attempt);
                        continue;
                    }
                }

                return $response;
            } catch (HttpException $exception) {
                if ($attempt > $maxAttempts || !$this->shouldRetry($request, $exception)) {
                    throw $exception;
                }

                $this->sleepRetryDelay($request, $attempt);
            }
        } while (true);
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
