<?php

namespace Appwrite\Utopia\Database;

use Utopia\Database\Database;

/**
 * Shared definition of the attribute types the API exposes.
 *
 * The dedicated per-attribute endpoints hardcode a type, a format and a size
 * each. This holds the same mapping so the inline `attributes`/`columns` array
 * on create collection/table produces identical attribute documents.
 */
class Attribute
{
    /**
     * Types whose size is fixed by the type itself.
     *
     * @var array<string, int>
     */
    public const SIZES = [
        Database::VAR_TEXT => 65535,
        Database::VAR_MEDIUMTEXT => 16777215,
        Database::VAR_LONGTEXT => 2147483647,
        // Bytes, the widths createBigIntColumn and createFloatColumn hardcode.
        // Every adapter maps these two types without consulting the size.
        Database::VAR_BIGINT => 8,
        Database::VAR_FLOAT => 0,
    ];

    /**
     * String formats, mapped to the size their dedicated endpoint uses. Each
     * format is also accepted as a shorthand type, mirroring the endpoints
     * (`createEmailColumn` and friends) that create a string with that format.
     *
     * @var array<string, int>
     */
    public const FORMAT_SIZES = [
        APP_DATABASE_ATTRIBUTE_EMAIL => 254,
        APP_DATABASE_ATTRIBUTE_ENUM => Database::LENGTH_KEY,
        APP_DATABASE_ATTRIBUTE_IP => 39,
        APP_DATABASE_ATTRIBUTE_URL => 2000,
    ];

    /**
     * Types accepted in an inline definition, including the format shorthands.
     *
     * @return array<string>
     */
    public static function types(): array
    {
        return [
            Database::VAR_STRING,
            Database::VAR_VARCHAR,
            Database::VAR_TEXT,
            Database::VAR_MEDIUMTEXT,
            Database::VAR_LONGTEXT,
            Database::VAR_INTEGER,
            Database::VAR_BIGINT,
            Database::VAR_FLOAT,
            Database::VAR_BOOLEAN,
            Database::VAR_DATETIME,
            Database::VAR_POINT,
            Database::VAR_LINESTRING,
            Database::VAR_POLYGON,
            ...\array_keys(self::FORMAT_SIZES),
        ];
    }

    /**
     * Resolve an inline definition into the type, format and size stored on the
     * attribute document. A format shorthand becomes a string of that format,
     * and a size is filled in from the type or format when the caller omits it.
     *
     * @param array<string, mixed> $attribute
     * @return array{type: string, format: string, size: int}
     */
    public static function resolve(array $attribute): array
    {
        $type = $attribute['type'] ?? '';
        $format = $attribute['format'] ?? '';

        if (isset(self::FORMAT_SIZES[$type])) {
            $format = $type;
            $type = Database::VAR_STRING;
        }

        $size = $attribute['size'] ?? 0;
        $size = \is_int($size) ? $size : 0;

        if (isset(self::SIZES[$type])) {
            // Fixed width types ignore any size the caller sent.
            $size = self::SIZES[$type];
        } elseif ($size < 1) {
            $size = self::FORMAT_SIZES[$format] ?? $size;
        }

        if ($type === Database::VAR_INTEGER) {
            // Same width createIntegerColumn picks. That endpoint takes no size at
            // all, so a size sent inline is ignored rather than left to promise a
            // range the column cannot hold: the 4 byte column only holds a range
            // that fits INT32, and a bound left out means the int64 edge, which
            // does not.
            $min = $attribute['min'] ?? \PHP_INT_MIN;
            $max = $attribute['max'] ?? \PHP_INT_MAX;
            $fitsInt32 = \is_int($min) && \is_int($max) && $min >= -2147483648 && $max <= 2147483647;
            $size = $fitsInt32 ? 4 : 8;
        }

        return [
            'type' => $type,
            'format' => $format,
            'size' => $size,
        ];
    }
}
