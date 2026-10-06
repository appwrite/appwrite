<?php

namespace Utopia\Query\Schema\ClickHouse;

enum IndexAlgorithm: string
{
    case MinMax = 'minmax';
    case Set = 'set';
    case BloomFilter = 'bloom_filter';
    case NgramBloomFilter = 'ngrambf_v1';
    case TokenBloomFilter = 'tokenbf_v1';
    case Inverted = 'inverted';
}
