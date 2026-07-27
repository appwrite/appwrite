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
                $valueOffset++;
                $value = '';
                while ($valueOffset < $length) {
                    $char = $data[$valueOffset];

                    if ($char === $quote) {
                        $valueOffset++;
                        break;
                    }

                    if ($quote === '"' && $char === '\\' && $valueOffset + 1 < $length) {
                        $next = $data[$valueOffset + 1];
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
                        $valueOffset += 2;
                        continue;
                    }

                    $value .= $char;
                    $valueOffset++;
                }

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
