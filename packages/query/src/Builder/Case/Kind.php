<?php

namespace Utopia\Query\Builder\Case;

enum Kind: string
{
    case Comparison = 'comparison';
    case Null = 'null';
    case NotNull = 'notNull';
    case In = 'in';
    case Raw = 'raw';
}
