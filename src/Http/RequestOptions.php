<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Http;

use InvalidArgumentException;

final class RequestOptions
{
    /** @param array{0: string, 1: string}|null $basicAuth */
    public function __construct(
        public ?string $baseUrl = null,
        ?Headers $headers = null,
        ?CookieJar $cookieJar = null,
        public ?string $proxy = null,
        public string $userAgent = 'FiberEventLoop AsyncHttpClient/1.0',
        public bool $sendJson = true,
        public bool $compression = false,
        public ?string $bearerToken = null,
        public ?array $basicAuth = null,
        public int $retries = 0,
        public int $retryDelayMs = 100,
        public bool $exponentialBackoff = false,
        public int $followRedirects = 3,
        public bool $verifySsl = true,
        public bool $keepAlive = false,
        public float $connectTimeout = 10.0,
        public float $readTimeout = 30.0,
        public float $writeTimeout = 30.0,
        public float $totalTimeout = 60.0,
        public bool $allowInsecureRedirects = false,
        /** @var list<string> */
        public array $sensitiveHeaders = [],
        /** Absolute monotonic deadline shared by retries and redirects. */
        public ?int $totalDeadlineNs = null
    ) {
        self::assertValidTimeout($connectTimeout, 'connectTimeout');
        self::assertValidTimeout($readTimeout, 'readTimeout');
        self::assertValidTimeout($writeTimeout, 'writeTimeout');
        self::assertValidTimeout($totalTimeout, 'totalTimeout');
        $this->headers = $headers ?? new Headers();
        $this->cookieJar = $cookieJar ?? new CookieJar();
    }

    public Headers $headers;

    public CookieJar $cookieJar;

    public function withBaseUrl(string $baseUrl): self
    {
        $clone = clone $this;
        $clone->baseUrl = rtrim($baseUrl, '/');
        return $clone;
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers = $clone->headers->merge([$name => $value]);
        return $clone;
    }

    /** @param array<string, string|list<string>> $headers */
    public function withHeaders(array $headers): self
    {
        $clone = clone $this;
        $clone->headers = $clone->headers->merge($headers);
        return $clone;
    }

    public function withCookie(string $name, string $value, string $domain = '', string $path = '/'): self
    {
        $clone = clone $this;
        $clone->cookieJar = clone $this->cookieJar;
        $clone->cookieJar->set($name, $value, $domain, $path);
        return $clone;
    }

    /** @param array<string|int, scalar|null> $cookies */
    public function withCookies(array $cookies, string $domain = '', string $path = '/'): self
    {
        $clone = clone $this;
        $clone->cookieJar = clone $this->cookieJar;
        $clone->cookieJar->setMany($cookies, $domain, $path);
        return $clone;
    }

    public function withProxy(string $proxy): self
    {
        $clone = clone $this;
        $clone->proxy = $proxy;
        return $clone;
    }

    public function withUserAgent(string $userAgent): self
    {
        $clone = clone $this;
        $clone->userAgent = $userAgent;
        return $clone;
    }

    public function withJson(bool $enabled = true): self
    {
        $clone = clone $this;
        $clone->sendJson = $enabled;
        if ($enabled) {
            $clone->headers = $clone->headers
                ->merge(['Accept' => 'application/json'])
                ->merge(['Content-Type' => 'application/json']);
        }
        return $clone;
    }

    public function withCompression(bool $enabled = true): self
    {
        $clone = clone $this;
        $clone->compression = $enabled;
        return $clone;
    }

    public function withBearerToken(string $token): self
    {
        $clone = clone $this;
        $clone->bearerToken = $token;
        return $clone;
    }

    public function withBasicAuth(string $username, string $password): self
    {
        $clone = clone $this;
        $clone->basicAuth = [$username, $password];
        return $clone;
    }

    public function withRetries(int $retries): self
    {
        $clone = clone $this;
        $clone->retries = max(0, $retries);
        return $clone;
    }

    public function withRetryDelay(int $milliseconds): self
    {
        $clone = clone $this;
        $clone->retryDelayMs = max(0, $milliseconds);
        return $clone;
    }

    public function withExponentialBackoff(bool $enabled = true): self
    {
        $clone = clone $this;
        $clone->exponentialBackoff = $enabled;
        return $clone;
    }

    public function withFollowRedirects(int|bool $value): self
    {
        $clone = clone $this;
        $clone->followRedirects = is_bool($value) ? ($value ? 3 : 0) : max(0, $value);
        return $clone;
    }

    public function withVerifySSL(bool $verify): self
    {
        $clone = clone $this;
        $clone->verifySsl = $verify;
        return $clone;
    }

    public function withKeepAlive(bool $enabled = true): self
    {
        $clone = clone $this;
        $clone->keepAlive = $enabled;
        return $clone;
    }

    public function withTimeout(float $seconds): self
    {
        self::assertValidTimeout($seconds, 'timeout');
        $clone = clone $this;
        $clone->totalTimeout = $seconds;
        return $clone;
    }

    public function withConnectTimeout(float $seconds): self
    {
        self::assertValidTimeout($seconds, 'connectTimeout');
        $clone = clone $this;
        $clone->connectTimeout = $seconds;
        return $clone;
    }

    public function withReadTimeout(float $seconds): self
    {
        self::assertValidTimeout($seconds, 'readTimeout');
        $clone = clone $this;
        $clone->readTimeout = $seconds;
        return $clone;
    }

    public function withWriteTimeout(float $seconds): self
    {
        self::assertValidTimeout($seconds, 'writeTimeout');
        $clone = clone $this;
        $clone->writeTimeout = $seconds;
        return $clone;
    }

    public function withTotalTimeout(float $seconds): self
    {
        self::assertValidTimeout($seconds, 'totalTimeout');
        $clone = clone $this;
        $clone->totalTimeout = $seconds;
        return $clone;
    }

    public function withAllowInsecureRedirects(bool $allow = true): self
    {
        $clone = clone $this;
        $clone->allowInsecureRedirects = $allow;
        return $clone;
    }

    /** @param list<string> $headers */
    public function withSensitiveHeaders(array $headers): self
    {
        $clone = clone $this;
        $clone->sensitiveHeaders = array_values(array_unique(array_map('strtolower', $headers)));
        return $clone;
    }

    /** @internal */
    public function withTotalDeadlineNs(?int $deadlineNs): self
    {
        $clone = clone $this;
        $clone->totalDeadlineNs = $deadlineNs;
        return $clone;
    }

    private static function assertValidTimeout(float $seconds, string $name): void
    {
        if (!is_finite($seconds) || $seconds <= 0.0) {
            throw new InvalidArgumentException(sprintf('%s must be a finite number greater than zero', $name));
        }
    }
}
