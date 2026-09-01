<?php

declare(strict_types=1);

namespace Appwrite\Smtp\Mime;

use InvalidArgumentException;

final class Parser
{
    private const int MAX_DEPTH = 20;

    private const int MAX_PARTS = 200;

    private int $parts = 0;

    private int $decodedBytes = 0;

    private int $bodyBytes = 0;

    /** @var list<string> */
    private array $text = [];

    /** @var list<string> */
    private array $html = [];

    /** @var list<Attachment> */
    private array $attachments = [];

    public function __construct(
        private readonly int $maximumDecodedBytes,
        private readonly int $maximumBodyBytes = 8_388_608,
    ) {
    }

    public function parse(string $raw): Message
    {
        [$headers, $body] = $this->splitEntity($raw);
        $this->walk($headers, $body, 0);

        return new Message(
            headers: $this->selectedHeaders($headers),
            subject: $this->decodedHeader($headers, 'subject'),
            from: $this->decodedHeader($headers, 'from'),
            to: $this->decodedHeader($headers, 'to'),
            cc: $this->decodedHeader($headers, 'cc'),
            replyTo: $this->decodedHeader($headers, 'reply-to'),
            messageId: $this->header($headers, 'message-id'),
            date: $this->header($headers, 'date'),
            text: implode("\n", $this->text),
            html: implode("\n", $this->html),
            attachments: $this->attachments,
        );
    }

    /** @param array<string, list<string>> $headers */
    private function walk(array $headers, string $body, int $depth): void
    {
        if ($depth > self::MAX_DEPTH || ++$this->parts > self::MAX_PARTS) {
            throw new InvalidArgumentException('MIME message has too many nested parts.');
        }

        [$contentType, $typeParams] = $this->structuredHeader($this->header($headers, 'content-type', 'text/plain'));
        if (str_starts_with($contentType, 'multipart/')) {
            $boundary = $typeParams['boundary'] ?? '';
            if ($boundary === '') {
                throw new InvalidArgumentException('Multipart MIME entity is missing a boundary.');
            }
            foreach ($this->multipartParts($body, $boundary) as $part) {
                [$partHeaders, $partBody] = $this->splitEntity($part);
                $this->walk($partHeaders, $partBody, $depth + 1);
            }

            return;
        }

        $decoded = $this->decodeBody($body, strtolower($this->header($headers, 'content-transfer-encoding')));
        $this->decodedBytes += strlen($decoded);
        if ($this->decodedBytes > $this->maximumDecodedBytes) {
            throw new InvalidArgumentException('Decoded MIME message exceeds the configured limit.');
        }

        [$disposition, $dispositionParams] = $this->structuredHeader($this->header($headers, 'content-disposition'));
        $filename = $dispositionParams['filename'] ?? $typeParams['name'] ?? '';
        $contentId = trim($this->header($headers, 'content-id'), '<> ');
        $attachment = $filename !== '' || $disposition === 'attachment' || $contentId !== '';

        if (! $attachment && $contentType === 'text/plain') {
            $this->appendBody($this->text, $this->toUtf8($decoded, $typeParams['charset'] ?? 'UTF-8'));

            return;
        }
        if (! $attachment && $contentType === 'text/html') {
            $this->appendBody($this->html, $this->toUtf8($decoded, $typeParams['charset'] ?? 'UTF-8'));

            return;
        }

        $this->attachments[] = new Attachment(
            filename: $this->decodeWords($filename !== '' ? $filename : 'attachment'),
            contentType: $contentType !== '' ? $contentType : 'application/octet-stream',
            contentId: $contentId,
            disposition: $disposition !== '' ? $disposition : 'attachment',
            content: $decoded,
        );
    }

    /** @param list<string> $parts */
    private function appendBody(array &$parts, string $value): void
    {
        $this->bodyBytes += strlen($value);
        if ($this->bodyBytes > $this->maximumBodyBytes) {
            throw new InvalidArgumentException('Decoded MIME body exceeds the configured limit.');
        }
        $parts[] = $value;
    }

    /** @return array{array<string, list<string>>, string} */
    private function splitEntity(string $entity): array
    {
        $position = strpos($entity, "\r\n\r\n");
        $separatorLength = 4;
        if ($position === false) {
            $position = strpos($entity, "\n\n");
            $separatorLength = 2;
        }
        if ($position === false) {
            return [$this->parseHeaders($entity), ''];
        }

        return [
            $this->parseHeaders(substr($entity, 0, $position)),
            substr($entity, $position + $separatorLength),
        ];
    }

    /** @return array<string, list<string>> */
    private function parseHeaders(string $raw): array
    {
        $raw = preg_replace("/\r?\n[\t ]+/", ' ', $raw) ?? $raw;
        $headers = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $separator = strpos($line, ':');
            if ($separator === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $separator)));
            if ($name !== '') {
                $headers[$name][] = trim(substr($line, $separator + 1));
            }
        }

        return $headers;
    }

    /** @return list<string> */
    private function multipartParts(string $body, string $boundary): array
    {
        $body = str_replace("\r\n", "\n", $body);
        $delimiter = '--'.$boundary;
        $closing = $delimiter.'--';
        $parts = [];
        $current = null;

        foreach (explode("\n", $body) as $line) {
            if ($line === $delimiter || $line === $closing) {
                if ($current !== null) {
                    $parts[] = implode("\r\n", $current);
                }
                $current = $line === $closing ? null : [];
                if ($line === $closing) {
                    break;
                }

                continue;
            }
            if ($current !== null) {
                $current[] = $line;
            }
        }
        if ($current !== null && $current !== []) {
            $parts[] = implode("\r\n", $current);
        }

        return $parts;
    }

    private function decodeBody(string $body, string $encoding): string
    {
        if ($encoding === 'base64') {
            $decoded = base64_decode(preg_replace('/\s+/', '', $body) ?? $body, true);
            if ($decoded === false) {
                throw new InvalidArgumentException('Invalid base64 MIME body.');
            }

            return $decoded;
        }

        return $encoding === 'quoted-printable' ? quoted_printable_decode($body) : $body;
    }

    /** @return array{string, array<string, string>} */
    private function structuredHeader(string $value): array
    {
        if ($value === '') {
            return ['', []];
        }
        $segments = preg_split('/;(?=(?:[^\"]*\"[^\"]*\")*[^\"]*$)/', $value) ?: [];
        $type = strtolower(trim(array_shift($segments) ?? ''));
        $params = [];
        foreach ($segments as $segment) {
            $separator = strpos($segment, '=');
            if ($separator === false) {
                continue;
            }
            $name = strtolower(trim(substr($segment, 0, $separator)));
            $params[$name] = trim(trim(substr($segment, $separator + 1)), "\"'");
        }

        return [$type, $params];
    }

    /** @param array<string, list<string>> $headers */
    private function header(array $headers, string $name, string $default = ''): string
    {
        return $headers[$name][0] ?? $default;
    }

    /** @param array<string, list<string>> $headers */
    private function decodedHeader(array $headers, string $name): string
    {
        return $this->decodeWords($this->header($headers, $name));
    }

    private function decodeWords(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return $decoded === false ? $value : $decoded;
    }

    private function toUtf8(string $value, string $charset): string
    {
        if ($charset === '' || strcasecmp($charset, 'UTF-8') === 0 || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        $converted = @iconv($charset, 'UTF-8//IGNORE', $value);

        return $converted === false ? $value : $converted;
    }

    /**
     * @param  array<string, list<string>>  $headers
     * @return array<string, list<string>>
     */
    private function selectedHeaders(array $headers): array
    {
        return array_intersect_key($headers, array_flip([
            'message-id',
            'date',
            'from',
            'to',
            'cc',
            'reply-to',
            'subject',
            'in-reply-to',
            'references',
        ]));
    }
}
