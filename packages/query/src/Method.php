<?php

namespace Utopia\Query;

enum Method: string
{
    // Filter methods
    case Equal = 'equal';
    case NotEqual = 'notEqual';
    case LessThan = 'lessThan';
    case LessThanEqual = 'lessThanEqual';
    case GreaterThan = 'greaterThan';
    case GreaterThanEqual = 'greaterThanEqual';
    case Contains = 'contains';
    case ContainsAny = 'containsAny';
    case NotContains = 'notContains';
    case Search = 'search';
    case NotSearch = 'notSearch';
    case IsNull = 'isNull';
    case IsNotNull = 'isNotNull';
    case Between = 'between';
    case NotBetween = 'notBetween';
    case StartsWith = 'startsWith';
    case NotStartsWith = 'notStartsWith';
    case EndsWith = 'endsWith';
    case NotEndsWith = 'notEndsWith';
    case Regex = 'regex';
    case Exists = 'exists';
    case NotExists = 'notExists';

    // Spatial methods
    case Crosses = 'crosses';
    case NotCrosses = 'notCrosses';
    case DistanceEqual = 'distanceEqual';
    case DistanceNotEqual = 'distanceNotEqual';
    case DistanceGreaterThan = 'distanceGreaterThan';
    case DistanceLessThan = 'distanceLessThan';
    case Intersects = 'intersects';
    case NotIntersects = 'notIntersects';
    case Overlaps = 'overlaps';
    case NotOverlaps = 'notOverlaps';
    case Touches = 'touches';
    case NotTouches = 'notTouches';

    // Vector query methods
    case VectorDot = 'vectorDot';
    case VectorCosine = 'vectorCosine';
    case VectorEuclidean = 'vectorEuclidean';

    case Select = 'select';

    // Order methods
    case OrderDesc = 'orderDesc';
    case OrderAsc = 'orderAsc';
    case OrderRandom = 'orderRandom';

    // Pagination methods
    case Limit = 'limit';
    case Offset = 'offset';
    case CursorAfter = 'cursorAfter';
    case CursorBefore = 'cursorBefore';

    // Logical methods
    case And = 'and';
    case Or = 'or';
    case ContainsAll = 'containsAll';
    case ElemMatch = 'elemMatch';

    // Aggregation methods
    case Count = 'count';
    case CountDistinct = 'countDistinct';
    case Sum = 'sum';
    case Avg = 'avg';
    case Min = 'min';
    case Max = 'max';
    case Stddev = 'stddev';
    case StddevPop = 'stddevPop';
    case StddevSamp = 'stddevSamp';
    case Variance = 'variance';
    case VarPop = 'varPop';
    case VarSamp = 'varSamp';
    case BitAnd = 'bitAnd';
    case BitOr = 'bitOr';
    case BitXor = 'bitXor';
    case GroupBy = 'groupBy';
    case GroupByTimeBucket = 'groupByTimeBucket';
    case Having = 'having';

    // Distinct
    case Distinct = 'distinct';

    // Join methods
    case Join = 'join';
    case LeftJoin = 'leftJoin';
    case RightJoin = 'rightJoin';
    case CrossJoin = 'crossJoin';
    case FullOuterJoin = 'fullOuterJoin';
    case NaturalJoin = 'naturalJoin';
    case On = 'on';

    // Union
    case Union = 'union';
    case UnionAll = 'unionAll';

    // JSON filter methods
    case JsonContains = 'jsonContains';
    case JsonNotContains = 'jsonNotContains';
    case JsonOverlaps = 'jsonOverlaps';
    case JsonPath = 'jsonPath';

    // Vector ordering
    case OrderVectorDistance = 'orderVectorDistance';

    // Spatial predicate extras
    case Covers = 'covers';
    case NotCovers = 'notCovers';
    case SpatialEquals = 'spatialEquals';
    case NotSpatialEquals = 'notSpatialEquals';

    // Raw
    case Raw = 'raw';

    public function isFilter(): bool
    {
        return match ($this) {
            self::Equal,
            self::NotEqual,
            self::LessThan,
            self::LessThanEqual,
            self::GreaterThan,
            self::GreaterThanEqual,
            self::Contains,
            self::ContainsAny,
            self::NotContains,
            self::Search,
            self::NotSearch,
            self::IsNull,
            self::IsNotNull,
            self::Between,
            self::NotBetween,
            self::StartsWith,
            self::NotStartsWith,
            self::EndsWith,
            self::NotEndsWith,
            self::Regex,
            self::Exists,
            self::NotExists => true,
            default => false,
        };
    }

    public function isSpatial(): bool
    {
        return match ($this) {
            self::Crosses,
            self::NotCrosses,
            self::DistanceEqual,
            self::DistanceNotEqual,
            self::DistanceGreaterThan,
            self::DistanceLessThan,
            self::Intersects,
            self::NotIntersects,
            self::Overlaps,
            self::NotOverlaps,
            self::Touches,
            self::NotTouches,
            self::Covers,
            self::NotCovers,
            self::SpatialEquals,
            self::NotSpatialEquals => true,
            default => false,
        };
    }

    public function isVector(): bool
    {
        return match ($this) {
            self::VectorDot,
            self::VectorCosine,
            self::VectorEuclidean => true,
            default => false,
        };
    }

    public function isJson(): bool
    {
        return match ($this) {
            self::JsonContains,
            self::JsonNotContains,
            self::JsonOverlaps,
            self::JsonPath => true,
            default => false,
        };
    }

    public function isNested(): bool
    {
        return match ($this) {
            self::And,
            self::Or,
            self::ElemMatch,
            self::Having,
            self::Union,
            self::UnionAll => true,
            default => false,
        };
    }

    public function isAggregate(): bool
    {
        return match ($this) {
            self::Count,
            self::CountDistinct,
            self::Sum,
            self::Avg,
            self::Min,
            self::Max,
            self::Stddev,
            self::StddevPop,
            self::StddevSamp,
            self::Variance,
            self::VarPop,
            self::VarSamp,
            self::BitAnd,
            self::BitOr,
            self::BitXor => true,
            default => false,
        };
    }

    public function isJoin(): bool
    {
        return match ($this) {
            self::Join,
            self::LeftJoin,
            self::RightJoin,
            self::CrossJoin,
            self::FullOuterJoin,
            self::NaturalJoin => true,
            default => false,
        };
    }

    /**
     * Return the standard SQL function name for aggregation methods,
     * or null if this method has no direct SQL-function mapping.
     */
    public function sqlFunction(): ?string
    {
        return match ($this) {
            self::Sum => 'SUM',
            self::Count => 'COUNT',
            self::CountDistinct => 'COUNT',
            self::Avg => 'AVG',
            self::Min => 'MIN',
            self::Max => 'MAX',
            self::Stddev => 'STDDEV',
            self::StddevPop => 'STDDEV_POP',
            self::StddevSamp => 'STDDEV_SAMP',
            self::Variance => 'VARIANCE',
            self::VarPop => 'VAR_POP',
            self::VarSamp => 'VAR_SAMP',
            self::BitAnd => 'BIT_AND',
            self::BitOr => 'BIT_OR',
            self::BitXor => 'BIT_XOR',
            default => null,
        };
    }
}
