<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Http;

use Omegaalfa\HttpClient\Http\Headers;
use Omegaalfa\HttpClient\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testWithUrlReturnsNewRequestAndClonesHeaders(): void
    {
        $request = new Request('GET', 'https://example.com/a', new Headers(['X-A' => '1']), null, ['q' => 'x']);
        $changed = $request->withUrl('https://example.com/b');

        self::assertNotSame($request, $changed);
        self::assertSame('https://example.com/a', $request->url);
        self::assertSame('https://example.com/b', $changed->url);
        self::assertNotSame($request->headers, $changed->headers);
        self::assertSame('1', $changed->headers->get('X-A'));
    }
}
