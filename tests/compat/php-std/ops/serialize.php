<?php

// serialize.*: serialize/unserialize and var_export formats.

/**
 * Runs $call and returns its result with the warnings and deprecations it
 * emitted (unserialize() reports its errors that way).
 *
 * @return array{0: mixed, 1: list<string>}
 */
$withWarnings = static function (callable $call): array {
    $warnings = [];
    set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });
    try {
        $result = $call();
    } finally {
        restore_error_handler();
    }

    return [$result, $warnings];
};

return [
    'serialize.serialize' => fn (array $a) => serialize($a['v']),
    // The same call; the Rust side serializes the serde_json::Value model directly.
    'serialize.serialize_value' => fn (array $a) => serialize($a['v']),
    'serialize.var_export' => fn (array $a) => var_export($a['v'], true),
    'serialize.var_export_value' => fn (array $a) => var_export($a['v'], true),
    'serialize.unserialize' => function (array $a) use ($withWarnings) {
        [$value, $warnings] = $withWarnings(fn () => unserialize($a['s'], $a['options'] ?? []));

        return ['value' => $value, 'warnings' => $warnings];
    },
    // serialize(unserialize($s)): compares what was read byte for byte (binary keys included).
    'serialize.roundtrip' => function (array $a) use ($withWarnings) {
        [$value, $warnings] = $withWarnings(fn () => unserialize($a['s'], $a['options'] ?? []));

        return ['serialized' => serialize($value), 'warnings' => $warnings];
    },
];
