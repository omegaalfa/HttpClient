<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Http;

use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use Omegaalfa\HttpClient\Http\Headers;
use Omegaalfa\HttpClient\Http\Request;
use Omegaalfa\HttpClient\Http\RequestOptions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AsyncHttpClientInternalTest extends TestCase
{
    public function testResolveUrlKeepsAbsoluteUrl(): void
    {
        $client = new AsyncHttpClient();

        $resolved = $this->invokePrivate($client, 'resolveUrl', 'https://api.example.com/users');
        self::assertSame('https://api.example.com/users', $resolved);
    }

    public function testResolveUrlUsesBaseUrlForRelativePath(): void
    {
        $client = (new AsyncHttpClient())->withBaseUrl('https://api.example.com/v1');

        $resolved = $this->invokePrivate($client, 'resolveUrl', '/users');
        self::assertSame('https://api.example.com/v1/users', $resolved);
    }

    public function testIsRedirectRecognizesSupportedStatusCodes(): void
    {
        $client = new AsyncHttpClient();

        self::assertTrue($this->invokePrivate($client, 'isRedirect', 301));
        self::assertTrue($this->invokePrivate($client, 'isRedirect', 308));
        self::assertFalse($this->invokePrivate($client, 'isRedirect', 200));
        self::assertFalse($this->invokePrivate($client, 'isRedirect', 404));
    }

    public function testResolveRedirectUrlVariants(): void
    {
        $client = new AsyncHttpClient();

        $absolute = $this->invokePrivate($client, 'resolveRedirectUrl', 'https://a.com/x', 'https://b.com/y');
        self::assertSame('https://b.com/y', $absolute);

        $rootRelative = $this->invokePrivate($client, 'resolveRedirectUrl', 'https://a.com/base/path', '/new');
        self::assertSame('https://a.com/new', $rootRelative);

        $relative = $this->invokePrivate($client, 'resolveRedirectUrl', 'https://a.com/base/path', 'next');
        self::assertSame('https://a.com/base/next', $relative);
    }

    public function testExtractCookiesReadsSetCookieValues(): void
    {
        $client = new AsyncHttpClient();
        $headers = new Headers();
        $headers->add('Set-Cookie', [
            'a=1; Path=/',
            'b=2; HttpOnly',
            'invalid-cookie-line',
        ]);

        $cookies = $this->invokePrivate($client, 'extractCookies', $headers);

        self::assertSame(['a' => '1', 'b' => '2'], $cookies);
    }

    public function testDecodeChunkedBody(): void
    {
        $client = new AsyncHttpClient();

        $raw = "4\r\nWiki\r\n5\r\npedia\r\n0\r\n\r\n";
        $decoded = $this->invokePrivate($client, 'decodeChunkedBody', $raw);

        self::assertSame('Wikipedia', $decoded);
    }

    public function testPrepareRequestAddsQueryHeadersAndJsonBody(): void
    {
        $options = (new RequestOptions())
            ->withUserAgent('TestAgent/1.0')
            ->withBearerToken('token')
            ->withCookie('sid', 'abc', 'api.example.com', '/')
            ->withKeepAlive(true);

        $request = new Request(
            'POST',
            'https://api.example.com/items',
            new Headers(),
            ['name' => 'book'],
            ['page' => 1],
            [],
            null,
            $options
        );

        $client = new AsyncHttpClient();
        $prepared = $this->invokePrivate($client, 'prepareRequest', $request, $options);

        self::assertSame('https://api.example.com/items?page=1', $prepared['url']);
        self::assertSame('POST', $prepared['method']);
        self::assertSame('api.example.com', $prepared['headers']->get('Host'));
        self::assertSame('keep-alive', $prepared['headers']->get('Connection'));
        self::assertSame('TestAgent/1.0', $prepared['headers']->get('User-Agent'));
        self::assertSame('Bearer token', $prepared['headers']->get('Authorization'));
        self::assertStringContainsString('sid=abc', (string) $prepared['headers']->get('Cookie'));
        self::assertSame('application/json', $prepared['headers']->get('Content-Type'));
        self::assertSame('application/json', $prepared['headers']->get('Accept'));
        self::assertSame('{"name":"book"}', $prepared['body']);
        self::assertSame((string) strlen('{"name":"book"}'), $prepared['headers']->get('Content-Length'));
    }

    public function testPrepareRequestSupportsFormUrlEncodedArrayBody(): void
    {
        $options = new RequestOptions();
        $request = new Request(
            'POST',
            'http://example.com/token',
            new Headers(['Content-Type' => 'application/x-www-form-urlencoded']),
            ['grant_type' => 'client_credentials', 'scope' => 'read'],
            [],
            [],
            null,
            $options
        );

        $client = new AsyncHttpClient();
        $prepared = $this->invokePrivate($client, 'prepareRequest', $request, $options);

        self::assertSame('grant_type=client_credentials&scope=read', $prepared['body']);
    }

    public function testPrepareRequestBuildsMultipartBodyFromFilesAndFields(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'httpclient-multipart-');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, 'demo-file-content');

        try {
            $options = new RequestOptions();
            $request = new Request(
                'POST',
                'http://example.com/upload',
                new Headers(),
                ['meta' => ['id' => 10]],
                [],
                ['file1' => $tmpFile],
                null,
                $options
            );

            $client = new AsyncHttpClient();
            $prepared = $this->invokePrivate($client, 'prepareRequest', $request, $options);

            self::assertStringContainsString('multipart/form-data; boundary=', (string) $prepared['headers']->get('Content-Type'));
            self::assertStringContainsString('name="meta"', $prepared['body']);
            self::assertStringContainsString('{"id":10}', $prepared['body']);
            self::assertStringContainsString('name="file1"', $prepared['body']);
            self::assertStringContainsString('demo-file-content', $prepared['body']);
        } finally {
            if (is_file($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    public function testBuildRequestPayloadFormatsStartLineHeadersAndBody(): void
    {
        $client = new AsyncHttpClient();
        $headers = new Headers([
            'Host' => 'example.com',
            'Content-Type' => 'application/json',
        ]);

        $payload = $this->invokePrivate(
            $client,
            'buildRequestPayload',
            'POST',
            '/api/items?x=1',
            $headers,
            '{"a":1}'
        );

        self::assertStringStartsWith("POST /api/items?x=1 HTTP/1.1\r\n", $payload);
        self::assertStringContainsString("Host: example.com\r\n", $payload);
        self::assertStringContainsString("Content-Type: application/json\r\n", $payload);
        self::assertStringEndsWith("\r\n\r\n{\"a\":1}", $payload);
    }

    public function testReadResponseParsesContentLengthResponse(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);

        [$readSocket, $writeSocket] = $sockets;

        fwrite($writeSocket, "HTTP/1.1 200 OK\r\nContent-Length: 5\r\nX-Test: ok\r\n\r\nhello");
        fclose($writeSocket);

        $client = new AsyncHttpClient();
        $options = (new RequestOptions())
            ->withReadTimeout(1.0)
            ->withTotalTimeout(1.0);

        [$status, $headers, $body] = $this->invokePrivate($client, 'readResponse', $readSocket, $options);

        fclose($readSocket);

        self::assertSame(200, $status);
        self::assertSame('ok', $headers->get('X-Test'));
        self::assertSame('hello', $body);
    }

    public function testReadResponseParsesChunkedResponse(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);

        [$readSocket, $writeSocket] = $sockets;

        fwrite(
            $writeSocket,
            "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n4\r\nWiki\r\n5\r\npedia\r\n0\r\n\r\n"
        );
        fclose($writeSocket);

        $client = new AsyncHttpClient();
        $options = (new RequestOptions())
            ->withReadTimeout(1.0)
            ->withTotalTimeout(1.0);

        [$status, $headers, $body] = $this->invokePrivate($client, 'readResponse', $readSocket, $options);

        fclose($readSocket);

        self::assertSame(200, $status);
        self::assertStringContainsString('chunked', strtolower((string) $headers->get('Transfer-Encoding')));
        self::assertSame('Wikipedia', $body);
    }

    private function invokePrivate(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new ReflectionClass($object);
        $private = $reflection->getMethod($method);
        $private->setAccessible(true);

        return $private->invoke($object, ...$args);
    }
}
