<?php

namespace Appwrite\Utopia\Fetch;

use Exception;

/**
 * Reads the streaming executor response format, where part content is length prefixed. Content is
 * never scanned for the boundary, so a body carrying the boundary string cannot split the envelope.
 */
class BodyMultipartStream
{
    private const STATE_DELIMITER = 0;
    private const STATE_HEADERS = 1;
    private const STATE_SIZE = 2;
    private const STATE_DATA = 3;
    private const STATE_DATA_EOL = 4;
    private const STATE_PART_EOL = 5;
    private const STATE_COMPLETE = 6;

    private string $buffer = '';
    private int $state = self::STATE_DELIMITER;
    private string $part = '';
    private int $remaining = 0;

    /**
     * @param \Closure(string, string, bool): void $onData Part name, content run, and whether the
     *                                                     part is now complete.
     */
    public function __construct(
        private readonly string $boundary,
        private readonly \Closure $onData,
    ) {
    }

    public function feed(string $data): void
    {
        $this->buffer .= $data;

        while (true) {
            switch ($this->state) {
                case self::STATE_COMPLETE:
                    return;

                case self::STATE_DELIMITER:
                    $delimiter = '--' . $this->boundary;

                    // Enough to tell a closing delimiter from a separating one.
                    if (\strlen($this->buffer) < \strlen($delimiter) + 2) {
                        return;
                    }

                    if (!\str_starts_with($this->buffer, $delimiter)) {
                        throw new Exception('Expected multipart delimiter');
                    }

                    $rest = \substr($this->buffer, \strlen($delimiter));

                    if (\str_starts_with($rest, '--')) {
                        $this->state = self::STATE_COMPLETE;
                        $this->buffer = '';

                        return;
                    }

                    if (!\str_starts_with($rest, "\r\n")) {
                        throw new Exception('Malformed multipart delimiter');
                    }

                    $this->buffer = \substr($rest, 2);
                    $this->state = self::STATE_HEADERS;
                    break;

                case self::STATE_HEADERS:
                    $end = \strpos($this->buffer, "\r\n\r\n");

                    if ($end === false) {
                        return;
                    }

                    $headers = \substr($this->buffer, 0, $end);
                    $this->buffer = \substr($this->buffer, $end + 4);
                    $this->part = $this->readName($headers);
                    $this->state = self::STATE_SIZE;
                    break;

                case self::STATE_SIZE:
                    $eol = \strpos($this->buffer, "\r\n");

                    if ($eol === false) {
                        return;
                    }

                    $size = \substr($this->buffer, 0, $eol);

                    if ($size === '' || !\ctype_xdigit($size)) {
                        throw new Exception('Malformed chunk size in part "' . $this->part . '"');
                    }

                    $this->buffer = \substr($this->buffer, $eol + 2);
                    $this->remaining = \intval($size, 16);
                    $this->state = $this->remaining === 0 ? self::STATE_PART_EOL : self::STATE_DATA;
                    break;

                case self::STATE_DATA:
                    $take = \min($this->remaining, \strlen($this->buffer));

                    if ($take > 0) {
                        ($this->onData)($this->part, \substr($this->buffer, 0, $take), false);
                        $this->buffer = \substr($this->buffer, $take);
                        $this->remaining -= $take;
                    }

                    if ($this->remaining > 0) {
                        return;
                    }

                    $this->state = self::STATE_DATA_EOL;
                    break;

                case self::STATE_DATA_EOL:
                    if (\strlen($this->buffer) < 2) {
                        return;
                    }

                    if (!\str_starts_with($this->buffer, "\r\n")) {
                        throw new Exception('Malformed content terminator in part "' . $this->part . '"');
                    }

                    $this->buffer = \substr($this->buffer, 2);
                    $this->state = self::STATE_SIZE;
                    break;

                case self::STATE_PART_EOL:
                    if (\strlen($this->buffer) < 2) {
                        return;
                    }

                    if (!\str_starts_with($this->buffer, "\r\n")) {
                        throw new Exception('Malformed part terminator in part "' . $this->part . '"');
                    }

                    $this->buffer = \substr($this->buffer, 2);
                    ($this->onData)($this->part, '', true);
                    $this->state = self::STATE_DELIMITER;
                    break;
            }
        }
    }

    public function isComplete(): bool
    {
        return $this->state === self::STATE_COMPLETE;
    }

    private function readName(string $headers): string
    {
        $chunked = false;
        $name = '';

        foreach (\explode("\r\n", $headers) as $header) {
            [$key, $value] = \array_pad(\explode(':', $header, 2), 2, '');
            $key = \strtolower(\trim($key));
            $value = \trim($value);

            if ($key === 'content-transfer-encoding') {
                $chunked = \strtolower($value) === 'chunked';
                continue;
            }

            if ($key !== 'content-disposition') {
                continue;
            }

            foreach (\explode(';', $value) as $attribute) {
                [$attributeKey, $attributeValue] = \array_pad(\explode('=', $attribute, 2), 2, '');

                if (\trim($attributeKey) === 'name') {
                    $name = \trim(\trim($attributeValue), '"');
                }
            }
        }

        if ($name === '') {
            throw new Exception('Multipart part is missing a name');
        }

        if (!$chunked) {
            throw new Exception('Part "' . $name . '" is not chunked');
        }

        return $name;
    }
}
