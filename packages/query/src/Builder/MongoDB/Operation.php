<?php

namespace Utopia\Query\Builder\MongoDB;

enum Operation: string
{
    case InsertMany = 'insertMany';
    case UpdateMany = 'updateMany';
    case DeleteMany = 'deleteMany';
    case UpdateOne = 'updateOne';
    case Find = 'find';
    case Aggregate = 'aggregate';
}
