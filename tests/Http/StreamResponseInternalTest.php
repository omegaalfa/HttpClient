<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Http;

use Omegaalfa\FiberEventLoop\FiberEventLoop;
use Omegaalfa\HttpClient\Http\ConnectionPool;
use Omegaalfa\HttpClient\Http\Headers;
use Omegaalfa\HttpClient\Http\RequestOptions;
use Omegaalfa\HttpClient\Http\StreamResponse;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class StreamResponseInternalTest extends TestCase
{
    public function testTotalDeadlineIsPerInstance(): void
    {
        $loop = new FiberEventLoop();
        $pool = new ConnectionPool();
        $headers = new Headers();

        $firstSocketPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($firstSocketPair);
        [$firstRead, $firstWrite] = $firstSocketPair;

        $secondSocketPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($secondSocketPair);
        [$secondRead, $secondWrite] = $secondSocketPair;

        fclose($firstWrite);
        fwrite($secondWrite, 'ok');
        fclose($secondWrite);

        $first = new StreamResponse(
            $loop,
            $pool,
            $firstRead,
            (new RequestOptions())->withTotalTimeout(0.01)->withReadTimeout(0.01),
            'k1',
            false,
            200,
            $headers
        );

        usleep(20_000);

        $second = new StreamResponse(
            $loop,
            $pool,
            $secondRead,
            (new RequestOptions())->withTotalTimeout(1.0)->withReadTimeout(1.0),
            'k2',
            false,
            200,
            $headers,
            [],
            false,
            'ok',
            false,
            2
        );

        $reflection = new ReflectionClass($first);
        $ensureTimeout = $reflection->getMethod('ensureTotalTimeout');

        $this->expectException(\Omegaalfa\HttpClient\Http\Exceptions\TimeoutException::class);
        $ensureTimeout->invoke($first);

        self::assertSame('ok', $second->readChunk(2));
        self::assertNull($second->readChunk());
    }

    public function testNoBodyStreamCompletesWithoutPeerClose(): void
    {
        $loop = new FiberEventLoop();
        $pool = new ConnectionPool();
        $headers = new Headers();

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);
        [$readSocket, $writeSocket] = $sockets;

        // HEAD/204/304 streams carry zero bytes; completion must not depend on EOF.
        $stream = new StreamResponse(
            $loop,
            $pool,
            $readSocket,
            (new RequestOptions())->withReadTimeout(0.2)->withTotalTimeout(1.0),
            'k',
            false,
            204,
            $headers,
            [],
            false,
            '',
            false,
            0
        );

        self::assertNull($stream->readChunk());
        self::assertTrue($stream->isComplete());

        fclose($writeSocket);
    }

    public function testChunkedStreamDecodesIncrementally(): void
    {
        $loop = new FiberEventLoop();
        $pool = new ConnectionPool();

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);
        [$readSocket, $writeSocket] = $sockets;

        fwrite($writeSocket, "6\r\n-world\r\n0\r\n\r\n");

        $stream = new StreamResponse(
            $loop,
            $pool,
            $readSocket,
            (new RequestOptions())->withReadTimeout(1.0)->withTotalTimeout(2.0),
            'k',
            false,
            200,
            new Headers(),
            [],
            false,
            "5\r\nhello\r\n",
            true,
            null
        );

        self::assertSame('hello', $stream->readChunk(5));
        self::assertSame('-world', $stream->readChunk());
        self::assertNull($stream->readChunk());
        self::assertTrue($stream->isComplete());

        fclose($writeSocket);
    }
}
