<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Http;

use Closure;
use IteratorAggregate;
use Omegaalfa\HttpClient\Http\SseEvent;
use Omegaalfa\HttpClient\Http\SseParser;
use Omegaalfa\HttpClient\Http\StreamResponse;
use RuntimeException;
use Traversable;

final class SseStream implements IteratorAggregate
{
    /**
     * @var list<SseEvent>
     */
    private array $buffer = [];

    private bool $doneSeen = false;

    private bool $closed = false;

    private ?Closure $completionDetector;

    public function __construct(
        private readonly StreamResponse $stream,
        private readonly SseParser $parser = new SseParser(),
        private readonly bool $requireDone = true,
        ?callable $completionDetector = null
    ) {
        $this->completionDetector = $completionDetector !== null
            ? Closure::fromCallable($completionDetector)
            : null;

        if ($this->requireDone && $this->completionDetector === null) {
            throw new RuntimeException('A completion detector is required when requireDone is true');
        }
    }

    public function nextEvent(): ?SseEvent
    {
        if ($this->buffer !== []) {
            return array_shift($this->buffer);
        }

        if ($this->closed) {
            return null;
        }

        while (true) {
            $chunk = $this->stream->readChunk();

            if ($chunk === null) {
                $events = $this->parser->finish();
                if ($events !== []) {
                    $this->buffer = array_values($events);
                }

                if ($this->requireDone && !$this->doneSeen) {
                    throw new RuntimeException('SSE stream ended before a completion event was received');
                }

                $this->close();
                return $this->buffer !== [] ? array_shift($this->buffer) : null;
            }

            $events = $this->parser->push($chunk);
            foreach ($events as $event) {
                if ($this->isCompletionEvent($event)) {
                    $this->doneSeen = true;
                    $event = new SseEvent(
                        $event->data(),
                        $event->event(),
                        $event->id(),
                        $event->retry(),
                        true
                    );
                }

                $this->buffer[] = $event;
            }

            if ($this->buffer !== []) {
                return array_shift($this->buffer);
            }
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->stream->close();
    }

    public function __destruct()
    {
        $this->close();
    }

    public function stream(): StreamResponse
    {
        return $this->stream;
    }

    /**
     * @return Traversable<int, SseEvent>
     */
    public function getIterator(): Traversable
    {
        try {
            while (($event = $this->nextEvent()) !== null) {
                yield $event;
            }
        } finally {
            // Breaking out of foreach should still close the underlying stream.
            $this->close();
        }
    }

    private function isCompletionEvent(SseEvent $event): bool
    {
        if ($this->completionDetector === null) {
            return false;
        }

        return (bool) ($this->completionDetector)($event);
    }
}