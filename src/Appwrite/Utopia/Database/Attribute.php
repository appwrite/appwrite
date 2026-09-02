<?php

namespace Appwrite\Utopia\Database;

use Utopia\Database\Database;
use Utopia\Query\Schema\ColumnType;

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
        ColumnType::Text->value => 65535,
        ColumnType::MediumText->value => 16777215,
        ColumnType::LongText->value => 2147483647,
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
            ColumnType::String->value,
            ColumnType::Varchar->value,
            ColumnType::Text->value,
            ColumnType::MediumText->value,
            ColumnType::LongText->value,
            ColumnType::Integer->value,
            ColumnType::BigInteger->value,
            'bigint',
            ColumnType::Double->value,
            ColumnType::Boolean->value,
            ColumnType::Datetime->value,
            ColumnType::Point->value,
            ColumnType::Linestring->value,
            ColumnType::Polygon->value,
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

        if ($type === ColumnType::BigInteger->value) {
            $type = 'bigint';
        }

        if (isset(self::FORMAT_SIZES[$type])) {
            $format = $type;
            $type = ColumnType::String->value;
        }

        $size = $attribute['size'] ?? 0;
        $size = \is_int($size) ? $size : 0;

        if (isset(self::SIZES[$type])) {
            // Fixed width types ignore any size the caller sent.
            $size = self::SIZES[$type];
        } elseif ($size < 1) {
            $size = self::FORMAT_SIZES[$format] ?? $size;
        }

        return [
            'type' => $type,
            'format' => $format,
            'size' => $size,
        ];
    }
}
