<?php

namespace Appwrite\Schedule;

enum Interval: string
{
    case OneMinute = '1m';
    case FiveMinutes = '5m';
    case FifteenMinutes = '15m';
    case ThirtyMinutes = '30m';
    case OneHour = '1h';
    case SixHours = '6h';
    case TwelveHours = '12h';
    case OneDay = '1d';

    public function seconds(): int
    {
        return match ($this) {
            self::OneMinute => 60,
            self::FiveMinutes => 300,
            self::FifteenMinutes => 900,
            self::ThirtyMinutes => 1800,
            self::OneHour => 3600,
            self::SixHours => 21600,
            self::TwelveHours => 43200,
            self::OneDay => 86400,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_column(self::cases(), 'value');
    }

    /**
     * @return array<string, string> value => case name, for SDK enum generation
     */
    public static function names(): array
    {
        return \array_column(self::cases(), 'name', 'value');
    }
}
