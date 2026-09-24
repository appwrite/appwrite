<?php

namespace Utopia\Query;

enum OrderDirection: string
{
    case Asc = 'ASC';
    case Desc = 'DESC';
    case Random = 'RANDOM';
}
