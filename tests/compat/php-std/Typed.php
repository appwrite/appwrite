<?php

namespace Tests\Compat\PhpStd;

/**
 * PHP values on the wire, exactly: what operations on structured values
 * (types, igbinary, sort, typed json/serialize) take and return. The Rust
 * side is crates/tools/compat/src/libs/php_std/typed.rs.
 *
 * Input: what Tests\Compat\Codec decodes (lists, objects as arrays, {} as
 * stdClass, {"$bytes"}, {"$float"}), plus {"$array": [pair...]} (an array,
 * keys assigned like `$a[$k] = $v`) and {"$object": [pair...]} (a stdClass,
 * property names as given). A pair is [k, v] or {"k": k, "v": v}.
 *
 * Every empty array decodes to the engine's shared empty array (`[]`).
 *
 * Output (dump): lists as lists, other arrays as {"$array": [[k, v]...]},
 * stdClass as {"$object": [[k, v]...]}, -0.0 as {"$float": "-0"}; the Codec
 * then encodes binary strings and non-finite floats.
 */
final class Typed
{
    public static function decode(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if ($value === []) {
            // The engine's shared empty array, as json_decode() and
            // unserialize() produce it (array_map() would allocate one, and
            // igbinary writes shared instances as back-references).
            return [];
        }
        if (\count($value) === 1 && isset($value['$array']) && \is_array($value['$array'])) {
            $array = [];
            foreach (self::pairs($value['$array']) as [$key, $item]) {
                $array[$key] = $item;
            }

            return $array;
        }
        if (\count($value) === 1 && isset($value['$object']) && \is_array($value['$object'])) {
            $properties = [];
            foreach (self::pairs($value['$object']) as [$key, $item]) {
                $properties[$key] = $item;
            }

            return (object) $properties;
        }

        return array_map(self::decode(...), $value);
    }

    /**
     * @param array<mixed> $pairs
     * @return list<array{0: int|string, 1: mixed}>
     */
    private static function pairs(array $pairs): array
    {
        $out = [];
        foreach ($pairs as $pair) {
            if (!\is_array($pair)) {
                throw new \Tests\Compat\Fault('a pair is [k, v] or {"k", "v"}');
            }
            $key = array_key_exists('k', $pair) ? $pair['k'] : ($pair[0] ?? null);
            $item = array_key_exists('v', $pair) ? $pair['v'] : ($pair[1] ?? null);
            if (!\is_int($key) && !\is_string($key)) {
                throw new \Tests\Compat\Fault('a key is an int or a string');
            }
            $out[] = [$key, self::decode($item)];
        }

        return $out;
    }

    public static function dump(mixed $value): mixed
    {
        if (\is_float($value) && $value === 0.0 && fdiv(1, $value) < 0) {
            return ['$float' => '-0'];
        }
        if (\is_array($value)) {
            if (array_is_list($value)) {
                return array_map(self::dump(...), $value);
            }
            $pairs = [];
            foreach ($value as $key => $item) {
                $pairs[] = [$key, self::dump($item)];
            }

            return ['$array' => $pairs];
        }
        if ($value instanceof \stdClass) {
            $pairs = [];
            foreach ((array) $value as $key => $item) {
                $pairs[] = [(string) $key, self::dump($item)];
            }

            return ['$object' => $pairs];
        }

        return $value;
    }
}
