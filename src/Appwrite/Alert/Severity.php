<?php

namespace Appwrite\Alert;

/**
 * How urgent a monitor's alerts are, in the levels PagerDuty's Events API uses. Only critical
 * changes delivery today: its pushes ring through silent on devices that allow it.
 */
enum Severity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $severity): string => $severity->value, self::cases());
    }
}
