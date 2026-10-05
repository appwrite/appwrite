<?php

declare(strict_types=1);

namespace Utopia\Psr7;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;

class ServerRequest extends Message implements ServerRequestInterface
{
    /**
     * @param array<string, mixed> $serverParams
     * @param array<string, string> $cookieParams
     * @param array<string, mixed> $queryParams
     * @param array<array-key, mixed> $uploadedFiles
     * @param null|array<array-key, mixed>|object $parsedBody
     * @param array<string, mixed> $attributes
     * @param array<string, string|array<int, string>> $headers
     */
    public function __construct(
        protected string $method,
        protected UriInterface $uri,
        protected array $serverParams = [],
        protected array $cookieParams = [],
        protected array $queryParams = [],
        protected array $uploadedFiles = [],
        protected array|object|null $parsedBody = null,
        protected array $attributes = [],
        protected ?string $requestTarget = null,
        ?StreamInterface $body = null,
        array $headers = [],
    ) {
        $this->assertUploadedFiles($uploadedFiles);
        [$headers, $headerNames] = $this->normalizeHeaders($headers);

        parent::__construct(headers: $headers, headerNames: $headerNames, body: $body ?? new Stream());
    }

    public function getRequestTarget(): string
    {
        if ($this->requestTarget !== null) {
            return $this->requestTarget;
        }

        $target = $this->uri->getPath() === '' ? '/' : $this->uri->getPath();

        if ($this->uri->getQuery() !== '') {
            $target .= '?' . $this->uri->getQuery();
        }

        return $target;
    }

    public function withRequestTarget(string $requestTarget): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->requestTarget = $requestTarget;

        return $clone;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod(string $method): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->method = strtoupper($method);

        return $clone;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): ServerRequestInterface
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

    /**
     * @return array<string, mixed>
     */
    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    /**
     * @return array<string, string>
     */
    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }

    /**
     * @param array<string, string> $cookies
     */
    public function withCookieParams(array $cookies): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->cookieParams = $cookies;

        return $clone;
    }

    /**
     * @return array<string, mixed>
     */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    /**
     * @param array<string, mixed> $query
     */
    public function withQueryParams(array $query): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->queryParams = $query;

        return $clone;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    /**
     * @param array<array-key, mixed> $uploadedFiles
     */
    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
    {
        $this->assertUploadedFiles($uploadedFiles);

        $clone = clone $this;
        $clone->uploadedFiles = $uploadedFiles;

        return $clone;
    }

    /**
     * @return null|array<array-key, mixed>|object
     */
    public function getParsedBody(): array|object|null
    {
        return $this->parsedBody;
    }

    /**
     * @phpstan-param mixed $data
     */
    public function withParsedBody(mixed $data): ServerRequestInterface
    {
        if ($data === null || \is_array($data) || \is_object($data)) {
            $clone = clone $this;
            $clone->parsedBody = $data;

            return $clone;
        }

        throw new InvalidArgumentException('Parsed body must be null, an array, or an object.');
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute($name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function withAttribute($name, mixed $value): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->attributes[$name] = $value;

        return $clone;
    }

    public function withoutAttribute($name): ServerRequestInterface
    {
        $clone = clone $this;
        unset($clone->attributes[$name]);

        return $clone;
    }

    private function host(UriInterface $uri): string
    {
        $port = $uri->getPort();

        if ($port === null) {
            return $uri->getHost();
        }

        return $uri->getHost() . ':' . $port;
    }

    /**
     * @param array<array-key, mixed> $uploadedFiles
     */
    private function assertUploadedFiles(array $uploadedFiles): void
    {
        foreach ($uploadedFiles as $file) {
            if ($file instanceof UploadedFileInterface) {
                continue;
            }

            if (\is_array($file)) {
                $this->assertUploadedFiles($file);

                continue;
            }

            throw new InvalidArgumentException('Uploaded files must be a tree of UploadedFileInterface instances.');
        }
    }

    /**
     * @param array<string, string|array<int, string>> $headers
     * @return array{0: array<string, array<int, string>>, 1: array<string, string>}
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalizedHeaders = [];
        $headerNames = [];

        foreach ($headers as $name => $values) {
            $name = (string) $name;
            $normalized = strtolower($name);
            $headerNames[$normalized] = $name;
            $normalizedHeaders[$name] = \is_array($values)
                ? array_values(array_map(strval(...), $values))
                : [(string) $values];
        }

        return [$normalizedHeaders, $headerNames];
    }
}
