<?php

namespace Utopia\Query\Builder\ClickHouse;

enum AsofOperator: string
{
    case LessThan = '<';
    case LessThanEqual = '<=';
    case GreaterThan = '>';
    case GreaterThanEqual = '>=';
}
