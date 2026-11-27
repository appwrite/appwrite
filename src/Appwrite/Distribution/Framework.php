<?php

namespace Appwrite\Distribution;

enum Framework: string
{
    case Flutter = 'flutter';
    case ReactNative = 'react-native';
    case Android = 'android';
    case Ios = 'ios';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $framework): string => $framework->value, self::cases());
    }
}
