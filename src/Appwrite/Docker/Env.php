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
            // Skip blank lines and comments.
            if ($data[$offset] === "\n" || $data[$offset] === "\r") {
                $offset++;
                continue;
            }

            if ($data[$offset] === '#') {
                $nextLine = \strpos($data, "\n", $offset);
                $offset = $nextLine === false ? $length : $nextLine + 1;
                continue;
            }

            $equals = \strpos($data, '=', $offset);
            if ($equals === false) {
                break;
            }

            $key = \trim(\substr($data, $offset, $equals - $offset));
            $offset = $equals + 1;

            if ($key === '') {
                // Skip malformed lines without a key.
                $nextLine = \strpos($data, "\n", $offset);
                $offset = $nextLine === false ? $length : $nextLine + 1;
                continue;
            }

            // Consume optional whitespace before the value.
            while ($offset < $length && ($data[$offset] === ' ' || $data[$offset] === "\t")) {
                $offset++;
            }

            if ($offset >= $length || $data[$offset] === "\n" || $data[$offset] === "\r") {
                $this->vars[$key] = '';
                if ($offset < $length) {
                    $offset++;
                }
                continue;
            }

            $quote = $data[$offset];
            if ($quote === '"' || $quote === "'") {
                $offset++;
                $value = '';
                while ($offset < $length) {
                    $char = $data[$offset];

                    if ($char === $quote) {
                        $offset++;
                        break;
                    }

                    if ($quote === '"' && $char === '\\' && $offset + 1 < $length) {
                        $next = $data[$offset + 1];
                        $value .= match ($next) {
                            'n' => "\n",
                            'r' => "\r",
                            't' => "\t",
                            '\\' => '\\',
                            '"' => '"',
                            "'" => "'",
                            '$' => '$',
                            '`' => '`',
                            default => $next,
                        };
                        $offset += 2;
                        continue;
                    }

                    $value .= $char;
                    $offset++;
                }

                // Consume trailing characters until end of line (comments/whitespace).
                while ($offset < $length && $data[$offset] !== "\n" && $data[$offset] !== "\r") {
                    $offset++;
                }
                if ($offset < $length) {
                    $offset++;
                }

                $this->vars[$key] = $value;
                continue;
            }

            // Unquoted value: read until end of line, trim trailing whitespace.
            $end = \strpos($data, "\n", $offset);
            if ($end === false) {
                $raw = \substr($data, $offset);
                $offset = $length;
            } else {
                $raw = \substr($data, $offset, $end - $offset);
                $offset = $end + 1;
            }

            // Strip inline comments preceded by whitespace.
            if (\preg_match('/\s+#/', $raw, $matches, PREG_OFFSET_CAPTURE) === 1) {
                $raw = \substr($raw, 0, $matches[0][1]);
            }

            $this->vars[$key] = \rtrim($raw, " \t\r");
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
