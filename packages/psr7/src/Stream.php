<?php

declare(strict_types=1);

namespace Utopia\Psr7;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class Stream implements StreamInterface, \Stringable
{
    /** @var resource|null */
    private mixed $resource;

    public function __construct(string $content = '')
    {
        $resource = fopen('php://temp', 'r+');

        if (!\is_resource($resource)) {
            throw new RuntimeException('Unable to create stream.');
        }

        $this->resource = $resource;
        $this->write($content);
        $this->rewind();
    }

    /**
     * Wrap an existing stream resource without reading it into memory, so a
     * large payload such as an open file stays on disk while it is sent.
     *
     * @param resource $resource
     */
    public static function fromResource(mixed $resource): self
    {
        if (!\is_resource($resource)) {
            throw new InvalidArgumentException('Expected a valid stream resource.');
        }

        $stream = new self();
        fclose($stream->detach());
        $stream->resource = $resource;

        return $stream;
    }

    public function __toString(): string
    {
        try {
            $this->seek(0);

            $contents = stream_get_contents($this->resource());

            return $contents === false ? '' : $contents;
        } catch (RuntimeException) {
            return '';
        }
    }

    public function close(): void
    {
        if ($this->resource !== null) {
            fclose($this->resource());
            $this->resource = null;
        }
    }

    /**
     * @return resource
     */
    public function detach(): mixed
    {
        $resource = $this->resource();
        $this->resource = null;

        return $resource;
    }

    public function getSize(): ?int
    {
        $stats = fstat($this->resource());

        return $stats === false ? null : $stats['size'];
    }

    public function tell(): int
    {
        $position = ftell($this->resource());

        if ($position === false) {
            throw new RuntimeException('Unable to determine stream position.');
        }

        return $position;
    }

    public function eof(): bool
    {
        return $this->resource === null || feof($this->resource());
    }

    public function isSeekable(): bool
    {
        return $this->resource !== null;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if (fseek($this->resource(), $offset, $whence) !== 0) {
            throw new RuntimeException('Unable to seek stream.');
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return $this->resource !== null;
    }

    public function write(string $string): int
    {
        $written = fwrite($this->resource(), $string);

        if ($written === false) {
            throw new RuntimeException('Unable to write to stream.');
        }

        return $written;
    }

    public function isReadable(): bool
    {
        return $this->resource !== null;
    }

    public function read(int $length): string
    {
        if ($length < 1) {
            throw new RuntimeException('Read length must be greater than zero.');
        }

        $content = fread($this->resource(), $length);

        if ($content === false) {
            throw new RuntimeException('Unable to read stream.');
        }

        return $content;
    }

    public function getContents(): string
    {
        $content = stream_get_contents($this->resource());

        if ($content === false) {
            throw new RuntimeException('Unable to read stream contents.');
        }

        return $content;
    }

    public function getMetadata(?string $key = null): mixed
    {
        $metadata = stream_get_meta_data($this->resource());

        if ($key === null) {
            return $metadata;
        }

        return $metadata[$key] ?? null;
    }

    /**
     * @return resource
     */
    private function resource(): mixed
    {
        if (!\is_resource($this->resource)) {
            throw new RuntimeException('Stream is detached.');
        }

        return $this->resource;
    }
}
