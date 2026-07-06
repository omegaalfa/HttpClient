<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Http;

final class CookieJar
{
    /**
     * @var array<string, array<string, array<string, array{value: string, expires: ?int, secure: bool, httpOnly: bool}>>>
     */
    private array $cookies = [];

    public function set(
        string $name,
        string $value,
        string $domain = '',
        string $path = '/',
        ?int $expires = null,
        bool $secure = false,
        bool $httpOnly = false
    ): void {
        $domain = ltrim(strtolower($domain), '.');
        $path = $path === '' ? '/' : $path;

        $this->cookies[$domain][$path][$name] = [
            'value' => $value,
            'expires' => $expires,
            'secure' => $secure,
            'httpOnly' => $httpOnly,
        ];
    }

    public function setMany(array $cookies, string $domain = '', string $path = '/'): void
    {
        foreach ($cookies as $name => $value) {
            $this->set((string) $name, (string) $value, $domain, $path);
        }
    }

    public function storeFromHeaders(Headers $headers, string $host): void
    {
        foreach ($headers->values('Set-Cookie') as $line) {
            $parts = array_map('trim', explode(';', $line));
            $nameValue = array_shift($parts);
            if ($nameValue === null || !str_contains($nameValue, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $nameValue, 2);
            $domain = $host;
            $path = '/';
            $expires = null;
            $secure = false;
            $httpOnly = false;

            foreach ($parts as $part) {
                if (str_contains($part, '=')) {
                    [$key, $attributeValue] = explode('=', $part, 2);
                    $key = strtolower(trim($key));
                    $attributeValue = trim($attributeValue);

                    if ($key === 'domain') {
                        $domain = $attributeValue;
                    } elseif ($key === 'path') {
                        $path = $attributeValue;
                    } elseif ($key === 'expires') {
                        $timestamp = strtotime($attributeValue);
                        $expires = $timestamp === false ? null : $timestamp;
                    }
                } else {
                    $flag = strtolower($part);
                    if ($flag === 'secure') {
                        $secure = true;
                    } elseif ($flag === 'httponly') {
                        $httpOnly = true;
                    }
                }
            }

            $this->set(trim($name), trim($value), $domain, $path, $expires, $secure, $httpOnly);
        }
    }

    public function headerFor(string $host, string $path = '/', bool $secure = false): ?string
    {
        $now = time();
        $values = [];
        $host = strtolower($host);

        foreach ($this->cookies as $domain => $paths) {
            if ($domain !== '' && !str_ends_with($host, $domain)) {
                continue;
            }

            foreach ($paths as $cookiePath => $cookies) {
                if (!str_starts_with($path, $cookiePath)) {
                    continue;
                }

                foreach ($cookies as $name => $cookie) {
                    if ($cookie['expires'] !== null && $cookie['expires'] < $now) {
                        continue;
                    }

                    if ($cookie['secure'] && !$secure) {
                        continue;
                    }

                    $values[] = $name . '=' . $cookie['value'];
                }
            }
        }

        return $values === [] ? null : implode('; ', $values);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->cookies;
    }
}