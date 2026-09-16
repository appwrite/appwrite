<?php

declare(strict_types=1);

namespace Utopia\Psr7\Request;

use JsonException;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;
use Utopia\Psr7\ContentType;
use Utopia\Psr7\Header;
use Utopia\Psr7\Request;
use Utopia\Psr7\Request\Multipart\Body;
use Utopia\Psr7\Request\Multipart\Part;
use Utopia\Psr7\Stream;
use Utopia\Psr7\Uri;

final readonly class Factory implements RequestFactoryInterface
{
    public function __construct(
        private UriFactoryInterface $uriFactory = new Uri\Factory(),
        private StreamFactoryInterface $streamFactory = new Stream\Factory(),
    ) {}

    public function createRequest(string $method, $uri): RequestInterface
    {
        $uri = $uri instanceof UriInterface ? $uri : $this->uriFactory->createUri((string) $uri);

        return new Request(strtoupper($method), $uri)
            ->withUri($uri);
    }

    /**
     * @param array<string, string|array<int, string>> $headers
     *
     * @throws JsonException
     */
    public function json(string $method, UriInterface|string $uri, mixed $data, array $headers = []): RequestInterface
    {
        $request = $this->body($method, $uri, json_encode($data, JSON_THROW_ON_ERROR), ContentType::JSON, $headers);

        if (!$request->hasHeader(Header::ACCEPT)) {
            return $request->withHeader(Header::ACCEPT, ContentType::JSON);
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|array<int, string>> $headers
     */
    public function form(string $method, UriInterface|string $uri, array $data, array $headers = []): RequestInterface
    {
        return $this->body(
            $method,
            $uri,
            http_build_query($data, '', '&', PHP_QUERY_RFC3986),
            ContentType::FORM_URLENCODED,
            $headers,
        );
    }

    /**
     * @param array<string, string|array<int, string>> $headers
     */
    public function text(string $method, UriInterface|string $uri, string $text, array $headers = []): RequestInterface
    {
        return $this->body($method, $uri, $text, ContentType::PLAIN_TEXT, $headers);
    }

    /**
     * @param array<string, string|array<int, string>> $headers
     */
    public function xml(string $method, UriInterface|string $uri, string $xml, array $headers = []): RequestInterface
    {
        return $this->body($method, $uri, $xml, ContentType::XML, $headers);
    }

    /**
     * @param array<string, string|array<int, string>> $headers
     */
    public function body(string $method, UriInterface|string $uri, string $body, string $contentType, array $headers = []): RequestInterface
    {
        $request = $this->applyHeaders(
            $this->createRequest($method, $uri),
            $headers,
        )->withBody($this->streamFactory->createStream($body));

        if (!$request->hasHeader(Header::CONTENT_TYPE)) {
            return $request->withHeader(Header::CONTENT_TYPE, $contentType);
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, string|array<int, string>> $headers
     */
    public function query(string $method, UriInterface|string $uri, array $parameters, array $headers = []): RequestInterface
    {
        $request = $this->applyHeaders(
            $this->createRequest($method, $uri),
            $headers,
        );

        if ($parameters === []) {
            return $request;
        }

        $uri = $request->getUri();
        $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);

        if ($uri->getQuery() !== '') {
            $query = $uri->getQuery() . '&' . $query;
        }

        return $request->withUri($uri->withQuery($query));
    }

    /**
     * @param array<array-key, scalar|Part> $parts
     * @param array<string, string|array<int, string>> $headers
     */
    public function multipart(string $method, UriInterface|string $uri, array $parts, array $headers = []): RequestInterface
    {
        $boundary = $this->boundary();
        $request = $this->applyHeaders(
            $this->createRequest($method, $uri),
            $headers,
        )->withBody(new Body($boundary, $this->parts($parts)));

        return $request->withHeader(
            Header::CONTENT_TYPE,
            ContentType::MULTIPART_FORM_DATA . '; boundary=' . $boundary,
        );
    }

    /**
     * @param array<string, string|array<int, string>> $headers
     */
    private function applyHeaders(RequestInterface $request, array $headers): RequestInterface
    {
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    private function boundary(): string
    {
        return 'utopia-' . bin2hex(random_bytes(16));
    }

    /**
     * @param array<array-key, scalar|Part> $parts
     *
     * @return list<Part>
     */
    private function parts(array $parts): array
    {
        $normalized = [];

        foreach ($parts as $name => $part) {
            $normalized[] = $part instanceof Part ? $part : Part::field((string) $name, (string) $part);
        }

        return $normalized;
    }
}
