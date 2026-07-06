<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Http;

use Omegaalfa\HttpClient\Http\Headers;
use PHPUnit\Framework\TestCase;

final class HeadersTest extends TestCase
{
    public function testConstructSkipsInvalidKeys(): void
    {
        $headers = new Headers([
            'Accept' => 'application/json',
            10 => 'invalid',
        ]);

        self::assertCount(1, $headers);
        self::assertTrue($headers->has('accept'));
    }

    public function testSetGetAndHasAreCaseInsensitive(): void
    {
        $headers = new Headers();
        $headers->set('Content-Type', 'application/json');

        self::assertTrue($headers->has('content-type'));
        self::assertSame('application/json', $headers->get('CONTENT-TYPE'));
    }

    public function testAddAppendsValues(): void
    {
        $headers = new Headers();
        $headers->add('Set-Cookie', 'a=1');
        $headers->add('Set-Cookie', ['b=2', 'c=3']);

        self::assertSame(['a=1', 'b=2', 'c=3'], $headers->values('set-cookie'));
        self::assertSame('a=1, b=2, c=3', $headers->get('Set-Cookie'));
    }

    public function testGetReturnsDefaultWhenMissing(): void
    {
        $headers = new Headers();
        self::assertSame('fallback', $headers->get('x-missing', 'fallback'));
    }

    public function testRemoveDeletesHeader(): void
    {
        $headers = new Headers(['X-Trace' => 'abc']);
        $headers->remove('x-trace');

        self::assertFalse($headers->has('X-Trace'));
    }

    public function testMergeReturnsNewInstanceWithoutMutatingOriginal(): void
    {
        $base = new Headers(['Accept' => 'application/json']);
        $merged = $base->merge(['Accept' => 'text/plain', 'X-Token' => '123']);

        self::assertSame('application/json', $base->get('Accept'));
        self::assertSame('text/plain', $merged->get('Accept'));
        self::assertSame('123', $merged->get('X-Token'));
    }

    public function testFromWithHeadersInstanceCreatesClone(): void
    {
        $original = new Headers(['X-Test' => 'one']);
        $clone = Headers::from($original);
        $clone->set('X-Test', 'two');

        self::assertSame('one', $original->get('X-Test'));
        self::assertSame('two', $clone->get('X-Test'));
    }

    public function testFromRawParsesRawHeaderBlock(): void
    {
        $raw = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nSet-Cookie: a=1\r\nSet-Cookie: b=2\r\n";
        $headers = Headers::fromRaw($raw);

        self::assertSame('application/json', $headers->get('Content-Type'));
        self::assertSame(['a=1', 'b=2'], $headers->values('set-cookie'));
    }

    public function testToArrayAndToLinesReturnExpectedFormat(): void
    {
        $headers = new Headers([
            'Accept' => 'application/json',
        ]);
        $headers->add('Set-Cookie', 'a=1');
        $headers->add('Set-Cookie', 'b=2');

        self::assertSame(
            [
                'Accept' => 'application/json',
                'Set-Cookie' => 'a=1, b=2',
            ],
            $headers->toArray()
        );

        self::assertSame(
            [
                'Accept: application/json',
                'Set-Cookie: a=1',
                'Set-Cookie: b=2',
            ],
            $headers->toLines()
        );
    }

    public function testIteratorYieldsArrayRepresentation(): void
    {
        $headers = new Headers(['X-A' => '1', 'X-B' => '2']);
        $iterated = [];
        foreach ($headers as $name => $value) {
            $iterated[$name] = $value;
        }

        self::assertSame(['X-A' => '1', 'X-B' => '2'], $iterated);
    }
}
