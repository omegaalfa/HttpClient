<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Http;

use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use PHPUnit\Framework\TestCase;
use function Omegaalfa\HttpClient\Http\await;

final class HttpsProxyIntegrationTest extends TestCase
{
    private static $certificate;
    private static $httpsServer;
    private static $proxyServer;

    public static function setUpBeforeClass(): void
    {
        self::$certificate = new \Tests\Omegaalfa\HttpClient\Support\SelfSignedCertificate('127.0.0.1');
        self::$httpsServer = new \Tests\Omegaalfa\HttpClient\Support\LocalHttpsServer(self::$certificate->combinedPem());
        self::$proxyServer = new \Tests\Omegaalfa\HttpClient\Support\LocalConnectProxyServer('127.0.0.1', self::$httpsServer->port());
    }

    public static function tearDownAfterClass(): void
    {
        self::$proxyServer->stop();
        self::$httpsServer->stop();
    }

    public function testHttpsRequestThroughConnectProxy(): void
    {
        $client = (new AsyncHttpClient())
            ->withProxy(self::$proxyServer->baseUrl())
            ->withVerifySSL(false)
            ->withFollowRedirects(false);

        $response = await($client->get(self::$httpsServer->baseUrl() . '/secure'));
        $data = $response->json();

        self::assertSame(200, $response->status());
        self::assertTrue($data['ok']);
        self::assertSame('GET', $data['method']);
        self::assertSame('/secure', $data['path']);
    }
}
