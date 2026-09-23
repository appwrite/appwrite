<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Parser;

use Utopia\OpenAPI\Exception\InvalidSpecification;

/**
 * Shape checks against a decoded document. Every failure names the JSON Pointer
 * location it was reading, so errors can be traced back into the source.
 */
final class Value
{
    /** @return array<string, mixed> */
    public static function object(mixed $value, string $location): array
    {
        if ($value instanceof \stdClass) {
            return (array) $value;
        }
        if (! \is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidSpecification("Expected an object at {$location}");
        }

        return $value;
    }

    /** @return list<mixed> */
    public static function list(mixed $value, string $location): array
    {
        if (! \is_array($value) || ! array_is_list($value)) {
            throw new InvalidSpecification("Expected a list at {$location}");
        }

        return $value;
    }

    /** @return list<string> */
    public static function stringList(mixed $value, string $location): array
    {
        $values = self::list($value, $location);
        foreach ($values as $index => $item) {
            if (! \is_string($item)) {
                throw new InvalidSpecification("Expected string at {$location}/{$index}");
            }
        }

        return $values;
    }

    /** @param array<string, mixed> $data */
    public static function requiredString(array $data, string $key, string $location): string
    {
        if (! \array_key_exists($key, $data) || ! \is_string($data[$key])) {
            throw new InvalidSpecification("Expected string {$location}/{$key}");
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    public static function optionalString(array $data, string $key): ?string
    {
        if (! \array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }
        if (! \is_string($data[$key])) {
            throw new InvalidSpecification("Expected '{$key}' to be a string");
        }

        return $data[$key];
    }

    public static function nullableInt(mixed $value, string $location): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! \is_int($value)) {
            throw new InvalidSpecification("Expected integer at {$location}");
        }

        return $value;
    }

    public static function nullableNumber(mixed $value, string $location): int|float|null
    {
        if ($value === null) {
            return null;
        }
        if (! \is_int($value) && ! \is_float($value)) {
            throw new InvalidSpecification("Expected number at {$location}");
        }

        return $value;
    }

    /**
     * @param  array<int|string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function extensions(array $data): array
    {
        return array_filter(
            $data,
            static fn(string|int $key): bool => \is_string($key) && str_starts_with(strtolower($key), 'x-'),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
