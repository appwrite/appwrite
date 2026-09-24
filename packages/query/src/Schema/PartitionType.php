<?php

namespace Utopia\Query\Schema;

enum PartitionType: string
{
    case Range = 'RANGE';
    case List = 'LIST';
    case Hash = 'HASH';
}
