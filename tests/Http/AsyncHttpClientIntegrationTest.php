<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Http;

use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use Omegaalfa\HttpClient\Http\Exceptions\ConnectionException;
use Omegaalfa\HttpClient\Http\Exceptions\TimeoutException;
use Omegaalfa\HttpClient\Http\MultipartBuilder;
use Omegaalfa\HttpClient\Http\SseEvent;
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

    public function testPostRetriesAreNotAppliedForNonIdempotentMethods(): void
    {
        $client = (new AsyncHttpClient())
            ->withRetries(2)
            ->withRetryDelay(10)
            ->withJson();

        await($client->get(self::$server->baseUrl() . '/retry-reset'));

        $response = await($client->post(self::$server->baseUrl() . '/retry-500', ['kind' => 'create']));
        $counter = await($client->get(self::$server->baseUrl() . '/retry-counter'));
        $counterData = $counter->json();

        self::assertSame(500, $response->status());
        self::assertSame(1, $counterData['count'], 'POST should not be retried by default.');
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

    public function testStreamRequestReadsIncrementalChunks(): void
    {
        $client = new AsyncHttpClient();
        $stream = await($client->streamGet(self::$server->baseUrl() . '/stream'));

        $start = microtime(true);
        $first = $stream->readChunk(5);
        $firstElapsed = microtime(true) - $start;
        $second = $stream->readChunk();

        self::assertSame(200, $stream->status());
        self::assertSame('hello', $first);
        self::assertLessThan(0.2, $firstElapsed, 'Expected the first chunk before the delayed write completed.');
        self::assertSame('-world', $second);
        self::assertNull($stream->readChunk());
        self::assertTrue($stream->isComplete());
    }

    public function testStreamRedirectConsumesFinalTargetAndMarksRedirected(): void
    {
        $client = (new AsyncHttpClient())
            ->withFollowRedirects(3);

        $stream = await($client->streamGet(self::$server->baseUrl() . '/stream-redirect'));
        $body = $stream->consume();

        self::assertSame(200, $stream->status());
        self::assertSame('hello-world', $body);
    }

    public function testStreamTotalTimeoutIsSharedAcrossHeaderAndBody(): void
    {
        $client = (new AsyncHttpClient())
            ->totalTimeout(0.2)
            ->readTimeout(1.0);

        $stream = await($client->streamGet(self::$server->baseUrl() . '/stream-total-timeout'));
        self::assertSame('A', $stream->readChunk(1));

        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage('Total request timeout exceeded');

        $stream->readChunk(1);
    }

    public function testSseStreamParsesEventsAndDoneMarker(): void
    {
        $client = new AsyncHttpClient();
        $stream = await($client->streamSseGet(
            self::$server->baseUrl() . '/stream-sse',
            requireDone: true,
            completionDetector: AsyncHttpClient::doneMarkerCompletionDetector()
        ));

        $first = $stream->nextEvent();
        $second = $stream->nextEvent();
        $done = $stream->nextEvent();

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNotNull($done);
        self::assertSame(['message' => 'one'], $first->json());
        self::assertSame(['message' => 'two'], $second->json());
        self::assertFalse($first->done());
        self::assertFalse($second->done());
        self::assertTrue($done->done());
        self::assertNull($stream->nextEvent());
    }

    public function testSsePostSupportsOptInDoneDetector(): void
    {
        $client = new AsyncHttpClient();
        $stream = await($client->streamSsePost(
            self::$server->baseUrl() . '/stream-sse',
            body: ['source' => 'post'],
            requireDone: true,
            completionDetector: AsyncHttpClient::doneMarkerCompletionDetector()
        ));

        $first = $stream->nextEvent();
        $second = $stream->nextEvent();
        $done = $stream->nextEvent();

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNotNull($done);
        self::assertSame(['message' => 'one'], $first->json());
        self::assertSame(['message' => 'two'], $second->json());
        self::assertTrue($done->done());
        self::assertNull($stream->nextEvent());
    }

    public function testSseStreamCanBeSafelyCancelledWithForeachAndFinally(): void
    {
        $client = new AsyncHttpClient();
        $stream = await($client->streamSsePost(
            self::$server->baseUrl() . '/stream-sse',
            body: ['source' => 'cancel']
        ));

        try {
            foreach ($stream as $event) {
                self::assertNotSame('', $event->data());
                break;
            }
        } finally {
            $stream->close();
        }

        self::assertNull($stream->nextEvent());
    }

    public function testSseStreamSupportsProviderSpecificCompletionDetector(): void
    {
        $client = new AsyncHttpClient();
        $stream = await($client->streamSseGet(
            self::$server->baseUrl() . '/stream-sse-json-done',
            requireDone: true,
            completionDetector: static function (SseEvent $event): bool {
                $payload = $event->json();
                return ($payload['done'] ?? false) === true;
            }
        ));

        $first = $stream->nextEvent();
        $done = $stream->nextEvent();

        self::assertNotNull($first);
        self::assertNotNull($done);
        self::assertSame(['message' => 'one'], $first->json());
        self::assertSame(['done' => true, 'provider' => 'custom'], $done->json());
        self::assertTrue($done->done());
        self::assertNull($stream->nextEvent());
    }

    public function testStreamReadFailureAfterFirstChunkIsNotRetried(): void
    {
        $client = (new AsyncHttpClient())
            ->withRetries(2)
            ->withRetryDelay(10);

        await($client->get(self::$server->baseUrl() . '/stream-mid-reset'));

        $stream = await($client->streamGet(self::$server->baseUrl() . '/stream-mid-fail'));
        self::assertSame('hello', $stream->readChunk(5));

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Incomplete streamed response body');

        try {
            $stream->readChunk();
        } finally {
            $counter = await($client->get(self::$server->baseUrl() . '/stream-mid-counter'));
            self::assertSame(1, $counter->json()['count'], 'Streaming must not retry after bytes are delivered.');
        }
    }
}
