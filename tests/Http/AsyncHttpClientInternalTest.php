<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Http;

use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use Omegaalfa\HttpClient\Http\Exceptions\ConnectionException;
use Omegaalfa\HttpClient\Http\Headers;
use Omegaalfa\HttpClient\Http\Response;
use Omegaalfa\HttpClient\Http\Testing\MockTransport;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function Omegaalfa\HttpClient\Http\await;

final class AsyncHttpClientInternalTest extends TestCase
{
    public function testResolveUrlKeepsAbsoluteUrl(): void
    {
        $client = new AsyncHttpClient();

        $resolved = $this->invokePrivate($client, 'resolveUrl', 'https://api.example.com/users');
        self::assertSame('https://api.example.com/users', $resolved);
    }

    public function testResolveUrlFollowsRfc3986ForAbsolutePathReference(): void
    {
        $client = (new AsyncHttpClient())->withBaseUrl('https://api.example.com/v1');

        $resolved = $this->invokePrivate($client, 'resolveUrl', '/users');
        self::assertSame('https://api.example.com/users', $resolved);
    }

    public function testResolveUrlMergesRelativePathAndRemovesDotSegments(): void
    {
        $client = (new AsyncHttpClient())->withBaseUrl('http://a/b/c/d;p?q');

        self::assertSame(
            'http://a/b/c/g',
            $this->invokePrivate($client, 'resolveUrl', 'g')
        );
        self::assertSame(
            'http://a/b/g',
            $this->invokePrivate($client, 'resolveUrl', '../g')
        );
        self::assertSame(
            'http://a/b/c/d;p?y',
            $this->invokePrivate($client, 'resolveUrl', '?y')
        );
        self::assertSame(
            'http://a/b/c/d;p?q#s',
            $this->invokePrivate($client, 'resolveUrl', '#s')
        );
    }

    public function testResolveUrlPreservesTrailingBasePathSlash(): void
    {
        $client = (new AsyncHttpClient())->withBaseUrl('https://api.example.com/v1/');

        self::assertSame(
            'https://api.example.com/v1/users',
            $this->invokePrivate($client, 'resolveUrl', 'users')
        );
    }

    public function testResolveUrlSupportsNetworkPathReferences(): void
    {
        $client = (new AsyncHttpClient())->withBaseUrl('https://api.example.com/v1/resource');

        self::assertSame(
            'https://cdn.example.net/assets/app.js',
            $this->invokePrivate($client, 'resolveUrl', '//cdn.example.net/assets/./app.js')
        );
    }

    public function testRetriesIdempotentRequestsOnServerErrors(): void
    {
        $transport = new MockTransport([
            new Response(500, new Headers(), 'boom'),
            new Response(200, new Headers(), 'ok'),
        ]);

        $client = (new AsyncHttpClient(transport: $transport))
            ->withRetries(2)
            ->withRetryDelay(1);

        $response = await($client->get('https://api.example.com/x'));

        self::assertSame(200, $response->status());
        self::assertSame('ok', $response->body());
        self::assertCount(2, $transport->requests());
        self::assertSame('GET', $transport->lastRequest()?->method);
    }

    public function testDoesNotRetryNonIdempotentRequests(): void
    {
        $transport = new MockTransport([
            new Response(500, new Headers(), 'boom'),
        ]);

        $client = (new AsyncHttpClient(transport: $transport))
            ->withRetries(2)
            ->withRetryDelay(1);

        $response = await($client->post('https://api.example.com/x', ['a' => 1]));

        self::assertSame(500, $response->status());
        self::assertCount(1, $transport->requests());
    }

    public function testRetriesConnectionErrorsForIdempotentRequests(): void
    {
        $transport = new MockTransport([
            new ConnectionException('boom'),
            new Response(200, new Headers(), 'ok'),
        ]);

        $client = (new AsyncHttpClient(transport: $transport))
            ->withRetries(2)
            ->withRetryDelay(1);

        $response = await($client->get('https://api.example.com/x'));

        self::assertSame(200, $response->status());
        self::assertCount(2, $transport->requests());
    }

    public function testRethrowsConnectionErrorWhenRetriesAreExhausted(): void
    {
        $transport = new MockTransport([
            new ConnectionException('boom'),
            new ConnectionException('boom'),
        ]);

        $client = (new AsyncHttpClient(transport: $transport))
            ->withRetries(1)
            ->withRetryDelay(1);

        $this->expectException(ConnectionException::class);

        try {
            await($client->get('https://api.example.com/x'));
        } finally {
            self::assertCount(2, $transport->requests());
        }
    }

    public function testWithMethodsKeepInjectedTransport(): void
    {
        $transport = new MockTransport([
            new Response(200, new Headers(), 'ok'),
        ]);

        $client = (new AsyncHttpClient(transport: $transport))
            ->withBaseUrl('https://api.example.com')
            ->withHeader('X-Test', '1');

        $response = await($client->get('/via-base'));

        self::assertSame('ok', $response->body());
        self::assertSame('https://api.example.com/via-base', $transport->lastRequest()?->url);
    }

    public function testDerivedClientGetsAnIndependentCookieJar(): void
    {
        $base = new AsyncHttpClient();
        $derived = $base->withHeader('X-Tenant', 'tenant-b');
        $optionsProperty = new \ReflectionProperty(AsyncHttpClient::class, 'options');
        $baseOptions = $optionsProperty->getValue($base);
        $derivedOptions = $optionsProperty->getValue($derived);

        self::assertNotSame($baseOptions->cookieJar, $derivedOptions->cookieJar);

        $baseOptions->cookieJar->set('session', 'tenant-a', 'api.example.com');
        self::assertSame('session=tenant-a', $baseOptions->cookieJar->headerFor('api.example.com'));
        self::assertNull($derivedOptions->cookieJar->headerFor('api.example.com'));
    }

    private function invokePrivate(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new ReflectionClass($object);
        $private = $reflection->getMethod($method);

        return $private->invoke($object, ...$args);
    }
}
