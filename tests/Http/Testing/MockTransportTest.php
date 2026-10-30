<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Http\Testing;

use Omegaalfa\FiberEventLoop\FiberEventLoop;
use Omegaalfa\HttpClient\Http\Headers;
use Omegaalfa\HttpClient\Http\Request;
use Omegaalfa\HttpClient\Http\RequestOptions;
use Omegaalfa\HttpClient\Http\Response;
use Omegaalfa\HttpClient\Http\StreamResponse;
use Omegaalfa\HttpClient\Http\Testing\MockTransport;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Omegaalfa\HttpClient\Http\await;

final class MockTransportTest extends TestCase
{
    public function testSendReturnsQueuedResponsesInOrderAndRecordsRequests(): void
    {
        $transport = new MockTransport([
            new Response(201, new Headers(), 'one'),
            new Response(200, new Headers(), 'two'),
        ]);

        $options = new RequestOptions();
        $first = $transport->send(new Request('GET', 'https://a.example.com/1', new Headers()), $options);
        $second = $transport->send(new Request('POST', 'https://a.example.com/2', new Headers()), $options);

        self::assertSame(201, $first->status());
        self::assertSame('two', $second->body());
        self::assertCount(2, $transport->requests());
        self::assertSame('GET', $transport->requests()[0]->method);
        self::assertSame('https://a.example.com/2', $transport->lastRequest()?->url);
        self::assertSame(0, $transport->pendingCount());
    }

    public function testCallableReceivesRequestAndOptions(): void
    {
        $transport = new MockTransport([
            static function (Request $request, RequestOptions $options): Response {
                return new Response(200, new Headers(), $request->method . ':' . $options->userAgent);
            },
        ]);

        $options = new RequestOptions();
        $response = $transport->send(new Request('PATCH', 'https://a.example.com/x', new Headers()), $options);

        self::assertSame('PATCH:' . $options->userAgent, $response->body());
    }

    public function testQueuedThrowableIsThrown(): void
    {
        $transport = new MockTransport([
            new RuntimeException('simulated failure'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('simulated failure');

        $transport->send(new Request('GET', 'https://a.example.com/x', new Headers()), new RequestOptions());
    }

    public function testExhaustedQueueThrows(): void
    {
        $transport = new MockTransport();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no more queued responses');

        $transport->send(new Request('GET', 'https://a.example.com/x', new Headers()), new RequestOptions());
    }

    public function testStreamHelperBuildsReadableStreamResponse(): void
    {
        $stream = MockTransport::stream('hello-world', 200, ['X-Test' => 'ok']);

        self::assertInstanceOf(StreamResponse::class, $stream);
        self::assertSame(200, $stream->status());
        self::assertSame('ok', $stream->headers()->get('X-Test'));
        self::assertSame('hello-world', $stream->consume());
        self::assertTrue($stream->isComplete());
    }

    public function testSendStreamReturnsQueuedStreamResponse(): void
    {
        $transport = new MockTransport([
            MockTransport::stream('chunked-body'),
        ]);

        $stream = $transport->sendStream(new Request('GET', 'https://a.example.com/x', new Headers()), new RequestOptions());

        self::assertSame('chunked-body', $stream->consume());
    }

    public function testSendRejectsStreamResponse(): void
    {
        $transport = new MockTransport([
            MockTransport::stream('x'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('send() requer uma Response');

        $transport->send(new Request('GET', 'https://a.example.com/x', new Headers()), new RequestOptions());
    }

    public function testSendStreamRejectsMaterializedResponse(): void
    {
        $transport = new MockTransport([
            new Response(200, new Headers(), 'x'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sendStream() requer uma StreamResponse');

        $transport->sendStream(new Request('GET', 'https://a.example.com/x', new Headers()), new RequestOptions());
    }

    public function testMockedClientWorksWithoutEventLoopJuggling(): void
    {
        $loop = new FiberEventLoop();
        $transport = new MockTransport(
            [new Response(200, new Headers(['Content-Type' => 'application/json']), '{"ok":true}')]
        );

        $request = new Request('GET', 'https://a.example.com/x', new Headers());
        $future = $loop->async(static fn () => $transport->send($request, new RequestOptions()));

        self::assertSame(['ok' => true], await($future)->json());
    }
}
