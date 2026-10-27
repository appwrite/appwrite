<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Generation;

/**
 * What a value may contain for a code generator to print it unescaped, as an
 * identifier or between quotes, and still have it read as that value.
 */
enum Rule
{
    case Name;
    case Field;
    case Path;
    case Url;
    case Title;
    case Version;
    case MediaType;
    case EnumValue;
    case EnumName;

    public function accepts(string $value): bool
    {
        return match ($this) {
            self::Name => preg_match('/^[A-Za-z0-9_-]+$/D', $value) === 1,
            self::Field => preg_match('/^\$?[A-Za-z0-9_-]+$/D', $value) === 1,
            self::Path => preg_match('/^\/[A-Za-z0-9_\/{}.~:@!()+,;=-]*$/D', $value) === 1,
            self::Url => preg_match('/^https?:\/\/[^\s"\'`\\\\$\p{Cc}\x{2028}\x{2029}]+$/Du', $value) === 1,
            self::Title => preg_match('/^[A-Za-z0-9 _-]+$/D', $value) === 1,
            self::Version => preg_match('/^[A-Za-z0-9.+_-]*$/D', $value) === 1,
            self::MediaType => preg_match('/^(?:[A-Za-z0-9!#&*+.^_|~-]+\/[A-Za-z0-9!#&*+.^_|~-]+(?: *; *[A-Za-z0-9!#&*+.^_|~-]+=[A-Za-z0-9!#&*+.^_|~-]+)*)?$/D', $value) === 1,
            self::EnumValue => preg_match('/["\'`\\\\$]|[\p{Cc}\x{2028}\x{2029}]/u', $value) === 0,
            self::EnumName => preg_match('/[\p{Cc}\x{2028}\x{2029}]/u', $value) === 0,
        };
    }
}
