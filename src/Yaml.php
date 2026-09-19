<?php

namespace Utopia\Detector;

/**
 * Minimal YAML support for detection. Not a parser: just enough structure to
 * tell a real key from one sitting inside a comment or a quoted value.
 */
final class Yaml
{
    /**
     * Removes comments, leaving a `#` inside a quoted value alone.
     */
    public static function stripComments(string $yaml): string
    {
        $pattern = '/(?<quoted>\x27[^\x27\n]*\x27|"[^"\n]*")|(?<before>^|[ \t])#[^\n]*/m';

        return \preg_replace_callback(
            $pattern,
            fn (array $match) => ($match['quoted'] ?? '') !== '' ? $match['quoted'] : ($match['before'] ?? ''),
            $yaml
        ) ?? $yaml;
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

        foreach (\str_split($flow) as $character) {
            if ($quote !== '') {
                $quote = $character === $quote ? '' : $quote;
            } elseif ($character === "'" || $character === '"') {
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

                continue;
            }

            $entry .= $character;
        }

        $entries[] = $entry;

        return \array_map(fn ($value) => \trim($value), $entries);
    }
}
