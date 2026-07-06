<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Http\Exceptions;

use Omegaalfa\HttpClient\Http\Exceptions\HttpException;
use Omegaalfa\HttpClient\Http\Headers;
use Omegaalfa\HttpClient\Http\Request;
use Omegaalfa\HttpClient\Http\Response;
use PHPUnit\Framework\TestCase;

final class HttpExceptionTest extends TestCase
{
    public function testRequestAndResponseAccessors(): void
    {
        $request = new Request('GET', 'https://example.com', new Headers());
        $response = new Response(500, new Headers(), 'error');

        $exception = new HttpException('boom', $request, $response, 123);

        self::assertSame('boom', $exception->getMessage());
        self::assertSame(123, $exception->getCode());
        self::assertSame($request, $exception->request());
        self::assertSame($response, $exception->response());
    }
}
