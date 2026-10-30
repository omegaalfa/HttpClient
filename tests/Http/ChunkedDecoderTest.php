<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Http;

use Omegaalfa\HttpClient\Http\ChunkedDecoder;
use Omegaalfa\HttpClient\Http\Exceptions\ConnectionException;
use PHPUnit\Framework\TestCase;

final class ChunkedDecoderTest extends TestCase
{
    public function testDecodesMultiChunkPayload(): void
    {
        $decoder = new ChunkedDecoder();
        $decoder->push("4\r\nWiki\r\n5\r\npedia\r\n0\r\n\r\n");

        self::assertSame('Wikipedia', $decoder->takeDecoded());
        self::assertTrue($decoder->isCompleted());
        $decoder->finish();
    }

    public function testDecodesAcrossFragmentedPushes(): void
    {
        $decoder = new ChunkedDecoder();
        $decoder->push("4\r\nWi");

        self::assertSame('', $decoder->takeDecoded());

        $decoder->push("ki\r\n5\r\npedia\r\n0\r\n\r\n");

        self::assertSame('Wikipedia', $decoder->takeDecoded());
        self::assertTrue($decoder->isCompleted());
    }

    public function testSupportsChunkExtensions(): void
    {
        $decoder = new ChunkedDecoder();
        $decoder->push("4;name=value\r\nWiki\r\n0\r\n\r\n");

        self::assertSame('Wiki', $decoder->takeDecoded());
        self::assertTrue($decoder->isCompleted());
    }

    public function testToleratesTrailers(): void
    {
        $decoder = new ChunkedDecoder();
        $decoder->push("4\r\nWiki\r\n0\r\nX-Trailer: v\r\n\r\n");

        self::assertSame('Wiki', $decoder->takeDecoded());
        self::assertTrue($decoder->isCompleted());
    }

    public function testRejectsInvalidChunkLength(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Invalid chunked response length');

        (new ChunkedDecoder())->push("zz\r\nWiki\r\n0\r\n\r\n");
    }

    public function testRejectsInvalidChunkFraming(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Invalid chunked response framing');

        (new ChunkedDecoder())->push("4\r\nWikiXX\r\n0\r\n\r\n");
    }

    public function testFinishThrowsOnIncompleteBody(): void
    {
        $decoder = new ChunkedDecoder();
        $decoder->push("4\r\nWiki\r\n");

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Incomplete chunked response body');

        $decoder->finish();
    }
}
