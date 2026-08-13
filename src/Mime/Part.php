<?php

declare(strict_types=1);

namespace Utopia\SMTP\Mime;

use Utopia\SMTP\Attachment;
use Utopia\SMTP\ConnectionException;
use Utopia\SMTP\Exception;

/**
 * One node of the MIME tree: either content, an attachment, or a boundary with
 * children under it.
 */
final readonly class Part
{
    /** Enough input for a hundred base64 lines per read. */
    private const BLOCK = Encoding::CHUNK * 100;

    /**
     * @param  array<string, string>  $headers
     * @param  list<Part>  $parts
     */
    private function __construct(
        private array $headers,
        private ?string $content = null,
        private ?Attachment $attachment = null,
        private array $parts = [],
        private string $boundary = '',
    ) {}

    public static function text(string $type, string $content): self
    {
        return new self(
            [
                'Content-Type' => "{$type}; charset=utf-8",
                'Content-Transfer-Encoding' => 'quoted-printable',
            ],
            content: Encoding::quotedPrintable($content),
        );
    }

    public static function attachment(Attachment $attachment): self
    {
        $headers = [
            'Content-Type' => $attachment->type,
            'Content-Transfer-Encoding' => 'base64',
            'Content-Disposition' => ($attachment->cid === null ? 'attachment' : 'inline')
                . '; ' . self::filename($attachment->name),
        ];

        if ($attachment->cid !== null) {
            $headers['Content-ID'] = "<{$attachment->cid}>";
        }

        return new self($headers, attachment: $attachment);
    }

    public static function multipart(string $subtype, self ...$parts): self
    {
        $boundary = Encoding::boundary();

        return new self(
            ['Content-Type' => "multipart/{$subtype}; boundary=\"{$boundary}\""],
            parts: array_values($parts),
            boundary: $boundary,
        );
    }

    /**
     * This part's own headers, followed by the blank line that ends them.
     */
    public function head(): string
    {
        $head = '';

        foreach ($this->headers as $name => $value) {
            $head .= Header::line($name, $value);
        }

        return $head . "\r\n";
    }

    /**
     * @return \Generator<string>
     */
    public function body(): \Generator
    {
        if ($this->parts !== []) {
            foreach ($this->parts as $part) {
                // RFC 2046: the CRLF before a boundary belongs to the delimiter,
                // not to the part above it.
                yield "--{$this->boundary}\r\n";
                yield $part->head();
                yield from $part->body();
                yield "\r\n";
            }

            yield "--{$this->boundary}--\r\n";

            return;
        }

        if ($this->attachment instanceof Attachment) {
            yield from $this->read($this->attachment);

            return;
        }

        yield $this->content ?? '';
    }

    /**
     * @return \Generator<string>
     */
    private function read(Attachment $attachment): \Generator
    {
        if ($attachment->content !== null) {
            yield Encoding::base64($attachment->content);

            return;
        }

        $handle = @fopen((string) $attachment->path, 'rb');

        if ($handle === false) {
            throw new Exception("Cannot read attachment: {$attachment->path}");
        }

        try {
            while (! feof($handle)) {
                $block = fread($handle, self::BLOCK);

                if ($block === false) {
                    throw new ConnectionException("Failed reading attachment: {$attachment->path}");
                }

                if ($block !== '') {
                    yield Encoding::base64($block);
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * RFC 2183 for the plain case, RFC 2231 once the name leaves ASCII.
     */
    private static function filename(string $name): string
    {
        if (Encoding::isAscii($name)) {
            return 'filename="' . addcslashes($name, '"\\') . '"';
        }

        return "filename*=UTF-8''" . rawurlencode($name);
    }
}
