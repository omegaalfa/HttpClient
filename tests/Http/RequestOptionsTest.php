<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Http;

use Omegaalfa\HttpClient\Http\RequestOptions;
use PHPUnit\Framework\TestCase;

final class RequestOptionsTest extends TestCase
{
    public function testDefaultValuesAndDependencies(): void
    {
        $options = new RequestOptions();

        self::assertNull($options->baseUrl);
        self::assertSame('FiberEventLoop AsyncHttpClient/1.0', $options->userAgent);
        self::assertTrue($options->sendJson);
        self::assertSame(0, $options->retries);
        self::assertSame(100, $options->retryDelayMs);
        self::assertSame(3, $options->followRedirects);
        self::assertTrue($options->verifySsl);
        self::assertFalse($options->keepAlive);
        self::assertSame(10.0, $options->connectTimeout);
        self::assertSame(30.0, $options->readTimeout);
        self::assertSame(30.0, $options->writeTimeout);
        self::assertSame(60.0, $options->totalTimeout);
        self::assertNotNull($options->headers);
        self::assertNotNull($options->cookieJar);
    }

    public function testWithHeaderAndWithHeadersAreImmutable(): void
    {
        $base = new RequestOptions();
        $changed = $base->withHeader('X-One', '1')->withHeaders(['X-Two' => '2']);

        self::assertNull($base->headers->get('X-One'));
        self::assertNull($base->headers->get('X-Two'));
        self::assertSame('1', $changed->headers->get('X-One'));
        self::assertSame('2', $changed->headers->get('X-Two'));
    }

    public function testWithBaseUrlTrimsTrailingSlash(): void
    {
        $base = new RequestOptions();
        $changed = $base->withBaseUrl('https://api.example.com/');

        self::assertNull($base->baseUrl);
        self::assertSame('https://api.example.com', $changed->baseUrl);
    }

    public function testWithCookieAndWithCookiesCloneCookieJar(): void
    {
        $base = new RequestOptions();
        $changed = $base
            ->withCookie('session', 'abc', 'example.com')
            ->withCookies(['a' => '1', 'b' => '2'], 'example.com', '/');

        self::assertNull($base->cookieJar->headerFor('example.com', '/', false));
        self::assertSame('session=abc; a=1; b=2', $changed->cookieJar->headerFor('example.com', '/', false));
    }

    public function testAuthenticationAndUserAgentAndProxy(): void
    {
        $options = (new RequestOptions())
            ->withUserAgent('MyApp/2.0')
            ->withProxy('http://127.0.0.1:8080')
            ->withBearerToken('token-123')
            ->withBasicAuth('user', 'pass');

        self::assertSame('MyApp/2.0', $options->userAgent);
        self::assertSame('http://127.0.0.1:8080', $options->proxy);
        self::assertSame('token-123', $options->bearerToken);
        self::assertSame(['user', 'pass'], $options->basicAuth);
    }

    public function testWithJsonEnabledInjectsAcceptAndContentType(): void
    {
        $enabled = (new RequestOptions())->withJson(true);
        $disabled = (new RequestOptions())->withJson(false);

        self::assertTrue($enabled->sendJson);
        self::assertSame('application/json', $enabled->headers->get('Accept'));
        self::assertSame('application/json', $enabled->headers->get('Content-Type'));

        self::assertFalse($disabled->sendJson);
    }

    public function testRetryAndRedirectConfiguration(): void
    {
        $options = (new RequestOptions())
            ->withRetries(-5)
            ->withRetryDelay(-10)
            ->withExponentialBackoff(true)
            ->withFollowRedirects(true);

        self::assertSame(0, $options->retries);
        self::assertSame(0, $options->retryDelayMs);
        self::assertTrue($options->exponentialBackoff);
        self::assertSame(3, $options->followRedirects);

        $noRedirect = $options->withFollowRedirects(false);
        self::assertSame(0, $noRedirect->followRedirects);

        $fixedRedirect = $options->withFollowRedirects(10);
        self::assertSame(10, $fixedRedirect->followRedirects);
    }

    public function testSslKeepAliveAndTimeouts(): void
    {
        $options = (new RequestOptions())
            ->withVerifySSL(false)
            ->withKeepAlive(true)
            ->withTimeout(77.0)
            ->withConnectTimeout(2.5)
            ->withReadTimeout(3.5)
            ->withWriteTimeout(4.5)
            ->withTotalTimeout(5.5);

        self::assertFalse($options->verifySsl);
        self::assertTrue($options->keepAlive);
        self::assertSame(77.0, $options->withTimeout(77.0)->totalTimeout);
        self::assertSame(2.5, $options->connectTimeout);
        self::assertSame(3.5, $options->readTimeout);
        self::assertSame(4.5, $options->writeTimeout);
        self::assertSame(5.5, $options->totalTimeout);
    }
}
