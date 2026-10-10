<?php

// pcre.*: preg_match, preg_match_all, preg_replace, preg_replace_callback,
// preg_split, preg_quote, preg_grep. Each call reports its return value,
// its by-reference outputs, preg_last_error() and the last warning emitted
// (warnings are silenced with @ and read back with error_get_last()).
// pcre.preg_match with "matches": false leaves $matches out of the call:
// with only the pattern and subject, PHP handles an empty match like
// preg_match_all(); flags or offset passed by name give it a $matches.

$warning = static fn (): ?string => error_get_last()['message'] ?? null;

return [
    'pcre.preg_match' => static function (array $a) use ($warning) {
        error_clear_last();
        if (($a['matches'] ?? true) === false) {
            $result = isset($a['flags']) || isset($a['offset'])
                ? @preg_match($a['pattern'], $a['subject'], flags: $a['flags'] ?? 0, offset: $a['offset'] ?? 0)
                : @preg_match($a['pattern'], $a['subject']);

            return ['result' => $result, 'error' => preg_last_error(), 'warning' => $warning()];
        }
        $matches = null;
        $result = @preg_match($a['pattern'], $a['subject'], $matches, $a['flags'] ?? 0, $a['offset'] ?? 0);

        return ['result' => $result, 'matches' => $matches, 'error' => preg_last_error(), 'warning' => $warning()];
    },
    'pcre.preg_match_all' => static function (array $a) use ($warning) {
        error_clear_last();
        $matches = null;
        $result = @preg_match_all($a['pattern'], $a['subject'], $matches, $a['flags'] ?? 0, $a['offset'] ?? 0);

        return ['result' => $result, 'matches' => $matches, 'error' => preg_last_error(), 'warning' => $warning()];
    },
    'pcre.preg_replace' => static function (array $a) use ($warning) {
        error_clear_last();
        $count = null;
        $result = @preg_replace($a['pattern'], $a['replacement'], $a['subject'], $a['limit'] ?? -1, $count);

        return ['result' => $result, 'count' => $count, 'error' => preg_last_error(), 'warning' => $warning()];
    },
    // The callback records what it receives and returns "<n>" for the n-th call.
    'pcre.preg_replace_callback' => static function (array $a) use ($warning) {
        error_clear_last();
        $calls = [];
        $count = null;
        $callback = static function (array $m) use (&$calls): string {
            $calls[] = $m;

            return '<' . \count($calls) . '>';
        };
        $result = @preg_replace_callback($a['pattern'], $callback, $a['subject'], $a['limit'] ?? -1, $count, $a['flags'] ?? 0);

        return ['result' => $result, 'count' => $count, 'calls' => $calls, 'error' => preg_last_error(), 'warning' => $warning()];
    },
    'pcre.preg_split' => static function (array $a) use ($warning) {
        error_clear_last();
        $result = @preg_split($a['pattern'], $a['subject'], $a['limit'] ?? -1, $a['flags'] ?? 0);

        return ['result' => $result, 'error' => preg_last_error(), 'warning' => $warning()];
    },
    'pcre.preg_quote' => static fn (array $a) => preg_quote($a['str'], $a['delimiter'] ?? null),
    'pcre.preg_grep' => static function (array $a) use ($warning) {
        error_clear_last();
        $result = @preg_grep($a['pattern'], $a['array'], $a['flags'] ?? 0);

        return ['result' => $result, 'error' => preg_last_error(), 'warning' => $warning()];
    },
    // pcre.compiled: a pattern compiled once (Regex::compiled): the groups of
    // preg_match($p, $s, $m) === 1 (integer keys) and preg_match($p, $s) === 1.
    // Each call gets its own copy of the subject: a /u call marks the string
    // it checked as valid UTF-8, which changes how the next call runs. The
    // copy is built (not interned, as substr() would for one character), and
    // the empty string stays PHP's interned one.
    'pcre.compiled' => static function (array $a) {
        $fresh = static fn (string $s): string => $s === '' ? $s : implode('', [$s, '']);
        $matches = [];
        $captures = @preg_match($a['pattern'], $fresh($a['subject']), $matches) === 1
            ? array_values(array_filter($matches, is_int(...), ARRAY_FILTER_USE_KEY))
            : null;

        return ['captures' => $captures, 'matched' => @preg_match($a['pattern'], $fresh($a['subject'])) === 1];
    },
];
