<?php

// json.*: json_encode (flags) and json_decode (validity, depth, objects vs arrays, error codes).
//
// Operations without `_throw` report json_last_error() and json_last_error_msg()
// right after the call; their flags must not include JSON_THROW_ON_ERROR (which
// leaves the last error untouched). The `_throw` variants add it.

return [
    'json.encode' => fn (array $a) => [
        'json' => json_encode($a['v'], $a['flags'] ?? 0, $a['depth'] ?? 512),
        'error' => json_last_error(),
        'message' => json_last_error_msg(),
    ],
    // The same call; the Rust side encodes the serde_json::Value model directly.
    'json.encode_value' => fn (array $a) => [
        'json' => json_encode($a['v'], $a['flags'] ?? 0, $a['depth'] ?? 512),
        'error' => json_last_error(),
        'message' => json_last_error_msg(),
    ],
    'json.encode_throw' => fn (array $a) => json_encode($a['v'], ($a['flags'] ?? 0) | JSON_THROW_ON_ERROR, $a['depth'] ?? 512),
    'json.decode' => fn (array $a) => [
        'value' => json_decode($a['s'], $a['assoc'] ?? null, $a['depth'] ?? 512, $a['flags'] ?? 0),
        'error' => json_last_error(),
        'message' => json_last_error_msg(),
    ],
    'json.decode_throw' => fn (array $a) => json_decode($a['s'], $a['assoc'] ?? null, $a['depth'] ?? 512, ($a['flags'] ?? 0) | JSON_THROW_ON_ERROR),
    // json_decode($s, true): the Rust side decodes into the serde_json::Value model.
    'json.decode_value' => fn (array $a) => [
        'value' => json_decode($a['s'], true, $a['depth'] ?? 512, $a['flags'] ?? 0),
        'error' => json_last_error(),
        'message' => json_last_error_msg(),
    ],
    'json.validate' => fn (array $a) => [
        'valid' => json_validate($a['s'], $a['depth'] ?? 512, $a['flags'] ?? 0),
        'error' => json_last_error(),
        'message' => json_last_error_msg(),
    ],
];
