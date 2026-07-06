<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Http;

use Omegaalfa\HttpClient\Http\Headers;
use Omegaalfa\HttpClient\Http\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ResponseTest extends TestCase
{
    public function testBasicAccessors(): void
    {
        $headers = new Headers(['Content-Type' => 'application/json']);
        $response = new Response(200, $headers, '{"ok":true}', ['sid' => 'abc'], false);

        self::assertSame(200, $response->status());
        self::assertSame('{"ok":true}', $response->body());
        self::assertSame('{"ok":true}', $response->text());
        self::assertSame(['sid' => 'abc'], $response->cookies());
    }

    public function testHeadersReturnsClone(): void
    {
        $response = new Response(200, new Headers(['X-A' => '1']), 'ok');
        $headers = $response->headers();
        $headers->set('X-A', '2');

        self::assertSame('1', $response->headers()->get('X-A'));
    }

    public function testJsonDecoding(): void
    {
        $response = new Response(200, new Headers(), '{"id":10,"name":"omega"}');
        $assoc = $response->json(true);
        $object = $response->json(false);

        self::assertSame(10, $assoc['id']);
        self::assertSame('omega', $object->name);
    }

    public function testJsonDecodingUsesCacheForObjectMode(): void
    {
        $response = new Response(200, new Headers(), '{"id":10}');

        $first = $response->json(false);
        $second = $response->json(false);

        self::assertSame($first, $second);
    }

    public function testSuccessAndFailureHelpers(): void
    {
        $ok = new Response(201, new Headers(), 'ok');
        $fail = new Response(404, new Headers(), 'not found');

        self::assertTrue($ok->successful());
        self::assertFalse($ok->failed());
        self::assertFalse($fail->successful());
        self::assertTrue($fail->failed());
    }

    public function testRedirectedFlag(): void
    {
        $redirected = new Response(200, new Headers(), 'ok', [], true);
        $normal = new Response(200, new Headers(), 'ok', [], false);

        self::assertTrue($redirected->redirected());
        self::assertFalse($normal->redirected());
    }

    public function testSaveWritesBodyToFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'response-test-');
        self::assertNotFalse($tmpFile);

        try {
            $response = new Response(200, new Headers(), 'saved-content');
            $response->save($tmpFile);

            self::assertSame('saved-content', file_get_contents($tmpFile));
        } finally {
            if (is_file($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    public function testSaveThrowsRuntimeExceptionWhenPathIsInvalid(): void
    {
        $response = new Response(200, new Headers(), 'body');
        $this->expectException(RuntimeException::class);

        $response->save(sys_get_temp_dir());
    }
}
