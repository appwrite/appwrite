<?php

namespace Utopia\Query\Schema;

enum ParameterDirection: string
{
    case In = 'IN';
    case Out = 'OUT';
    case InOut = 'INOUT';
}
