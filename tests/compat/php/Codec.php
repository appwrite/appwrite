<?php

namespace Tests\Compat;

/**
 * JSON <-> PHP values, the same way on every request.
 *
 * Decoding follows Utopia\Http\Request::decodePayload(): objects become
 * associative arrays except empty ones, which stay stdClass. Tagged values
 * carry what JSON cannot: {"$bytes": "<base64>"} is a binary string and
 * {"$float": "INF" | "-INF" | "NAN"} a non-finite float.
 */
final class Codec
{
    public static function decode(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = (array) $value;
            if ($properties === []) {
                return $value;
            }
            if (\count($properties) === 1 && isset($properties['$bytes']) && \is_string($properties['$bytes'])) {
                $bytes = base64_decode($properties['$bytes'], true);

                return $bytes === false ? throw new Fault('invalid $bytes') : $bytes;
            }
            if (\count($properties) === 1 && isset($properties['$float']) && \is_string($properties['$float'])) {
                return match ($properties['$float']) {
                    'INF' => INF,
                    '-INF' => -INF,
                    'NAN' => NAN,
                    default => throw new Fault('invalid $float'),
                };
            }

            return array_map(self::decode(...), $properties);
        }
        if (\is_array($value)) {
            return array_map(self::decode(...), $value);
        }

        return $value;
    }

    public static function encode(mixed $value): mixed
    {
        if (\is_string($value)) {
            return mb_check_encoding($value, 'UTF-8') ? $value : ['$bytes' => base64_encode($value)];
        }
        if (\is_float($value) && !is_finite($value)) {
            return ['$float' => is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF')];
        }
        if (\is_array($value)) {
            return array_map(self::encode(...), $value);
        }
        if ($value instanceof \stdClass) {
            $properties = (array) $value;

            return $properties === [] ? $value : (object) array_map(self::encode(...), $properties);
        }
        if ($value instanceof \JsonSerializable) {
            return self::encode($value->jsonSerialize());
        }
        if (\is_object($value) || \is_resource($value)) {
            throw new Fault('adapter returned ' . get_debug_type($value) . '; return plain values or a handle');
        }

        return $value;
    }

    public static function line(mixed $reply): string
    {
        return json_encode(
            $reply,
            JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . "\n";
    }
}
