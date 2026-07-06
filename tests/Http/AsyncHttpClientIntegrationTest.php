<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Http;

use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use Omegaalfa\HttpClient\Http\Exceptions\ConnectionException;
use Omegaalfa\HttpClient\Http\Exceptions\TimeoutException;
use Omegaalfa\HttpClient\Http\MultipartBuilder;
use PHPUnit\Framework\TestCase;
use Tests\Omegaalfa\HttpClient\Support\LocalHttpServer;
use function Omegaalfa\HttpClient\Http\await;

final class AsyncHttpClientIntegrationTest extends TestCase
{
    private static LocalHttpServer $server;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        $router = $root . '/tests/Fixtures/router.php';

        self::$server = new LocalHttpServer();
        self::$server->start($router, $root);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    public function testGetRequestAndQueryString(): void
    {
        $client = new AsyncHttpClient();
        $response = await($client->get(self::$server->baseUrl() . '/json', ['lang' => 'pt-BR']));
        $data = $response->json();

        self::assertSame(200, $response->status());
        self::assertTrue($data['ok']);
        self::assertSame('GET', $data['method']);
        self::assertSame('pt-BR', $data['query']['lang']);
    }

    public function testPostJsonBody(): void
    {
        $client = (new AsyncHttpClient())->withJson();

        $response = await($client->post(self::$server->baseUrl() . '/post', [
            'title' => 'integration',
            'userId' => 1,
        ]));

        $data = $response->json();

        self::assertSame(200, $response->status());
        self::assertSame('POST', $data['method']);
        self::assertStringContainsString('application/json', (string) $data['content_type']);
        self::assertStringContainsString('"title":"integration"', $data['raw']);
    }

    public function testFollowRedirectsAndMarkRedirectedResponse(): void
    {
        $client = (new AsyncHttpClient())
            ->withFollowRedirects(3);

        $response = await($client->get(self::$server->baseUrl() . '/redirect'));
        $data = $response->json();

        self::assertSame(200, $response->status());
        self::assertTrue($response->redirected());
        self::assertTrue($data['ok']);
    }

    public function testCookieJarStoresAndSendsCookiesAutomatically(): void
    {
        $client = new AsyncHttpClient();

        $setCookie = await($client->get(self::$server->baseUrl() . '/set-cookie'));
        self::assertSame(200, $setCookie->status());

        $checkCookie = await($client->get(self::$server->baseUrl() . '/check-cookie'));
        $data = $checkCookie->json();

        self::assertStringContainsString('session_id=abc123', $data['cookie']);
    }

    public function testInvalidUrlRaisesConnectionException(): void
    {
        $this->expectException(ConnectionException::class);

        $client = new AsyncHttpClient();
        await($client->get('http://')); // Invalid host, should trigger connection error path.
    }

    public function testFollowRedirectsDisabledKeepsOriginalStatus(): void
    {
        $client = (new AsyncHttpClient())
            ->withFollowRedirects(false);

        $response = await($client->get(self::$server->baseUrl() . '/redirect'));

        self::assertSame(302, $response->status());
        self::assertFalse($response->redirected());
    }

    public function testReadTimeoutRaisesTimeoutException(): void
    {
        $this->expectException(TimeoutException::class);

        $client = (new AsyncHttpClient())
            ->readTimeout(0.05)
            ->totalTimeout(1.0);

        await($client->get(self::$server->baseUrl() . '/slow-read'));
    }

    public function testMultipartUploadSendsFieldsAndFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'httpclient-upload-');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, 'multipart-demo-content');

        try {
            $multipart = MultipartBuilder::make()
                ->field('description', 'demo-file')
                ->file('document', $tmpFile, 'demo.txt', 'text/plain');

            $client = new AsyncHttpClient();
            $response = await($client->post(self::$server->baseUrl() . '/post', body: ['meta' => 'x'], multipart: $multipart));
            $data = $response->json();

            self::assertSame(200, $response->status());
            self::assertStringContainsString('multipart/form-data', (string) $data['content_type']);
            self::assertSame('demo-file', $data['post']['description']);
            self::assertSame('x', $data['post']['meta']);
            self::assertSame('demo.txt', $data['files']['document']['name']);
            self::assertSame('text/plain', $data['files']['document']['type']);
            self::assertSame('multipart-demo-content', $data['files']['document']['content']);
        } finally {
            if (is_file($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    public function testRetriesAreAppliedForServerErrors(): void
    {
        $client = (new AsyncHttpClient())
            ->withRetries(2)
            ->withRetryDelay(10);

        await($client->get(self::$server->baseUrl() . '/retry-reset'));
        $response = await($client->get(self::$server->baseUrl() . '/retry-500'));
        $counter = await($client->get(self::$server->baseUrl() . '/retry-counter'));
        $counterData = $counter->json();

        self::assertSame(500, $response->status());
        self::assertSame(3, $counterData['count'], 'Expected 1 original attempt + 2 retries.');
    }

    public function testHttpProxyOptionIsAppliedForHttpRequests(): void
    {
        $proxyAddress = str_replace('http://', '', self::$server->baseUrl());
        $client = (new AsyncHttpClient())
            ->withProxy($proxyAddress);

        $response = await($client->get(self::$server->baseUrl() . '/json', ['proxy' => 'on']));
        $data = $response->json();

        self::assertSame(200, $response->status());
        self::assertSame('on', $data['query']['proxy']);
    }
}
