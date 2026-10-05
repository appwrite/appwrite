<?php

namespace Utopia\Query\Builder;

enum JoinType: string
{
    case Inner = 'JOIN';
    case Left = 'LEFT JOIN';
    case Right = 'RIGHT JOIN';
    case Cross = 'CROSS JOIN';
    case FullOuter = 'FULL OUTER JOIN';
    case Natural = 'NATURAL JOIN';
}
