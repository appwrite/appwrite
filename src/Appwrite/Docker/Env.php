<?php

namespace Appwrite\Docker;

class Env
{
    /**
     * @var array<string, string|null>
     */
    protected $vars = [];

    public function __construct(string $data)
    {
        $length = \strlen($data);
        $offset = 0;

        while ($offset < $length) {
            $lineEnd = \strpos($data, "\n", $offset);
            if ($lineEnd === false) {
                $lineEnd = $length;
            }

            $line = \substr($data, $offset, $lineEnd - $offset);
            // Normalize Windows newlines for line-oriented parsing of keys.
            if (\str_ends_with($line, "\r")) {
                $line = \substr($line, 0, -1);
            }

            $trimmed = \ltrim($line, " \t");

            // Skip blank lines and comments (including indented comments).
            if ($trimmed === '' || $trimmed[0] === '#') {
                $offset = $lineEnd < $length ? $lineEnd + 1 : $length;
                continue;
            }

            $equals = \strpos($line, '=');
            if ($equals === false) {
                // Skip malformed lines without an assignment on this line.
                $offset = $lineEnd < $length ? $lineEnd + 1 : $length;
                continue;
            }

            $key = \trim(\substr($line, 0, $equals));
            if ($key === '') {
                $offset = $lineEnd < $length ? $lineEnd + 1 : $length;
                continue;
            }

            // Value starts after '=' in the full buffer so quoted values may span lines.
            $valueOffset = $offset + $equals + 1;

            // Consume optional whitespace before the value.
            while ($valueOffset < $length && ($data[$valueOffset] === ' ' || $data[$valueOffset] === "\t")) {
                $valueOffset++;
            }

            if ($valueOffset >= $length || $data[$valueOffset] === "\n" || $data[$valueOffset] === "\r") {
                $this->vars[$key] = '';
                $offset = $valueOffset < $length ? (($data[$valueOffset] === "\r" && $valueOffset + 1 < $length && $data[$valueOffset + 1] === "\n") ? $valueOffset + 2 : $valueOffset + 1) : $length;
                continue;
            }

            $quote = $data[$valueOffset];
            if ($quote === '"' || $quote === "'") {
                $contentStart = $valueOffset + 1;
                $scanOffset = $contentStart;
                $value = '';
                $closed = false;
                $crossedNewline = false;

                while ($scanOffset < $length) {
                    $char = $data[$scanOffset];

                    if ($char === "\n" || $char === "\r") {
                        $crossedNewline = true;
                    }

                    if ($char === $quote) {
                        // A later line's opening quote (KEY=") must not close an
                        // earlier unterminated value, or upgrade will drop that key.
                        if ($crossedNewline && self::isAssignmentOpener($data, $scanOffset)) {
                            break;
                        }
                        $scanOffset++;
                        $closed = true;
                        break;
                    }

                    if ($quote === '"' && $char === '\\' && $scanOffset + 1 < $length) {
                        $next = $data[$scanOffset + 1];
                        $value .= match ($next) {
                            'n' => "\n",
                            'r' => "\r",
                            't' => "\t",
                            '\\' => '\\',
                            '"' => '"',
                            "'" => "'",
                            '$' => '$',
                            '`' => '`',
                            // Preserve unknown escapes as a literal backslash + next char
                            // (e.g. Windows paths or patterns like `\d`, `\q`).
                            default => '\\' . $next,
                        };
                        $scanOffset += 2;
                        continue;
                    }

                    $value .= $char;
                    $scanOffset++;
                }

                if (!$closed) {
                    // Unterminated quote: keep only the remainder of the starting line so
                    // later assignments are not absorbed into this value during upgrade.
                    $end = \strpos($data, "\n", $contentStart);
                    if ($end === false) {
                        $raw = \substr($data, $contentStart);
                        $offset = $length;
                    } else {
                        $raw = \substr($data, $contentStart, $end - $contentStart);
                        $offset = $end + 1;
                    }
                    if (\str_ends_with($raw, "\r")) {
                        $raw = \substr($raw, 0, -1);
                    }
                    $this->vars[$key] = $raw;
                    continue;
                }

                $valueOffset = $scanOffset;

                // Consume trailing characters until end of line (comments/whitespace).
                while ($valueOffset < $length && $data[$valueOffset] !== "\n" && $data[$valueOffset] !== "\r") {
                    $valueOffset++;
                }
                if ($valueOffset < $length) {
                    if ($data[$valueOffset] === "\r" && $valueOffset + 1 < $length && $data[$valueOffset + 1] === "\n") {
                        $valueOffset += 2;
                    } else {
                        $valueOffset++;
                    }
                }

                $this->vars[$key] = $value;
                $offset = $valueOffset;
                continue;
            }

            // Unquoted value: read until end of line, trim trailing whitespace.
            $end = \strpos($data, "\n", $valueOffset);
            if ($end === false) {
                $raw = \substr($data, $valueOffset);
                $offset = $length;
            } else {
                $raw = \substr($data, $valueOffset, $end - $valueOffset);
                $offset = $end + 1;
            }

            if (\str_ends_with($raw, "\r")) {
                $raw = \substr($raw, 0, -1);
            }

            // Strip inline comments preceded by whitespace.
            if (\preg_match('/\s+#/', $raw, $matches, PREG_OFFSET_CAPTURE) === 1) {
                $raw = \substr($raw, 0, $matches[0][1]);
            }

            $this->vars[$key] = \rtrim($raw, " \t");
        }
    }

    /**
     * @param string $key
     * @param mixed  $value
     *
     * @return $this
     */
    public function setVar(string $key, $value): self
    {
        $this->vars[$key] = $value;

        return $this;
    }

    /**
     * @param string $key
     *
     * @return string
     */
    public function getVar(string $key): string
    {
        return (isset($this->vars[$key])) ? $this->vars[$key] : '';
    }

    /**
     * Get All Vars
     *
     * @return array
     */
    public function list(): array
    {
        return $this->vars;
    }

    /**
     * Encode a value for a double-quoted .env assignment.
     */
    public static function encodeValue(string $value): string
    {
        return \addcslashes($value, "\"\\\n\r\t\$`");
    }

    /**
     * True when $quoteOffset points at a quote that opens a new KEY="..." assignment.
     */
    private static function isAssignmentOpener(string $data, int $quoteOffset): bool
    {
        $lineStart = \strrpos(\substr($data, 0, $quoteOffset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $before = \substr($data, $lineStart, $quoteOffset - $lineStart);

        return \preg_match('/^\s*[A-Za-z_][A-Za-z0-9_]*\s*=\s*$/', $before) === 1;
    }

    /**
     * @return string
     */
    public function export(): string
    {
        $output = '';

        foreach ($this->vars as $key => $value) {
            if ($value === null || $value === '') {
                $output .= $key . "=\n";
                continue;
            }

            $output .= $key . '="' . self::encodeValue((string) $value) . "\"\n";
        }

        return $output;
    }
}
