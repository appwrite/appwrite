<?php

declare(strict_types=1);

namespace Utopia\Psr7;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

final class Request extends Message implements RequestInterface
{
    public function __construct(
        private string $method,
        private UriInterface $uri,
        ?StreamInterface $body = null,
    ) {
        parent::__construct(body: $body ?? new Stream());
    }

    public function getRequestTarget(): string
    {
        $target = $this->uri->getPath() === '' ? '/' : $this->uri->getPath();

        if ($this->uri->getQuery() !== '') {
            $target .= '?' . $this->uri->getQuery();
        }

        return $target;
    }

    public function withRequestTarget(string $requestTarget): RequestInterface
    {
        return $this->withUri($this->uri->withPath($requestTarget));
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod(string $method): RequestInterface
    {
        $clone = clone $this;
        $clone->method = strtoupper($method);

        return $clone;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface
    {
        $clone = clone $this;
        $clone->uri = $uri;

        if ($uri->getHost() === '') {
            return $clone;
        }

        if ($preserveHost && $clone->hasHeader('Host') && $clone->getHeaderLine('Host') !== '') {
            return $clone;
        }

        return $clone->withHeader('Host', $this->host($uri));
    }

    private function host(UriInterface $uri): string
    {
        $port = $uri->getPort();

        if ($port === null) {
            return $uri->getHost();
        }

        return $uri->getHost() . ':' . $port;
    }
}
