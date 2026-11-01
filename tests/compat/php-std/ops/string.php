<?php

// string.*: byte-string functions (ext/standard/string.c, ext/ctype). The
// arguments are the function's named parameters, passed straight through.

return [
    'string.strtolower' => fn (array $a) => strtolower(...$a),
    'string.strtoupper' => fn (array $a) => strtoupper(...$a),
    'string.ucfirst' => fn (array $a) => ucfirst(...$a),
    'string.lcfirst' => fn (array $a) => lcfirst(...$a),
    'string.strip_tags' => fn (array $a) => strip_tags(...$a),
    'string.ucwords' => fn (array $a) => ucwords(...$a),
    'string.trim' => fn (array $a) => trim(...$a),
    'string.ltrim' => fn (array $a) => ltrim(...$a),
    'string.rtrim' => fn (array $a) => rtrim(...$a),
    'string.substr' => fn (array $a) => substr(...$a),
    'string.stripos' => fn (array $a) => stripos(...$a),
    'string.str_contains' => fn (array $a) => str_contains(...$a),
    'string.str_starts_with' => fn (array $a) => str_starts_with(...$a),
    'string.strcmp' => fn (array $a) => strcmp(...$a),
    'string.strcasecmp' => fn (array $a) => strcasecmp(...$a),
    'string.str_replace' => function (array $a) {
        $result = str_replace($a['search'], $a['replace'], $a['subject'], $count);

        return [$result, $count];
    },
    'string.strtr' => fn (array $a) => strtr(...$a),
    'string.explode' => fn (array $a) => explode(...$a),
    'string.strval' => fn (array $a) => strval(...$a),
    'string.basename' => fn (array $a) => basename(...$a),
    'string.escapeshellarg' => fn (array $a) => escapeshellarg(...$a),
    'string.addcslashes' => fn (array $a) => addcslashes(...$a),
    'string.stripcslashes' => fn (array $a) => stripcslashes(...$a),
    'string.ctype_alnum' => fn (array $a) => ctype_alnum($a['text']),
    'string.ctype_digit' => fn (array $a) => ctype_digit($a['text']),
    'string.ctype_xdigit' => fn (array $a) => ctype_xdigit($a['text']),
    'string.crypt' => fn (array $a) => crypt($a['string'], $a['salt']),
    'string.hash' => fn (array $a) => hash($a['algo'], $a['data']),
    'string.password_verify' => fn (array $a) => password_verify($a['password'], $a['hash']),
    'string.password_hash' => fn (array $a) => password_hash(
        $a['password'],
        $a['algo'] === 'bcrypt' ? PASSWORD_BCRYPT : PASSWORD_ARGON2ID,
        \is_array($a['options'] ?? null) ? $a['options'] : [],
    ),
];
