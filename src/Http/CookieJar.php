<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Http;

use Pdp\Domain;
use Pdp\Rules;

final class CookieJar
{
    private const PUBLIC_SUFFIX_LIST_PATH = __DIR__ . '/../../resources/public_suffix_list.dat';

    private static ?Rules $publicSuffixRules = null;

    /**
     * @var array<string, array<string, array<string, array{value: string, expires: ?int, secure: bool, httpOnly: bool, hostOnly: bool}>>>
     */
    private array $cookies = [];

    public function set(
        string $name,
        string $value,
        string $domain = '',
        string $path = '/',
        ?int $expires = null,
        bool $secure = false,
        bool $httpOnly = false,
        bool $hostOnly = false
    ): void {
        $domain = ltrim(strtolower($domain), '.');
        $path = $path === '' ? '/' : $path;

        $this->cookies[$domain][$path][$name] = [
            'value' => $value,
            'expires' => $expires,
            'secure' => $secure,
            'httpOnly' => $httpOnly,
            'hostOnly' => $hostOnly,
        ];
    }

    /** @param array<string|int, scalar|null> $cookies */
    public function setMany(array $cookies, string $domain = '', string $path = '/'): void
    {
        foreach ($cookies as $name => $value) {
            $this->set((string) $name, (string) $value, $domain, $path);
        }
    }

    /** @return array<string, string> */
    public function storeFromHeaders(Headers $headers, string $host): array
    {
        $responseCookies = [];
        foreach ($headers->values('Set-Cookie') as $line) {
            $parts = explode(';', $line);
            $nameValue = array_shift($parts);
            if ($nameValue === null || !str_contains($nameValue, '=')) {
                continue;
            }

            [$name, $value] = explode('=', trim($nameValue), 2);
            $name = trim($name);
            $value = trim($value);
            if ($name !== '') {
                $responseCookies[$name] = $value;
            }
            $domain = '';
            $path = '/';
            $expires = null;
            $maxAge = null;
            $secure = false;
            $httpOnly = false;
            $hasDomainAttribute = false;

            foreach ($parts as $part) {
                $part = trim($part);
                if (str_contains($part, '=')) {
                    [$key, $attributeValue] = explode('=', $part, 2);
                    $key = strtolower(trim($key));
                    $attributeValue = trim($attributeValue);

                    if ($key === 'domain') {
                        $hasDomainAttribute = $attributeValue !== '';
                        $domain = ltrim(strtolower($attributeValue), '.');
                    } elseif ($key === 'path') {
                        $path = $attributeValue;
                    } elseif ($key === 'expires') {
                        $timestamp = strtotime($attributeValue);
                        $expires = $timestamp === false ? null : $timestamp;
                    } elseif ($key === 'max-age') {
                        if ($this->isIntegerString($attributeValue)) {
                            $maxAge = (int) $attributeValue;
                        }
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

            $originHost = strtolower($host);
            if ($domain === '') {
                $domain = $originHost;
            } elseif (!self::domainMatches($originHost, $domain)) {
                // Domain não é parent do host de origem — rejeita o cookie (RFC 6265 §5.3.6).
                continue;
            }

            if ($hasDomainAttribute && self::isPublicSuffixOrIp($domain)) {
                continue;
            }

            if ($maxAge !== null) {
                $now = time();
                $expires = $maxAge <= 0
                    ? $now - 1
                    : ($maxAge > PHP_INT_MAX - $now ? PHP_INT_MAX : $now + $maxAge);
            }

            $this->set($name, $value, $domain, $path, $expires, $secure, $httpOnly, !$hasDomainAttribute);
        }

        return $responseCookies;
    }

    public function headerFor(string $host, string $path = '/', bool $secure = false): ?string
    {
        $now = time();
        $values = [];
        $host = strtolower($host);

        // Cookies configured without a domain are scoped to the first request
        // host that uses this jar. This retains the convenient default while
        // preventing credentials from being sent to unrelated origins.
        if (isset($this->cookies[''])) {
            foreach ($this->cookies[''] as $cookiePath => $cookies) {
                foreach ($cookies as $name => $cookie) {
                    $cookie['hostOnly'] = true;
                    $this->cookies[$host][$cookiePath][$name] = $cookie;
                }
            }
            unset($this->cookies['']);
        }

        foreach ($this->cookies as $domain => $paths) {
            if ($domain !== $host && !self::domainMatches($host, $domain)) {
                continue;
            }

            foreach ($paths as $cookiePath => $cookies) {
                if (!self::pathMatches($path, $cookiePath)) {
                    continue;
                }

                foreach ($cookies as $name => $cookie) {
                    if ($cookie['hostOnly'] && $host !== $domain) {
                        continue;
                    }

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

    /**
     * Match exato ou por sufixo em boundary de ponto: cookie de evil.com
     * nunca casa com notevil.com.
     */
    private static function domainMatches(string $host, string $domain): bool
    {
        return $host === $domain || str_ends_with($host, '.' . $domain);
    }

    /** Reject IP domains and Domain attributes that are public suffixes. */
    private static function isPublicSuffixOrIp(string $domain): bool
    {
        if (filter_var($domain, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        $domain = strtolower(rtrim($domain, '.'));
        if ($domain === '' || count(explode('.', $domain)) < 2) {
            return true;
        }

        try {
            // Resolve a child name: resolving the suffix itself is treated as
            // an unregistrable/unknown domain by the parser.
            $suffix = self::publicSuffixRules()
                ->resolve(Domain::fromIDNA2008('psl-check.' . $domain))
                ->suffix();

            return $suffix->isPublicSuffix() && strtolower($suffix->toString()) === $domain;
        } catch (\Throwable) {
            // Fail closed when a Domain attribute cannot be checked safely.
            return true;
        }
    }

    private static function publicSuffixRules(): Rules
    {
        return self::$publicSuffixRules ??= Rules::fromPath(self::PUBLIC_SUFFIX_LIST_PATH);
    }

    private static function pathMatches(string $requestPath, string $cookiePath): bool
    {
        if ($requestPath === $cookiePath) {
            return true;
        }

        if (!str_starts_with($requestPath, $cookiePath)) {
            return false;
        }

        return str_ends_with($cookiePath, '/')
            || ($requestPath[strlen($cookiePath)] ?? '') === '/';
    }

    private function isIntegerString(string $value): bool
    {
        $length = strlen($value);
        if ($length === 0) {
            return false;
        }

        $start = $value[0] === '-' ? 1 : 0;
        if ($start === $length) {
            return false;
        }

        for ($i = $start; $i < $length; $i++) {
            $byte = ord($value[$i]);
            if ($byte < 48 || $byte > 57) {
                return false;
            }
        }

        return true;
    }
}
