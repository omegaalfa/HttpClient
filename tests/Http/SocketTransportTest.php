<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Http;

use Omegaalfa\FiberEventLoop\FiberEventLoop;
use Omegaalfa\HttpClient\Http\Exceptions\ConnectionException;
use Omegaalfa\HttpClient\Http\CookieJar;
use Omegaalfa\HttpClient\Http\Headers;
use Omegaalfa\HttpClient\Http\Request;
use Omegaalfa\HttpClient\Http\RequestOptions;
use Omegaalfa\HttpClient\Http\SocketTransport;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SocketTransportTest extends TestCase
{
    public function testIsRedirectRecognizesSupportedStatusCodes(): void
    {
        $transport = new SocketTransport(new FiberEventLoop());

        self::assertTrue($this->invokePrivate($transport, 'isRedirect', 301));
        self::assertTrue($this->invokePrivate($transport, 'isRedirect', 308));
        self::assertFalse($this->invokePrivate($transport, 'isRedirect', 200));
        self::assertFalse($this->invokePrivate($transport, 'isRedirect', 404));
    }

    public function testResolveRedirectUrlVariants(): void
    {
        $transport = new SocketTransport(new FiberEventLoop());

        $absolute = $this->invokePrivate($transport, 'resolveRedirectUrl', 'https://a.com/x', 'https://b.com/y');
        self::assertSame('https://b.com/y', $absolute);

        $rootRelative = $this->invokePrivate($transport, 'resolveRedirectUrl', 'https://a.com/base/path', '/new');
        self::assertSame('https://a.com/new', $rootRelative);

        $relative = $this->invokePrivate($transport, 'resolveRedirectUrl', 'https://a.com/base/path', 'next');
        self::assertSame('https://a.com/base/next', $relative);
    }

    public function testBuildRedirectedRequestStripsCredentialsOnCrossOrigin(): void
    {
        $transport = new SocketTransport(new FiberEventLoop());
        $options = (new RequestOptions())->withBearerToken('secret')->withBasicAuth('user', 'pass')->withSensitiveHeaders(['X-Api-Key']);
        $request = new Request('GET', 'https://a.example.com/x', new Headers([
            'Authorization' => 'Bearer manual',
            'Cookie' => 'sid=1',
            'X-Keep' => 'yes',
            'X-Api-Key' => 'secret-key',
        ]), null, [], [], null, $options);

        $redirected = $this->invokePrivate(
            $transport,
            'buildRedirectedRequest',
            $request,
            $options,
            'https://a.example.com/x',
            'https://b.example.com/y',
            302
        );

        self::assertNull($redirected->headers->get('Authorization'));
        self::assertNull($redirected->headers->get('Cookie'));
        self::assertSame('yes', $redirected->headers->get('X-Keep'));
        self::assertNull($redirected->headers->get('X-Api-Key'));
        self::assertNull($redirected->options?->bearerToken);
        self::assertNull($redirected->options?->basicAuth);
    }

    public function testBuildRedirectedRequestBlocksHttpsDowngradeByDefault(): void
    {
        $transport = new SocketTransport(new FiberEventLoop());
        $options = new RequestOptions();
        $request = new Request('GET', 'https://api.example.com/', new Headers(), null, [], [], null, $options);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Refusing HTTPS to HTTP redirect');
        $this->invokePrivate($transport, 'buildRedirectedRequest', $request, $options, 'https://api.example.com/', 'http://api.example.com/', 302);
    }

    public function testPoolKeySeparatesVerifiedAndUnverifiedTlsConnections(): void
    {
        $transport = new SocketTransport(new FiberEventLoop());
        $verified = $this->invokePrivate($transport, 'connectionPoolKey', 'https', 'api.example.com', 443, new RequestOptions());
        $unverified = $this->invokePrivate($transport, 'connectionPoolKey', 'https', 'api.example.com', 443, (new RequestOptions())->withVerifySSL(false));

        self::assertNotSame($verified, $unverified);
    }

    public function testBuildRedirectedRequestKeepsCredentialsOnSameOrigin(): void
    {
        $transport = new SocketTransport(new FiberEventLoop());
        $options = (new RequestOptions())->withBearerToken('secret');
        $request = new Request('GET', 'https://a.example.com/x', new Headers([
            'Authorization' => 'Bearer manual',
            'Cookie' => 'sid=1',
        ]), null, [], [], null, $options);

        $redirected = $this->invokePrivate(
            $transport,
            'buildRedirectedRequest',
            $request,
            $options,
            'https://a.example.com/x',
            'https://a.example.com/y',
            302
        );

        self::assertSame('Bearer manual', $redirected->headers->get('Authorization'));
        self::assertSame('sid=1', $redirected->headers->get('Cookie'));
        self::assertSame('secret', $redirected->options?->bearerToken);
    }

    public function testBuildRedirectedRequestStripsCredentialsWhenOnlyPortChanges(): void
    {
        $transport = new SocketTransport(new FiberEventLoop());
        $options = (new RequestOptions())->withBearerToken('secret');
        $request = new Request('GET', 'https://a.example.com:8443/x', new Headers([
            'Authorization' => 'Bearer manual',
        ]), null, [], [], null, $options);

        $redirected = $this->invokePrivate(
            $transport,
            'buildRedirectedRequest',
            $request,
            $options,
            'https://a.example.com:8443/x',
            'https://a.example.com:9443/y',
            302
        );

        self::assertNull($redirected->headers->get('Authorization'));
        self::assertNull($redirected->options?->bearerToken);
    }

    public function testExtractCookiesReadsSetCookieValues(): void
    {
        $headers = new Headers();
        $headers->add('Set-Cookie', [
            'a=1; Path=/',
            'b=2; HttpOnly',
            'invalid-cookie-line',
        ]);

        $cookies = (new CookieJar())->storeFromHeaders($headers, 'localhost');

        self::assertSame(['a' => '1', 'b' => '2'], $cookies);
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

        $transport = new SocketTransport(new FiberEventLoop());
        $prepared = $this->invokePrivate($transport, 'prepareRequest', $request, $options);

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

    public function testPrepareRequestIncludesNonDefaultPortInHostHeader(): void
    {
        $options = new RequestOptions();
        $request = new Request('GET', 'http://example.com:8080/items', new Headers(), null, [], [], null, $options);

        $transport = new SocketTransport(new FiberEventLoop());
        $prepared = $this->invokePrivate($transport, 'prepareRequest', $request, $options);

        self::assertSame('example.com:8080', $prepared['headers']->get('Host'));
    }

    public function testPrepareRequestOmitsDefaultPortInHostHeader(): void
    {
        $options = new RequestOptions();
        $request = new Request('GET', 'https://example.com:443/items', new Headers(), null, [], [], null, $options);

        $transport = new SocketTransport(new FiberEventLoop());
        $prepared = $this->invokePrivate($transport, 'prepareRequest', $request, $options);

        self::assertSame('example.com', $prepared['headers']->get('Host'));
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

        $transport = new SocketTransport(new FiberEventLoop());
        $prepared = $this->invokePrivate($transport, 'prepareRequest', $request, $options);

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

            $transport = new SocketTransport(new FiberEventLoop());
            $prepared = $this->invokePrivate($transport, 'prepareRequest', $request, $options);

            self::assertStringContainsString('multipart/form-data; boundary=', (string) $prepared['headers']->get('Content-Type'));
            self::assertIsResource($prepared['body']);
            rewind($prepared['body']);
            $body = stream_get_contents($prepared['body']);
            fclose($prepared['body']);
            self::assertIsString($body);

            self::assertStringContainsString('name="meta"', $body);
            self::assertStringContainsString('{"id":10}', $body);
            self::assertStringContainsString('name="file1"', $body);
            self::assertStringContainsString('demo-file-content', $body);
            self::assertSame((string) strlen($body), $prepared['headers']->get('Content-Length'));
        } finally {
            if (is_file($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    public function testPrepareRequestSendsAcceptEncodingOnlyWhenCompressionEnabled(): void
    {
        $transport = new SocketTransport(new FiberEventLoop());

        $default = new RequestOptions();
        $prepared = $this->invokePrivate(
            $transport,
            'prepareRequest',
            new Request('GET', 'https://example.com/x', new Headers(), null, [], [], null, $default),
            $default
        );
        self::assertFalse($prepared['headers']->has('Accept-Encoding'));

        $compressed = (new RequestOptions())->withCompression();
        $prepared = $this->invokePrivate(
            $transport,
            'prepareRequest',
            new Request('GET', 'https://example.com/x', new Headers(), null, [], [], null, $compressed),
            $compressed
        );
        self::assertSame('gzip', $prepared['headers']->get('Accept-Encoding'));
    }

    public function testPrepareRequestRespectsUserAcceptEncoding(): void
    {
        $options = (new RequestOptions())->withCompression();
        $request = new Request('GET', 'https://example.com/x', new Headers([
            'Accept-Encoding' => 'identity',
        ]), null, [], [], null, $options);

        $transport = new SocketTransport(new FiberEventLoop());
        $prepared = $this->invokePrivate($transport, 'prepareRequest', $request, $options);

        self::assertSame('identity', $prepared['headers']->get('Accept-Encoding'));
    }

    public function testBuildRequestPayloadFormatsStartLineHeadersAndBody(): void
    {
        $transport = new SocketTransport(new FiberEventLoop());
        $headers = new Headers([
            'Host' => 'example.com',
            'Content-Type' => 'application/json',
        ]);

        $payload = $this->invokePrivate(
            $transport,
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

        $transport = new SocketTransport(new FiberEventLoop());
        $options = (new RequestOptions())
            ->withReadTimeout(1.0)
            ->withTotalTimeout(1.0);

        [$status, $headers, $body] = $this->invokePrivate($transport, 'readResponse', $readSocket, $options);

        fclose($readSocket);

        self::assertSame(200, $status);
        self::assertSame('ok', $headers->get('X-Test'));
        self::assertSame('hello', $body);
    }

    public function testReadResponseSkipsInformationalResponseBeforeFinalResponse(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);
        [$readSocket, $writeSocket] = $sockets;
        fwrite($writeSocket, "HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok");
        fclose($writeSocket);

        $transport = new SocketTransport(new FiberEventLoop());
        [$status, , $body] = $this->invokePrivate($transport, 'readResponse', $readSocket, (new RequestOptions())->withReadTimeout(1.0)->withTotalTimeout(1.0));
        fclose($readSocket);

        self::assertSame(200, $status);
        self::assertSame('ok', $body);
    }

    public function testReadResponseRejectsAmbiguousContentLengthAndTransferEncoding(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);
        [$readSocket, $writeSocket] = $sockets;
        fwrite($writeSocket, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n");
        fclose($writeSocket);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Ambiguous response framing');
        try {
            $this->invokePrivate(new SocketTransport(new FiberEventLoop()), 'readResponse', $readSocket, new RequestOptions());
        } finally {
            fclose($readSocket);
        }
    }

    public function testReadResponseAcceptsIdenticalRepeatedContentLength(): void
    {
        $headers = new Headers();
        $headers->add('Content-Length', '2');
        $headers->add('Content-Length', '2');

        self::assertSame([2, false], $this->invokePrivate(new SocketTransport(new FiberEventLoop()), 'parseResponseFraming', $headers));
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

        $transport = new SocketTransport(new FiberEventLoop());
        $options = (new RequestOptions())
            ->withReadTimeout(1.0)
            ->withTotalTimeout(1.0);

        [$status, $headers, $body] = $this->invokePrivate($transport, 'readResponse', $readSocket, $options);

        fclose($readSocket);

        self::assertSame(200, $status);
        self::assertStringContainsString('chunked', strtolower((string) $headers->get('Transfer-Encoding')));
        self::assertSame('Wikipedia', $body);
    }

    public function testReadResponseParsesChunkedResponseWithExtensionsAndTrailers(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);

        [$readSocket, $writeSocket] = $sockets;

        fwrite(
            $writeSocket,
            "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n4;ext=1\r\nWiki\r\n5\r\npedia\r\n0\r\nX-Trailer: v\r\n\r\n"
        );
        fclose($writeSocket);

        $transport = new SocketTransport(new FiberEventLoop());
        $options = (new RequestOptions())
            ->withReadTimeout(1.0)
            ->withTotalTimeout(1.0);

        [$status, $headers, $body] = $this->invokePrivate($transport, 'readResponse', $readSocket, $options);

        fclose($readSocket);

        self::assertSame(200, $status);
        self::assertSame('Wikipedia', $body);
    }

    public function testReadResponseParsesFragmentedLargeBody(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);

        [$readSocket, $writeSocket] = $sockets;
        stream_set_chunk_size($readSocket, 1024);

        $expected = str_repeat('payload-', 12_800);
        $response = "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($expected) . "\r\nX-Large: yes\r\n\r\n" . $expected;
        self::assertSame(strlen($response), fwrite($writeSocket, $response));
        fclose($writeSocket);

        $transport = new SocketTransport(new FiberEventLoop());
        $options = (new RequestOptions())
            ->withReadTimeout(1.0)
            ->withTotalTimeout(2.0);

        [$status, $headers, $body] = $this->invokePrivate($transport, 'readResponse', $readSocket, $options);
        fclose($readSocket);

        self::assertSame(200, $status);
        self::assertSame('yes', $headers->get('X-Large'));
        self::assertSame($expected, $body);
    }

    public function testReadResponseRejectsIncompleteContentLengthBody(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);

        [$readSocket, $writeSocket] = $sockets;
        fwrite($writeSocket, "HTTP/1.1 200 OK\r\nContent-Length: 10\r\n\r\nshort");
        fclose($writeSocket);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Incomplete HTTP response body');

        $transport = new SocketTransport(new FiberEventLoop());
        $options = (new RequestOptions())
            ->withReadTimeout(1.0)
            ->withTotalTimeout(1.0);

        try {
            $this->invokePrivate($transport, 'readResponse', $readSocket, $options);
        } finally {
            fclose($readSocket);
        }
    }

    public function testReadResponseReturnsEmptyBodyForHeadWithContentLength(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);

        [$readSocket, $writeSocket] = $sockets;

        // HEAD: headers advertise a body length that never arrives. The peer
        // keeps the connection open, so only an immediate no-body return works.
        fwrite($writeSocket, "HTTP/1.1 200 OK\r\nContent-Length: 1234\r\nX-Test: ok\r\n\r\n");

        $transport = new SocketTransport(new FiberEventLoop());
        $options = (new RequestOptions())
            ->withReadTimeout(0.2)
            ->withTotalTimeout(1.0);

        [$status, $headers, $body] = $this->invokePrivate($transport, 'readResponse', $readSocket, $options, null, 'HEAD');

        fclose($writeSocket);
        fclose($readSocket);

        self::assertSame(200, $status);
        self::assertSame('ok', $headers->get('X-Test'));
        self::assertSame('', $body);
    }

    public function testReadResponseReturnsEmptyBodyForNoContentStatus(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);

        [$readSocket, $writeSocket] = $sockets;

        fwrite($writeSocket, "HTTP/1.1 204 No Content\r\nX-Test: ok\r\n\r\n");

        $transport = new SocketTransport(new FiberEventLoop());
        $options = (new RequestOptions())
            ->withReadTimeout(0.2)
            ->withTotalTimeout(1.0);

        [$status, $headers, $body] = $this->invokePrivate($transport, 'readResponse', $readSocket, $options);

        fclose($writeSocket);
        fclose($readSocket);

        self::assertSame(204, $status);
        self::assertSame('ok', $headers->get('X-Test'));
        self::assertSame('', $body);
    }

    public function testReadResponseIgnoresFramingHeadersOnNotModified(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);

        [$readSocket, $writeSocket] = $sockets;

        // 304 may carry Content-Length/Transfer-Encoding describing the GET entity.
        fwrite($writeSocket, "HTTP/1.1 304 Not Modified\r\nContent-Length: 1234\r\nTransfer-Encoding: chunked\r\n\r\n");

        $transport = new SocketTransport(new FiberEventLoop());
        $options = (new RequestOptions())
            ->withReadTimeout(0.2)
            ->withTotalTimeout(1.0);

        [$status, $headers, $body] = $this->invokePrivate($transport, 'readResponse', $readSocket, $options);

        fclose($writeSocket);
        fclose($readSocket);

        self::assertSame(304, $status);
        self::assertSame('', $body);
    }

    public function testReadResponseHeadReturnsEmptyBodyBufferForHead(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);

        [$readSocket, $writeSocket] = $sockets;

        fwrite($writeSocket, "HTTP/1.1 200 OK\r\nContent-Length: 1234\r\nX-Test: ok\r\n\r\n");

        $transport = new SocketTransport(new FiberEventLoop());
        $options = (new RequestOptions())
            ->withReadTimeout(0.2)
            ->withTotalTimeout(1.0);

        [$status, $headers, $bodyBuffer, $contentLength, $chunked] = $this->invokePrivate(
            $transport,
            'readResponseHead',
            $readSocket,
            $options,
            null,
            'HEAD'
        );

        fclose($writeSocket);
        fclose($readSocket);

        self::assertSame(200, $status);
        self::assertSame('', $bodyBuffer);
        self::assertSame(0, $contentLength);
        self::assertFalse($chunked);
    }

    public function testReadResponseDecodesGzipBody(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);

        [$readSocket, $writeSocket] = $sockets;

        $payload = gzencode('{"ok":true}');
        self::assertIsString($payload);
        fwrite($writeSocket, 'HTTP/1.1 200 OK' . "\r\nContent-Encoding: gzip\r\nContent-Length: " . strlen($payload) . "\r\n\r\n" . $payload);
        fclose($writeSocket);

        $transport = new SocketTransport(new FiberEventLoop());
        $options = (new RequestOptions())
            ->withReadTimeout(1.0)
            ->withTotalTimeout(1.0);

        [$status, $headers, $body] = $this->invokePrivate($transport, 'readResponse', $readSocket, $options);

        fclose($readSocket);

        self::assertSame(200, $status);
        self::assertSame('{"ok":true}', $body);
    }

    public function testReadResponseRejectsUnknownContentEncoding(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);

        [$readSocket, $writeSocket] = $sockets;

        fwrite($writeSocket, "HTTP/1.1 200 OK\r\nContent-Encoding: br\r\nContent-Length: 3\r\n\r\nabc");
        fclose($writeSocket);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Unsupported Content-Encoding: br');

        $transport = new SocketTransport(new FiberEventLoop());
        $options = (new RequestOptions())
            ->withReadTimeout(1.0)
            ->withTotalTimeout(1.0);

        try {
            $this->invokePrivate($transport, 'readResponse', $readSocket, $options);
        } finally {
            fclose($readSocket);
        }
    }

    private function invokePrivate(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new ReflectionClass($object);
        $private = $reflection->getMethod($method);

        return $private->invoke($object, ...$args);
    }
}
