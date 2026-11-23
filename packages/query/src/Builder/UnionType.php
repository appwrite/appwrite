<?php

namespace Utopia\Query\Builder;

enum UnionType: string
{
    case Union = 'UNION';
    case UnionAll = 'UNION ALL';
    case Intersect = 'INTERSECT';
    case IntersectAll = 'INTERSECT ALL';
    case Except = 'EXCEPT';
    case ExceptAll = 'EXCEPT ALL';
}
