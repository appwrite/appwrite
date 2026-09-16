<?php

declare(strict_types=1);

namespace Utopia\Psr7\Request\Multipart;

use RuntimeException;

final readonly class Part
{
    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        private string $name,
        private string $content,
        private ?string $path,
        private ?string $filename = null,
        private ?string $contentType = null,
        private array $headers = [],
    ) {}

    /**
     * @param array<string, string> $headers
     */
    public static function field(string $name, string $value, array $headers = []): self
    {
        return new self($name, $value, null, headers: $headers);
    }

    /**
     * Reference a file on disk. The file is read lazily while the request is sent,
     * so the body never has to be held in memory.
     *
     * @param array<string, string> $headers
     */
    public static function file(string $name, string $path, ?string $filename = null, ?string $contentType = null, array $headers = []): self
    {
        if (!is_file($path)) {
            throw new RuntimeException('Unable to read multipart file.');
        }

        return new self($name, '', $path, $filename ?? basename($path), $contentType, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function body(string $name, string $body, ?string $filename = null, ?string $contentType = null, array $headers = []): self
    {
        return new self($name, $body, null, $filename, $contentType, $headers);
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * In-memory content for field and body parts; empty for file parts, whose
     * bytes live at {@see path()}.
     */
    public function content(): string
    {
        return $this->content;
    }

    public function path(): ?string
    {
        return $this->path;
    }

    public function size(): int
    {
        if ($this->path === null) {
            return \strlen($this->content);
        }

        $size = filesize($this->path);

        return $size === false ? 0 : $size;
    }

    public function filename(): ?string
    {
        return $this->filename;
    }

    public function contentType(): ?string
    {
        return $this->contentType;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }
}
