<?php

declare(strict_types=1);

namespace Utopia\Psr7;

use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use SimpleXMLElement;
use Utopia\Psr7\Response\Multipart\Part;
use ValueError;

final class Response extends Message implements ResponseInterface
{
    public function __construct(
        private int $statusCode = 200,
        private string $reasonPhrase = '',
        ?StreamInterface $body = null,
    ) {
        $this->assertStatusCode($statusCode);

        parent::__construct(body: $body ?? new Stream());
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        $this->assertStatusCode($code);

        $clone = clone $this;
        $clone->statusCode = $code;
        $clone->reasonPhrase = $reasonPhrase;

        return $clone;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    public function contentType(): string
    {
        $contentType = $this->getHeaderLine(Header::CONTENT_TYPE);
        $position = strpos($contentType, ';');

        if ($position !== false) {
            $contentType = substr($contentType, 0, $position);
        }

        return strtolower(trim($contentType));
    }

    /**
     * @throws JsonException
     */
    public function json(bool $associative = true, int $depth = 512, int $flags = 0): mixed
    {
        if ($depth < 1) {
            throw new ValueError('JSON decode depth must be greater than zero.');
        }

        return json_decode((string) $this->getBody(), $associative, $depth, $flags | JSON_THROW_ON_ERROR);
    }

    public function text(): string
    {
        return (string) $this->getBody();
    }

    public function xml(int $options = 0): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $xml = simplexml_load_string((string) $this->getBody(), SimpleXMLElement::class, $options);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            throw new InvalidArgumentException('Invalid XML response.');
        }

        return $xml;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function form(): array
    {
        parse_str((string) $this->getBody(), $data);

        return $data;
    }

    /**
     * @return array<int, Part>
     */
    public function multipart(): array
    {
        $boundary = $this->boundary();
        $body = (string) $this->getBody();
        $segments = $this->segments($body, $boundary);
        $parts = [];

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            $parts[] = $this->part($segment);
        }

        return $parts;
    }

    private function assertStatusCode(int $statusCode): void
    {
        if ($statusCode < 100 || $statusCode > 599) {
            throw new InvalidArgumentException('Invalid response status code.');
        }
    }

    private function boundary(): string
    {
        $contentType = $this->getHeaderLine(Header::CONTENT_TYPE);

        if (preg_match('/(?:^|;\s*)boundary=(?:"(?P<quoted>[^"]+)"|(?P<plain>[^;\s]+))/', $contentType, $matches) !== 1) {
            throw new InvalidArgumentException('Multipart response is missing a boundary.');
        }

        return $matches['quoted'] !== '' ? $matches['quoted'] : $matches['plain'];
    }

    /**
     * @return array<int, string>
     */
    private function segments(string $body, string $boundary): array
    {
        $pattern = '/(^|\r\n|\n|\r)--' . preg_quote($boundary, '/') . '(--)?[ \t]*(?:\r\n|\n|\r|$)/';

        if (preg_match_all($pattern, $body, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        $segments = [];
        $previousEnd = null;

        foreach ($matches as $match) {
            $delimiterOffset = $match[0][1];

            if ($previousEnd !== null) {
                $segments[] = substr($body, $previousEnd, $delimiterOffset - $previousEnd);
            }

            $previousEnd = $delimiterOffset + \strlen($match[0][0]);

            if (isset($match[2]) && $match[2][0] === '--') {
                break;
            }
        }

        return $segments;
    }

    private function part(string $segment): Part
    {
        [$rawHeaders, $body] = $this->splitPart($segment);

        return new Part($this->headers($rawHeaders), $body);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitPart(string $segment): array
    {
        foreach (["\r\n\r\n", "\n\n", "\r\r"] as $separator) {
            $position = strpos($segment, $separator);

            if ($position !== false) {
                return [
                    substr($segment, 0, $position),
                    substr($segment, $position + \strlen($separator)),
                ];
            }
        }

        return ['', $segment];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function headers(string $rawHeaders): array
    {
        $headers = [];
        $lines = preg_split("/\r\n|\n|\r/", $rawHeaders);

        foreach ($lines === false ? [] : $lines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $name = trim($name);

            if ($name !== '') {
                $headers[$name][] = trim($value);
            }
        }

        return $headers;
    }
}
