<?php

namespace Utopia\Query\Builder\MongoDB;

enum PipelineStage: string
{
    case Match = '$match';
    case Group = '$group';
    case Project = '$project';
    case Sort = '$sort';
    case Skip = '$skip';
    case Limit = '$limit';
    case Lookup = '$lookup';
    case GraphLookup = '$graphLookup';
    case Facet = '$facet';
    case Bucket = '$bucket';
    case BucketAuto = '$bucketAuto';
    case SetWindowFields = '$setWindowFields';
    case Search = '$search';
    case SearchMeta = '$searchMeta';
    case VectorSearch = '$vectorSearch';
    case Sample = '$sample';
    case Text = '$text';
    case Unwind = '$unwind';
    case AddFields = '$addFields';
    case ReplaceRoot = '$replaceRoot';
    case UnionWith = '$unionWith';
    case Unset = '$unset';
    case Merge = '$merge';
    case Out = '$out';
}
