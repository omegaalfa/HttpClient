<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Http;

use Omegaalfa\HttpClient\Http\SseEvent;
use RuntimeException;

final class SseParser
{
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
                break;
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
                'id' => $this->current['id'] = $value,
                'retry' => $this->current['retry'] = is_numeric($value) ? (int) $value : null,
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
        if ($this->buffer !== '' || $this->current['data'] !== [] || $this->current['event'] !== null || $this->current['id'] !== null || $this->current['retry'] !== null) {
            throw new RuntimeException('SSE stream ended before a complete event was received');
        }

        return [];
    }

    private function dispatchCurrentEvent(): ?SseEvent
    {
        if ($this->current['data'] === [] && $this->current['event'] === null && $this->current['id'] === null && $this->current['retry'] === null) {
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
            'id' => null,
            'retry' => null,
        ];

        return $event;
    }
}