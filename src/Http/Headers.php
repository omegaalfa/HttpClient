<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Http;

use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<string, string> */
final class Headers implements Countable, IteratorAggregate
{
    /**
     * @var array<string, array{name: string, values: list<string>}>
     */
    private array $items = [];

    /** @param iterable<array-key, string|list<string>> $headers */
    public function __construct(iterable $headers = [])
    {
        foreach ($headers as $name => $value) {
            if (is_int($name) || !is_string($name)) {
                continue;
            }

            $this->set($name, $value);
        }
    }

    /** @param iterable<array-key, string|list<string>>|self $headers */
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
        $rawHeaders = trim($rawHeaders, "\r\n");
        $lines = str_contains($rawHeaders, "\r\n")
            ? explode("\r\n", $rawHeaders)
            : (preg_split('/\r\n|\n|\r/', $rawHeaders) ?: []);

        foreach ($lines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $headers->add(trim($name), trim($value));
        }

        return $headers;
    }

    /** @param string|array<array-key, mixed> $value */
    public function set(string $name, string|array $value): self
    {
        $this->assertNoControlChars($name, 'Header name');
        $normalized = $this->normalize($name);
        $values = array_map(static fn (mixed $item): string => (string) $item, (array) $value);
        foreach ($values as $item) {
            $this->assertNoControlChars($item, 'Header value');
        }

        $this->items[$normalized] = [
            'name' => $name,
            'values' => array_values($values),
        ];

        return $this;
    }

    /** @param string|array<array-key, mixed> $value */
    public function add(string $name, string|array $value): self
    {
        $this->assertNoControlChars($name, 'Header name');
        $normalized = $this->normalize($name);
        $values = array_map(static fn (mixed $item): string => (string) $item, (array) $value);
        foreach ($values as $item) {
            $this->assertNoControlChars($item, 'Header value');
        }

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

    /** @param iterable<array-key, string|list<string>>|self $headers */
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

    private function assertNoControlChars(string $text, string $subject): void
    {
        if (preg_match('/[\r\n\0]/', $text) === 1) {
            throw new InvalidArgumentException("{$subject} must not contain CR, LF or NUL characters");
        }
    }
}
