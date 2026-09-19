<?php

namespace Utopia\Detector;

/**
 * Minimal YAML support for detection. Not a parser: just enough structure to
 * tell a real key from one sitting inside a comment or a quoted value.
 */
final class Yaml
{
    /**
     * Characters a quoted scalar may open after. An apostrophe anywhere else
     * belongs to a plain scalar, as in `description: Don't`.
     */
    private const BOUNDARIES = ':,-[{?';

    /**
     * Removes comments, leaving a `#` inside a quoted value alone. Quote state
     * is tracked per line, so an unterminated quote cannot swallow the rest of
     * the document: the worst it costs is text, never a key it invents.
     */
    public static function stripComments(string $yaml): string
    {
        $stripped = '';
        $quote = '';
        $previous = '';
        $length = \strlen($yaml);

        for ($position = 0; $position < $length; $position++) {
            $character = $yaml[$position];

            if ($character === "\n") {
                $stripped .= $character;
                $quote = '';
                $previous = '';

                continue;
            }

            if ($quote !== '') {
                $stripped .= $character;

                if ($character === $quote) {
                    if ($quote === "'" && ($yaml[$position + 1] ?? '') === "'") {
                        $stripped .= "'";
                        $position++;
                    } elseif (! self::isEscaped($yaml, $position, $quote)) {
                        $quote = '';
                    }
                }

                continue;
            }

            if (self::opensQuote($character, $previous)) {
                $quote = $character;
            } elseif ($character === '#' && ($previous === '' || $yaml[$position - 1] === ' ' || $yaml[$position - 1] === "\t")) {
                while ($position + 1 < $length && $yaml[$position + 1] !== "\n") {
                    $position++;
                }

                continue;
            }

            if ($character !== ' ' && $character !== "\t") {
                $previous = $character;
            }

            $stripped .= $character;
        }

        return $stripped;
    }

    /**
     * Checks for a mapping key, written at the start of a line or inside a flow mapping.
     */
    public static function hasKey(string $yaml, string $key): bool
    {
        $pattern = '/(?:^[ \t]*|[{,]\s*)[\x27\x22]?'.\preg_quote($key, '/').'[\x27\x22]?[ \t]*:/m';

        return \preg_match($pattern, self::stripComments($yaml)) === 1;
    }

    /**
     * Reads a direct child of a mapping body, written in either block or flow style.
     */
    public static function readKey(string $body, string $key): ?string
    {
        $trimmed = \ltrim($body);

        if (\str_starts_with($trimmed, '{')) {
            $entries = self::splitFlow(\substr($trimmed, 1));
        } else {
            $lines = \array_values(\array_filter(\explode("\n", $body), fn ($line) => \trim($line) !== ''));

            if (\count($lines) === 0) {
                return null;
            }

            $indent = \min(\array_map(fn ($line) => \strlen($line) - \strlen(\ltrim($line)), $lines));
            $entries = \array_filter($lines, fn ($line) => \strlen($line) - \strlen(\ltrim($line)) === $indent);
        }

        $pattern = '/^[ \t]*[\x27\x22]?'.\preg_quote($key, '/').'[\x27\x22]?[ \t]*:[ \t]*[\x27\x22]?(?<value>[^\x27\x22\s]*)/';

        foreach ($entries as $entry) {
            if (\preg_match($pattern, $entry, $match) === 1) {
                return $match['value'];
            }
        }

        return null;
    }

    /**
     * Splits a flow mapping on its own commas, ignoring nested collections and
     * quoted values, and stops at the brace closing it.
     *
     * @return array<string>
     */
    private static function splitFlow(string $flow): array
    {
        $entries = [];
        $entry = '';
        $depth = 0;
        $quote = '';
        $previous = '';
        $length = \strlen($flow);

        for ($position = 0; $position < $length; $position++) {
            $character = $flow[$position];

            if ($quote !== '') {
                $entry .= $character;

                if ($character === $quote) {
                    if ($quote === "'" && ($flow[$position + 1] ?? '') === "'") {
                        $entry .= "'";
                        $position++;
                    } elseif (! self::isEscaped($flow, $position, $quote)) {
                        $quote = '';
                    }
                }

                continue;
            }

            if (self::opensQuote($character, $previous)) {
                $quote = $character;
            } elseif ($character === '{' || $character === '[') {
                $depth++;
            } elseif ($character === '}' || $character === ']') {
                if ($depth === 0) {
                    break;
                }

                $depth--;
            } elseif ($character === ',' && $depth === 0) {
                $entries[] = $entry;
                $entry = '';
                $previous = ',';

                continue;
            }

            if (\trim($character) !== '') {
                $previous = $character;
            }

            $entry .= $character;
        }

        $entries[] = $entry;

        return \array_map(fn ($value) => \trim($value), $entries);
    }

    private static function opensQuote(string $character, string $previous): bool
    {
        if ($character !== "'" && $character !== '"') {
            return false;
        }

        return $previous === '' || \str_contains(self::BOUNDARIES, $previous);
    }

    /**
     * Only double quoted scalars use backslash escapes, and only an odd number
     * of them escapes the quote that follows.
     */
    private static function isEscaped(string $text, int $position, string $quote): bool
    {
        if ($quote !== '"') {
            return false;
        }

        $backslashes = 0;

        for ($index = $position - 1; $index >= 0 && $text[$index] === '\\'; $index--) {
            $backslashes++;
        }

        return $backslashes % 2 === 1;
    }
}
