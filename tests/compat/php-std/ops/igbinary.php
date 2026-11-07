<?php

// igbinary.*: igbinary_serialize / igbinary_unserialize (the extension in the
// dev image; Appwrite's cache and queue use it). Values cross as Typed.

use Tests\Compat\PhpStd\Typed;

require_once __DIR__ . '/../Typed.php';

/**
 * igbinary_unserialize($s) with the warnings it emitted.
 *
 * @return array{0: mixed, 1: list<string>}
 */
$unserialize = static function (string $s): array {
    $warnings = [];
    set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });
    try {
        $value = igbinary_unserialize($s);
    } finally {
        restore_error_handler();
    }

    return [$value, $warnings];
};

return [
    'igbinary.serialize' => fn (array $a) => igbinary_serialize(Typed::decode($a['v'])),
    'igbinary.unserialize' => function (array $a) use ($unserialize) {
        [$value, $warnings] = $unserialize($a['s']);

        return ['value' => Typed::dump($value), 'warnings' => $warnings];
    },
    // Serialize, read back, serialize again: the reader against the writer.
    'igbinary.roundtrip' => function (array $a) use ($unserialize) {
        $serialized = (string) igbinary_serialize(Typed::decode($a['v']));
        [$value, $warnings] = $unserialize($serialized);

        return [
            'serialized' => $serialized,
            'value' => Typed::dump($value),
            'reserialized' => igbinary_serialize($value),
            'warnings' => $warnings,
        ];
    },
];
