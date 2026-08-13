<?php

declare(strict_types=1);

namespace Utopia\SMTP;

/**
 * A file to carry along, either held in memory or read from disk when the
 * message is written.
 */
final readonly class Attachment
{
    private const string FALLBACK_TYPE = 'application/octet-stream';

    private function __construct(
        public string $name,
        public string $type,
        public ?string $content,
        public ?string $path,
        public ?string $cid = null,
    ) {
        if (preg_match('/[\r\n\x00]/', $name . $type . ($cid)) === 1) {
            throw new \InvalidArgumentException('An attachment name, type or identifier must not span lines');
        }
    }

    /**
     * Read when the message is written, so a large file is never held twice.
     */
    public static function fromPath(string $path, ?string $name = null, ?string $type = null): self
    {
        if (! is_readable($path)) {
            throw new \InvalidArgumentException("Cannot read attachment: {$path}");
        }

        return new self(
            $name ?? basename($path),
            $type ?? self::detect($path),
            null,
            $path,
        );
    }

    public static function fromString(string $content, string $name, string $type = self::FALLBACK_TYPE): self
    {
        return new self($name, $type, $content, null);
    }

    /**
     * Reference the attachment from markup as `cid:<value>`, which puts it in a
     * multipart/related tree rather than alongside the message.
     */
    public function inline(string $cid): self
    {
        return new self($this->name, $this->type, $this->content, $this->path, $cid);
    }

    private static function detect(string $path): string
    {
        if (! \function_exists('mime_content_type')) {
            return self::FALLBACK_TYPE;
        }

        $type = @mime_content_type($path);

        return $type === false ? self::FALLBACK_TYPE : $type;
    }
}
