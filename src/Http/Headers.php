<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Http;

use Countable;
use IteratorAggregate;
use Traversable;

final class Headers implements Countable, IteratorAggregate
{
    /**
     * @var array<string, array{name: string, values: list<string>}>
     */
    private array $items = [];

    public function __construct(iterable $headers = [])
    {
        foreach ($headers as $name => $value) {
            if (is_int($name) || !is_string($name)) {
                continue;
            }

            $this->set($name, $value);
        }
    }

    public static function from(self|iterable $headers): self
    {
        if ($headers instanceof self) {
            return clone $headers;
        }

        return new self($headers);
    }

    public static function fromRaw(string $rawHeaders): self
    {
        $headers = new self();
        $lines = preg_split('/\r\n|\n|\r/', trim($rawHeaders)) ?: [];

        foreach ($lines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $headers->add(trim($name), trim($value));
        }

        return $headers;
    }

    public function set(string $name, string|array $value): self
    {
        $normalized = $this->normalize($name);
        $values = array_map(static fn (mixed $item): string => (string) $item, (array) $value);
        $this->items[$normalized] = [
            'name' => $name,
            'values' => array_values($values),
        ];

        return $this;
    }

    public function add(string $name, string|array $value): self
    {
        $normalized = $this->normalize($name);
        $values = array_map(static fn (mixed $item): string => (string) $item, (array) $value);

        if (!isset($this->items[$normalized])) {
            $this->items[$normalized] = [
                'name' => $name,
                'values' => [],
            ];
        }

        foreach ($values as $item) {
            $this->items[$normalized]['values'][] = $item;
        }

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->items[$this->normalize($name)]);
    }

    public function get(string $name, ?string $default = null): ?string
    {
        $normalized = $this->normalize($name);
        if (!isset($this->items[$normalized])) {
            return $default;
        }

        return implode(', ', $this->items[$normalized]['values']);
    }

    /**
     * @return list<string>
     */
    public function values(string $name): array
    {
        $normalized = $this->normalize($name);
        return $this->items[$normalized]['values'] ?? [];
    }

    public function remove(string $name): self
    {
        unset($this->items[$this->normalize($name)]);
        return $this;
    }

    public function merge(self|iterable $headers): self
    {
        $merged = clone $this;
        $incoming = self::from($headers);

        foreach ($incoming->items as $normalized => $item) {
            $merged->items[$normalized] = $item;
        }

        return $merged;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $headers = [];
        foreach ($this->items as $item) {
            $headers[$item['name']] = implode(', ', $item['values']);
        }

        return $headers;
    }

    /**
     * @return list<string>
     */
    public function toLines(): array
    {
        $lines = [];
        foreach ($this->items as $item) {
            foreach ($item['values'] as $value) {
                $lines[] = $item['name'] . ': ' . $value;
            }
        }

        return $lines;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): Traversable
    {
        yield from $this->toArray();
    }

    private function normalize(string $name): string
    {
        return strtolower(trim($name));
    }
}