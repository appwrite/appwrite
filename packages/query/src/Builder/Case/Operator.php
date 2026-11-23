<?php

namespace Utopia\Query\Builder\Case;

enum Operator: string
{
    case Equal = 'equal';
    case NotEqual = 'notEqual';
    case LessThan = 'lessThan';
    case LessThanEqual = 'lessThanEqual';
    case GreaterThan = 'greaterThan';
    case GreaterThanEqual = 'greaterThanEqual';

    public function sqlOperator(): string
    {
        return match ($this) {
            self::Equal => '=',
            self::NotEqual => '!=',
            self::LessThan => '<',
            self::LessThanEqual => '<=',
            self::GreaterThan => '>',
            self::GreaterThanEqual => '>=',
        };
    }
}
