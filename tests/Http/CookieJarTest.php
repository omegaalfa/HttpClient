<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Http;

use Omegaalfa\HttpClient\Http\CookieJar;
use Omegaalfa\HttpClient\Http\Headers;
use PHPUnit\Framework\TestCase;

final class CookieJarTest extends TestCase
{
    public function testSetAndAll(): void
    {
        $jar = new CookieJar();
        $jar->set('session', 'abc', 'example.com', '/api');

        $all = $jar->all();
        self::assertArrayHasKey('example.com', $all);
        self::assertSame('abc', $all['example.com']['/api']['session']['value']);
    }

    public function testSetManyStoresAllCookies(): void
    {
        $jar = new CookieJar();
        $jar->setMany(['a' => '1', 'b' => '2'], 'example.com', '/');

        self::assertSame('a=1; b=2', $jar->headerFor('example.com', '/', false));
    }

    public function testStoreFromHeadersParsesAttributesAndFlags(): void
    {
        $headers = new Headers();
        $headers->add('Set-Cookie', [
            'token=xyz; Domain=.example.com; Path=/secure; Secure; HttpOnly',
        ]);

        $jar = new CookieJar();
        $jar->storeFromHeaders($headers, 'api.example.com');

        self::assertNull($jar->headerFor('api.example.com', '/secure', false));
        self::assertSame('token=xyz', $jar->headerFor('api.example.com', '/secure', true));
    }

    public function testHeaderForMatchesSubdomainAndPath(): void
    {
        $jar = new CookieJar();
        $jar->set('global', '1', 'example.com', '/');
        $jar->set('api_only', '2', 'api.example.com', '/v1');

        self::assertSame('global=1; api_only=2', $jar->headerFor('api.example.com', '/v1/users', false));
        self::assertSame('global=1', $jar->headerFor('app.example.com', '/v1/users', false));
    }

    public function testHeaderForSkipsExpiredCookies(): void
    {
        $jar = new CookieJar();
        $jar->set('old', 'x', 'example.com', '/', time() - 10);
        $jar->set('fresh', 'y', 'example.com', '/', time() + 3600);

        self::assertSame('fresh=y', $jar->headerFor('example.com', '/', false));
    }

    public function testStoreFromHeadersUsesHostWhenDomainMissing(): void
    {
        $headers = new Headers();
        $headers->add('Set-Cookie', 'pref=pt; Path=/');

        $jar = new CookieJar();
        $jar->storeFromHeaders($headers, 'service.local');

        self::assertSame('pref=pt', $jar->headerFor('service.local', '/', false));
    }

    public function testHeaderForMatchesDomainOnlyAtLabelBoundary(): void
    {
        $jar = new CookieJar();
        $jar->set('session', 'secret', 'evil.com', '/');

        self::assertNull($jar->headerFor('notevil.com', '/', false));
        self::assertNull($jar->headerFor('xevil.com', '/', false));
        self::assertSame('session=secret', $jar->headerFor('evil.com', '/', false));
        self::assertSame('session=secret', $jar->headerFor('x.evil.com', '/', false));
    }

    public function testStoreFromHeadersRejectsDomainNotMatchingOriginHost(): void
    {
        $headers = new Headers();
        $headers->add('Set-Cookie', 'session=leak; Domain=bank.com; Path=/');

        $jar = new CookieJar();
        $jar->storeFromHeaders($headers, 'evil.com');

        self::assertSame([], $jar->all());
        self::assertNull($jar->headerFor('evil.com', '/', false));
        self::assertNull($jar->headerFor('bank.com', '/', false));
    }

    public function testStoreFromHeadersRejectsDomainWithoutLabelBoundaryMatch(): void
    {
        $headers = new Headers();
        $headers->add('Set-Cookie', 'session=leak; Domain=vil.com; Path=/');

        $jar = new CookieJar();
        $jar->storeFromHeaders($headers, 'evil.com');

        self::assertSame([], $jar->all());
    }

    public function testStoreFromHeadersAcceptsParentDomain(): void
    {
        $headers = new Headers();
        $headers->add('Set-Cookie', 'token=ok; Domain=.example.com; Path=/');

        $jar = new CookieJar();
        $jar->storeFromHeaders($headers, 'api.example.com');

        self::assertSame('token=ok', $jar->headerFor('api.example.com', '/', false));
        self::assertSame('token=ok', $jar->headerFor('other.example.com', '/', false));
        self::assertNull($jar->headerFor('example.org', '/', false));
    }

    public function testStoreFromHeadersIgnoresEmptyDomainAttribute(): void
    {
        $headers = new Headers();
        $headers->add('Set-Cookie', 'pref=1; Domain=; Path=/');

        $jar = new CookieJar();
        $jar->storeFromHeaders($headers, 'service.local');

        self::assertSame('pref=1', $jar->headerFor('service.local', '/', false));
        self::assertNull($jar->headerFor('other.local', '/', false));
    }

    public function testMaxAgeTakesPrecedenceOverExpires(): void
    {
        $headers = new Headers();
        $headers->add('Set-Cookie', 'short=1; Expires=Wed, 21 Oct 2037 07:28:00 GMT; Max-Age=0');

        $jar = new CookieJar();
        $jar->storeFromHeaders($headers, 'service.local');

        self::assertNull($jar->headerFor('service.local', '/', false));
    }
}
