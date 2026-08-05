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
        $ensureTimeout->setAccessible(true);

        $this->expectException(\Omegaalfa\HttpClient\Http\Exceptions\TimeoutException::class);
        $ensureTimeout->invoke($first);

        self::assertSame('ok', $second->readChunk(2));
        self::assertNull($second->readChunk());
    }
}
