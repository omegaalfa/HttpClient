<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Http;

use Omegaalfa\HttpClient\Http\SseEvent;

final class SseParser
{
    private const MAX_LINE_BYTES = 65_536;

    private string $buffer = '';

    /**
     * @var array{data: list<string>, event: ?string, id: ?string, retry: ?int}
     */
    private array $current = [
        'data' => [],
        'event' => null,
        'id' => null,
        'retry' => null,
    ];

    /**
     * @return list<SseEvent>
     */
    public function push(string $chunk): array
    {
        $this->buffer .= $chunk;
        $events = [];

        while (true) {
            $lineEnd = strpos($this->buffer, "\n");
            if ($lineEnd === false) {
                if (strlen($this->buffer) > self::MAX_LINE_BYTES) {
                    throw new \RuntimeException('SSE line exceeds the configured size limit');
                }
                break;
            }

            if ($lineEnd > self::MAX_LINE_BYTES) {
                throw new \RuntimeException('SSE line exceeds the configured size limit');
            }

            $line = substr($this->buffer, 0, $lineEnd);
            $this->buffer = substr($this->buffer, $lineEnd + 1);
            $line = rtrim($line, "\r");

            if ($line === '') {
                $event = $this->dispatchCurrentEvent();
                if ($event !== null) {
                    $events[] = $event;
                }

                continue;
            }

            if ($line[0] === ':') {
                continue;
            }

            $field = $line;
            $value = '';
            if (str_contains($line, ':')) {
                [$field, $value] = explode(':', $line, 2);
                $value = ltrim($value, ' ');
            }

            match ($field) {
                'data' => $this->current['data'][] = $value,
                'event' => $this->current['event'] = $value,
                'id' => $this->current['id'] = $this->parseId($value),
                'retry' => $this->current['retry'] = $this->parseRetry($value),
                default => null,
            };
        }

        return $events;
    }

    /**
     * @return list<SseEvent>
     */
    public function finish(): array
    {
        // The SSE format dispatches only events terminated by a blank line.
        // Discard an unfinished trailing line/event at EOF.
        $this->buffer = '';
        $this->current['data'] = [];
        $this->current['event'] = null;
        return [];
    }

    private function dispatchCurrentEvent(): ?SseEvent
    {
        if ($this->current['data'] === []) {
            $this->current['event'] = null;
            return null;
        }

        $data = implode("\n", $this->current['data']);
        $event = new SseEvent(
            $data,
            $this->current['event'],
            $this->current['id'],
            $this->current['retry']
        );

        $this->current = [
            'data' => [],
            'event' => null,
            'id' => $this->current['id'],
            'retry' => $this->current['retry'],
        ];

        return $event;
    }

    private function parseId(string $value): ?string
    {
        return str_contains($value, "\0") ? $this->current['id'] : $value;
    }

    private function parseRetry(string $value): ?int
    {
        return ctype_digit($value) ? (int) $value : $this->current['retry'];
    }
}
