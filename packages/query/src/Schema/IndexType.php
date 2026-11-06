<?php

namespace Utopia\Query\Schema;

enum IndexType: string
{
    case Key = 'key';
    case Index = 'index';
    case Unique = 'unique';
    case Fulltext = 'fulltext';
    case Spatial = 'spatial';
    case Object = 'object';
    case HnswEuclidean = 'hnsw_euclidean';
    case HnswCosine = 'hnsw_cosine';
    case HnswDot = 'hnsw_dot';
    case Trigram = 'trigram';
    case Ttl = 'ttl';
}
