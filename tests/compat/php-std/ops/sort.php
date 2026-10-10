<?php

// sort.*: sort, rsort, asort, arsort, ksort, krsort (SORT_* flags),
// array_unique, array_diff, array_diff_key, array_intersect, in_array and
// array_search. Values cross as Typed; warnings ("Array to string
// conversion") are silenced, exceptions are the result.

use Tests\Compat\PhpStd\Typed;

require_once __DIR__ . '/../Typed.php';

/**
 * @return array<mixed>
 */
$array = static function (mixed $value): array {
    $value = Typed::decode($value);
    if (!\is_array($value)) {
        throw new \Tests\Compat\Fault('expected an array');
    }

    return $value;
};

/**
 * @return list<array<mixed>>
 */
$arrays = static function (mixed $values) use ($array): array {
    if (!\is_array($values)) {
        throw new \Tests\Compat\Fault('expected a list of arrays');
    }

    return array_values(array_map($array, $values));
};

/**
 * Runs an in-place sort and returns the sorted array.
 */
$sorted = static function (callable $sort, array $a) use ($array): mixed {
    $value = $array($a['a']);
    @$sort($value, (int) ($a['flags'] ?? SORT_REGULAR));

    return Typed::dump($value);
};

return [
    'sort.sort' => fn (array $a) => $sorted(sort(...), $a),
    'sort.rsort' => fn (array $a) => $sorted(rsort(...), $a),
    'sort.asort' => fn (array $a) => $sorted(asort(...), $a),
    'sort.arsort' => fn (array $a) => $sorted(arsort(...), $a),
    'sort.ksort' => fn (array $a) => $sorted(ksort(...), $a),
    'sort.krsort' => fn (array $a) => $sorted(krsort(...), $a),
    'sort.array_unique' => fn (array $a) => Typed::dump(@array_unique($array($a['a']), (int) ($a['flags'] ?? SORT_STRING))),
    'sort.array_diff' => fn (array $a) => Typed::dump(@array_diff($array($a['a']), ...$arrays($a['others']))),
    'sort.array_diff_key' => fn (array $a) => Typed::dump(array_diff_key($array($a['a']), ...$arrays($a['others']))),
    'sort.array_intersect' => fn (array $a) => Typed::dump(@array_intersect($array($a['a']), ...$arrays($a['others']))),
    'sort.in_array' => fn (array $a) => @in_array(Typed::decode($a['needle']), $array($a['haystack']), (bool) ($a['strict'] ?? false)),
    'sort.array_search' => fn (array $a) => @array_search(Typed::decode($a['needle']), $array($a['haystack']), (bool) ($a['strict'] ?? false)),
];
