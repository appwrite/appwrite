<?php

declare(strict_types=1);

namespace Utopia\Psr7\Request\Multipart;

use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Utopia\Psr7\Header;
use Utopia\Psr7\Stream;

/**
 * A read-only PSR-7 stream that serialises a multipart/form-data body lazily:
 * in-memory parts are buffered, but file parts are read from disk in chunks as
 * the stream is consumed, so an upload never holds the whole body in memory. It
 * also exposes its parts and boundary so an adapter (e.g. Swoole) can stream them
 * through a native upload API instead of reading the serialised bytes.
 */
final class Body implements StreamInterface, \Stringable
{
    /**
     * Read increment used only when buffering the whole body into a string
     * (getContents()/__toString()); streaming reads are sized by the transport.
     */
    private const int BUFFER_SIZE = 65_536;

    /** @var list<StreamInterface> */
    private array $segments;

    private int $index = 0;

    private int $position = 0;

    private readonly ?int $size;

    /**
     * @param list<Part> $parts
     */
    public function __construct(
        private readonly string $boundary,
        private readonly array $parts,
    ) {
        $this->segments = $this->build($boundary, $parts);
        $this->size = $this->measure($this->segments);
    }

    public function boundary(): string
    {
        return $this->boundary;
    }

    /**
     * @return list<Part>
     */
    public function parts(): array
    {
        return $this->parts;
    }

    public function __toString(): string
    {
        try {
            $this->rewind();

            return $this->getContents();
        } catch (RuntimeException) {
            return '';
        }
    }

    public function getContents(): string
    {
        $contents = '';

        while (!$this->eof()) {
            $contents .= $this->read(self::BUFFER_SIZE);
        }

        return $contents;
    }

    public function read(int $length): string
    {
        if ($length < 1) {
            throw new RuntimeException('Read length must be greater than zero.');
        }

        while ($this->index < \count($this->segments)) {
            $segment = $this->segments[$this->index];

            if (!$segment->eof()) {
                $chunk = $segment->read($length);

                if ($chunk !== '') {
                    $this->position += \strlen((string) $chunk);

                    return $chunk;
                }
            }

            $this->index++;
        }

        return '';
    }

    public function eof(): bool
    {
        return $this->index >= \count($this->segments);
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function isSeekable(): bool
    {
        return true;
    }

    public function rewind(): void
    {
        foreach ($this->segments as $segment) {
            $segment->rewind();
        }

        $this->index = 0;
        $this->position = 0;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if ($offset !== 0 || $whence !== SEEK_SET) {
            throw new RuntimeException('A multipart body can only be rewound.');
        }

        $this->rewind();
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('A multipart body is not writable.');
    }

    public function close(): void
    {
        foreach ($this->segments as $segment) {
            $segment->close();
        }
    }

    public function detach(): null
    {
        $this->close();

        return null;
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }

    /**
     * @param list<Part> $parts
     *
     * @return list<StreamInterface>
     */
    private function build(string $boundary, array $parts): array
    {
        $segments = [];

        foreach ($parts as $part) {
            $segments[] = new Stream('--' . $boundary . "\r\n" . $this->headers($part) . "\r\n");
            $segments[] = $this->content($part);
            $segments[] = new Stream("\r\n");
        }

        $segments[] = new Stream('--' . $boundary . "--\r\n");

        return $segments;
    }

    private function content(Part $part): StreamInterface
    {
        $path = $part->path();

        if ($path === null) {
            return new Stream($part->content());
        }

        $resource = fopen($path, 'rb');

        if (!\is_resource($resource)) {
            throw new RuntimeException('Unable to open multipart file.');
        }

        return Stream::fromResource($resource);
    }

    private function headers(Part $part): string
    {
        $disposition = 'form-data; name="' . $this->escape($part->name()) . '"';

        if ($part->filename() !== null) {
            $disposition .= '; filename="' . $this->escape($part->filename()) . '"';
        }

        $headers = [Header::CONTENT_DISPOSITION => $disposition];

        if ($part->contentType() !== null) {
            $headers[Header::CONTENT_TYPE] = $part->contentType();
        }

        foreach ($part->headers() as $name => $value) {
            $headers[$name] = $value;
        }

        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    private function escape(string $value): string
    {
        return addcslashes($value, '\\"');
    }

    /**
     * @param list<StreamInterface> $segments
     */
    private function measure(array $segments): ?int
    {
        $total = 0;

        foreach ($segments as $segment) {
            $size = $segment->getSize();

            if ($size === null) {
                return null;
            }

            $total += $size;
        }

        return $total;
    }
}
