<?php

declare(strict_types=1);

namespace Utopia\Cache\Codec;

use JsonException;
use stdClass;
use Utopia\Cache\Codec;

/**
 * JSON payloads, the format every adapter has always written.
 *
 * Objects decode to associative arrays, except empty ones, which stay
 * `stdClass` so `{}` survives a round trip instead of becoming `[]`.
 */
final class Json implements Codec
{
    /**
     * @throws JsonException
     */
    public function encode(mixed $value): string
    {
        return json_encode($value, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @throws JsonException
     */
    public function decode(string $value): mixed
    {
        if (preg_match('/\{\s*\}/', $value) === 0) {
            return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        }

        return self::toAssociative(json_decode($value, flags: JSON_THROW_ON_ERROR));
    }

    private static function toAssociative(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            if ($properties === []) {
                return $value;
            }

            $value = $properties;
        }

        if (\is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::toAssociative($item);
            }
        }

        return $value;
    }
}
