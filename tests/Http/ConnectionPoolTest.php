<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Http;

use Omegaalfa\HttpClient\Http\ConnectionPool;
use PHPUnit\Framework\TestCase;

final class ConnectionPoolTest extends TestCase
{
    public function testAcquireReturnsNullWhenPoolIsEmpty(): void
    {
        $pool = new ConnectionPool();
        self::assertNull($pool->acquire('tcp', 'example.com', 80));
    }

    public function testReleaseClosesSocketWhenKeepAliveIsFalse(): void
    {
        $pool = new ConnectionPool();

        $left = fopen('php://temp', 'r+');
        self::assertIsResource($left);
        $pool->release($left, false);
        self::assertFalse(is_resource($left));
    }

    public function testReleaseWithKeepAliveStoresSocketForReuse(): void
    {
        $pool = new ConnectionPool();

        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($server);

        $name = stream_socket_get_name($server, false);
        self::assertIsString($name);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $client = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 1);
        self::assertIsResource($client);
        $peer = stream_socket_accept($server, 1);
        self::assertIsResource($peer);

        $pool->release($client, true);
        $reused = $pool->acquire('tcp', '127.0.0.1', $port);
        self::assertIsResource($reused);
        self::assertSame($client, $reused);

        fclose($peer);
        fclose($server);

        if (is_resource($reused)) {
            fclose($reused);
        }
    }

    public function testPoolIgnoresClosedSocketsOnAcquire(): void
    {
        $pool = new ConnectionPool();

        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($server);

        $name = stream_socket_get_name($server, false);
        self::assertIsString($name);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $client = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 1);
        self::assertIsResource($client);
        $peer = stream_socket_accept($server, 1);
        self::assertIsResource($peer);

        $pool->release($client, true);
        fclose($peer);

        $reused = $pool->acquire('tcp', '127.0.0.1', $port);
        self::assertNull($reused);

        fclose($server);
    }
}
