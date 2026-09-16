<?php

declare(strict_types=1);

namespace Utopia\Psr7;

use InvalidArgumentException;
use Psr\Http\Message\UriInterface;

final readonly class Uri implements UriInterface, \Stringable
{
    public function __construct(
        private string $scheme = '',
        private string $userInfo = '',
        private string $host = '',
        private ?int $port = null,
        private string $path = '',
        private string $query = '',
        private string $fragment = '',
    ) {}

    public static function parse(string $uri): self
    {
        $parts = parse_url($uri);

        if ($parts === false) {
            throw new InvalidArgumentException('Invalid URI.');
        }

        $userInfo = $parts['user'] ?? '';

        if (isset($parts['pass'])) {
            $userInfo .= ':' . $parts['pass'];
        }

        return new self(
            isset($parts['scheme']) ? strtolower($parts['scheme']) : '',
            $userInfo,
            isset($parts['host']) ? strtolower($parts['host']) : '',
            $parts['port'] ?? null,
            isset($parts['path']) ? self::encodePath($parts['path']) : '',
            isset($parts['query']) ? self::encodeQueryOrFragment($parts['query']) : '',
            isset($parts['fragment']) ? self::encodeQueryOrFragment($parts['fragment']) : '',
        );
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }

        $authority = $this->host;

        if ($this->userInfo !== '') {
            $authority = $this->userInfo . '@' . $authority;
        }

        if ($this->port !== null && !$this->isDefaultPort()) {
            $authority .= ':' . $this->port;
        }

        return $authority;
    }

    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->isDefaultPort() ? null : $this->port;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getFragment(): string
    {
        return $this->fragment;
    }

    public function withScheme(string $scheme): UriInterface
    {
        return new self(strtolower($scheme), $this->userInfo, $this->host, $this->port, $this->path, $this->query, $this->fragment);
    }

    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        return new self($this->scheme, $password === null ? $user : $user . ':' . $password, $this->host, $this->port, $this->path, $this->query, $this->fragment);
    }

    public function withHost(string $host): UriInterface
    {
        return new self($this->scheme, $this->userInfo, strtolower($host), $this->port, $this->path, $this->query, $this->fragment);
    }

    public function withPort(?int $port): UriInterface
    {
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new InvalidArgumentException('Invalid URI port.');
        }

        return new self($this->scheme, $this->userInfo, $this->host, $port, $this->path, $this->query, $this->fragment);
    }

    public function withPath(string $path): UriInterface
    {
        return new self($this->scheme, $this->userInfo, $this->host, $this->port, self::encodePath($path), $this->query, $this->fragment);
    }

    public function withQuery(string $query): UriInterface
    {
        return new self($this->scheme, $this->userInfo, $this->host, $this->port, $this->path, self::encodeQueryOrFragment(ltrim($query, '?')), $this->fragment);
    }

    public function withFragment(string $fragment): UriInterface
    {
        return new self($this->scheme, $this->userInfo, $this->host, $this->port, $this->path, $this->query, self::encodeQueryOrFragment(ltrim($fragment, '#')));
    }

    public function __toString(): string
    {
        $uri = '';

        if ($this->scheme !== '') {
            $uri .= $this->scheme . ':';
        }

        $authority = $this->getAuthority();

        if ($authority !== '') {
            $uri .= '//' . $authority;
        }

        $uri .= $this->path;

        if ($this->query !== '') {
            $uri .= '?' . $this->query;
        }

        if ($this->fragment !== '') {
            $uri .= '#' . $this->fragment;
        }

        return $uri;
    }

    private function isDefaultPort(): bool
    {
        return ($this->scheme === 'http' && $this->port === 80)
            || ($this->scheme === 'https' && $this->port === 443);
    }

    private static function encodePath(string $path): string
    {
        return preg_replace_callback(
            '/(?:[^A-Za-z0-9_\-\.~!\$&\'\(\)\*\+,;=:@\/%]++|%(?![A-Fa-f0-9]{2}))/',
            static fn(array $matches): string => rawurlencode($matches[0]),
            $path,
        ) ?? '';
    }

    private static function encodeQueryOrFragment(string $value): string
    {
        return preg_replace_callback(
            '/(?:[^A-Za-z0-9_\-\.~!\$&\'\(\)\*\+,;=:@\/\?%]++|%(?![A-Fa-f0-9]{2}))/',
            static fn(array $matches): string => rawurlencode($matches[0]),
            $value,
        ) ?? '';
    }
}
