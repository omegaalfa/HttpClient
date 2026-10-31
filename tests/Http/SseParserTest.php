<?php

declare(strict_types=1);

namespace Tests\Omegaalfa\HttpClient\Http;

use Omegaalfa\HttpClient\Http\SseEvent;
use Omegaalfa\HttpClient\Http\SseParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SseParserTest extends TestCase
{
    public function testPushParsesSplitEventsAndMultilineData(): void
    {
        $parser = new SseParser();

        $this->assertSame([], $parser->push(": comment\ndata: first line\n"));
        $events = $parser->push("data: second line\n\n");

        self::assertCount(1, $events);
        self::assertSame("first line\nsecond line", $events[0]->data());
        self::assertFalse($events[0]->done());
    }

    public function testPushTreatsDoneMarkerAsRegularData(): void
    {
        $parser = new SseParser();
        $events = $parser->push(": keep-alive\ndata: [DONE]\n\n");

        self::assertCount(1, $events);
        self::assertFalse($events[0]->done());
        self::assertSame('[DONE]', $events[0]->data());
    }

    public function testFinishDiscardsAnIncompleteTrailingEvent(): void
    {
        $parser = new SseParser();
        $parser->push("data: partial");

        self::assertSame([], $parser->finish());
    }

    public function testIdOnlyBlockDoesNotDispatchAndIdPersistsAcrossEvents(): void
    {
        $parser = new SseParser();

        self::assertSame([], $parser->push("id: cursor-1\n\n"));
        $events = $parser->push("data: first\n\n");
        self::assertCount(1, $events);
        self::assertSame('cursor-1', $events[0]->id());

        $next = $parser->push("data: second\n\n");
        self::assertCount(1, $next);
        self::assertSame('cursor-1', $next[0]->id());
    }

    public function testRejectsAnOversizedUnterminatedLine(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SSE line exceeds the configured size limit');

        (new SseParser())->push(str_repeat('x', 65_537));
    }

    public function testJsonDecodingReportsContextOnFailure(): void
    {
        $event = new SseEvent('{"invalid":');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid SSE JSON payload');

        $event->json();
    }
}
