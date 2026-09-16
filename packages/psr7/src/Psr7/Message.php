<?php

declare(strict_types=1);

namespace Utopia\Psr7;

use InvalidArgumentException;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;

abstract class Message implements MessageInterface
{
    /**
     * @param array<string, array<int, string>> $headers
     * @param array<string, string> $headerNames
     */
    public function __construct(
        protected string $protocolVersion = '1.1',
        protected array $headers = [],
        protected array $headerNames = [],
        protected StreamInterface $body = new Stream(),
    ) {}

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion(string $version): MessageInterface
    {
        $clone = clone $this;
        $clone->protocolVersion = $version;

        return $clone;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headerNames[strtolower($name)]);
    }

    /**
     * @return array<int, string>
     */
    public function getHeader(string $name): array
    {
        $normalized = strtolower($name);

        if (!isset($this->headerNames[$normalized])) {
            return [];
        }

        return $this->headers[$this->headerNames[$normalized]];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): MessageInterface
    {
        $this->assertHeaderName($name);

        $clone = clone $this;
        $normalized = strtolower($name);

        if (isset($clone->headerNames[$normalized])) {
            unset($clone->headers[$clone->headerNames[$normalized]]);
        }

        $clone->headerNames[$normalized] = $name;
        $clone->headers[$name] = $this->normalizeHeaderValue($value);

        return $clone;
    }

    public function withAddedHeader(string $name, $value): MessageInterface
    {
        $this->assertHeaderName($name);

        $clone = clone $this;
        $normalized = strtolower($name);
        $values = $this->normalizeHeaderValue($value);

        if (!isset($clone->headerNames[$normalized])) {
            $clone->headerNames[$normalized] = $name;
            $clone->headers[$name] = $values;

            return $clone;
        }

        $headerName = $clone->headerNames[$normalized];
        $clone->headers[$headerName] = [...$clone->headers[$headerName], ...$values];

        return $clone;
    }

    public function withoutHeader(string $name): MessageInterface
    {
        $clone = clone $this;
        $normalized = strtolower($name);

        if (!isset($clone->headerNames[$normalized])) {
            return $clone;
        }

        unset($clone->headers[$clone->headerNames[$normalized]], $clone->headerNames[$normalized]);

        return $clone;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body): MessageInterface
    {
        $clone = clone $this;
        $clone->body = $body;

        return $clone;
    }

    private function assertHeaderName(string $name): void
    {
        if ($name === '' || preg_match('/^[A-Za-z0-9\'`#$%&*+.^_|~!-]+$/', $name) !== 1) {
            throw new InvalidArgumentException('Invalid header name.');
        }
    }

    /**
     * @return array<int, string>
     */
    private function normalizeHeaderValue(mixed $value): array
    {
        $values = \is_array($value) ? array_values($value) : [$value];

        $normalized = [];

        foreach ($values as $singleValue) {
            if (\is_scalar($singleValue) || $singleValue instanceof \Stringable) {
                $normalized[] = (string) $singleValue;

                continue;
            }

            throw new InvalidArgumentException('Invalid header value.');
        }

        return $normalized;
    }
}
