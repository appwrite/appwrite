//! PHP `Utopia\Query\Method`.

use std::fmt::{Display, Formatter};

use serde::{Deserialize, Serialize};

/// Query method names. PHP backed enum `Method: string`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub enum Method {
    Equal,
    NotEqual,
    LessThan,
    LessThanEqual,
    GreaterThan,
    GreaterThanEqual,
    Contains,
    ContainsAny,
    NotContains,
    Search,
    NotSearch,
    IsNull,
    IsNotNull,
    Between,
    NotBetween,
    StartsWith,
    NotStartsWith,
    EndsWith,
    NotEndsWith,
    Regex,
    Exists,
    NotExists,
    Crosses,
    NotCrosses,
    DistanceEqual,
    DistanceNotEqual,
    DistanceGreaterThan,
    DistanceLessThan,
    Intersects,
    NotIntersects,
    Overlaps,
    NotOverlaps,
    Touches,
    NotTouches,
    VectorDot,
    VectorCosine,
    VectorEuclidean,
    Select,
    OrderDesc,
    OrderAsc,
    OrderRandom,
    Limit,
    Offset,
    CursorAfter,
    CursorBefore,
    And,
    Or,
    ContainsAll,
    ElemMatch,
    Count,
    CountDistinct,
    Sum,
    Avg,
    Min,
    Max,
    Stddev,
    StddevPop,
    StddevSamp,
    Variance,
    VarPop,
    VarSamp,
    BitAnd,
    BitOr,
    BitXor,
    GroupBy,
    GroupByTimeBucket,
    Having,
    Distinct,
    Join,
    LeftJoin,
    RightJoin,
    CrossJoin,
    FullOuterJoin,
    NaturalJoin,
    Union,
    UnionAll,
    JsonContains,
    JsonNotContains,
    JsonOverlaps,
    JsonPath,
    OrderVectorDistance,
    Covers,
    NotCovers,
    SpatialEquals,
    NotSpatialEquals,
    Raw,
}

impl Method {
    pub const fn as_str(self) -> &'static str {
        match self {
            Self::Equal => "equal",
            Self::NotEqual => "notEqual",
            Self::LessThan => "lessThan",
            Self::LessThanEqual => "lessThanEqual",
            Self::GreaterThan => "greaterThan",
            Self::GreaterThanEqual => "greaterThanEqual",
            Self::Contains => "contains",
            Self::ContainsAny => "containsAny",
            Self::NotContains => "notContains",
            Self::Search => "search",
            Self::NotSearch => "notSearch",
            Self::IsNull => "isNull",
            Self::IsNotNull => "isNotNull",
            Self::Between => "between",
            Self::NotBetween => "notBetween",
            Self::StartsWith => "startsWith",
            Self::NotStartsWith => "notStartsWith",
            Self::EndsWith => "endsWith",
            Self::NotEndsWith => "notEndsWith",
            Self::Regex => "regex",
            Self::Exists => "exists",
            Self::NotExists => "notExists",
            Self::Crosses => "crosses",
            Self::NotCrosses => "notCrosses",
            Self::DistanceEqual => "distanceEqual",
            Self::DistanceNotEqual => "distanceNotEqual",
            Self::DistanceGreaterThan => "distanceGreaterThan",
            Self::DistanceLessThan => "distanceLessThan",
            Self::Intersects => "intersects",
            Self::NotIntersects => "notIntersects",
            Self::Overlaps => "overlaps",
            Self::NotOverlaps => "notOverlaps",
            Self::Touches => "touches",
            Self::NotTouches => "notTouches",
            Self::VectorDot => "vectorDot",
            Self::VectorCosine => "vectorCosine",
            Self::VectorEuclidean => "vectorEuclidean",
            Self::Select => "select",
            Self::OrderDesc => "orderDesc",
            Self::OrderAsc => "orderAsc",
            Self::OrderRandom => "orderRandom",
            Self::Limit => "limit",
            Self::Offset => "offset",
            Self::CursorAfter => "cursorAfter",
            Self::CursorBefore => "cursorBefore",
            Self::And => "and",
            Self::Or => "or",
            Self::ContainsAll => "containsAll",
            Self::ElemMatch => "elemMatch",
            Self::Count => "count",
            Self::CountDistinct => "countDistinct",
            Self::Sum => "sum",
            Self::Avg => "avg",
            Self::Min => "min",
            Self::Max => "max",
            Self::Stddev => "stddev",
            Self::StddevPop => "stddevPop",
            Self::StddevSamp => "stddevSamp",
            Self::Variance => "variance",
            Self::VarPop => "varPop",
            Self::VarSamp => "varSamp",
            Self::BitAnd => "bitAnd",
            Self::BitOr => "bitOr",
            Self::BitXor => "bitXor",
            Self::GroupBy => "groupBy",
            Self::GroupByTimeBucket => "groupByTimeBucket",
            Self::Having => "having",
            Self::Distinct => "distinct",
            Self::Join => "join",
            Self::LeftJoin => "leftJoin",
            Self::RightJoin => "rightJoin",
            Self::CrossJoin => "crossJoin",
            Self::FullOuterJoin => "fullOuterJoin",
            Self::NaturalJoin => "naturalJoin",
            Self::Union => "union",
            Self::UnionAll => "unionAll",
            Self::JsonContains => "jsonContains",
            Self::JsonNotContains => "jsonNotContains",
            Self::JsonOverlaps => "jsonOverlaps",
            Self::JsonPath => "jsonPath",
            Self::OrderVectorDistance => "orderVectorDistance",
            Self::Covers => "covers",
            Self::NotCovers => "notCovers",
            Self::SpatialEquals => "spatialEquals",
            Self::NotSpatialEquals => "notSpatialEquals",
            Self::Raw => "raw",
        }
    }

    /// PHP `Method::from($value)`.
    pub fn from_value(value: &str) -> Result<Self, String> {
        Self::try_from_value(value).ok_or_else(|| value.to_owned())
    }

    /// PHP `Method::tryFrom($value)`.
    pub fn try_from_value(value: &str) -> Option<Self> {
        Some(match value {
            "equal" => Self::Equal,
            "notEqual" => Self::NotEqual,
            "lessThan" => Self::LessThan,
            "lessThanEqual" => Self::LessThanEqual,
            "greaterThan" => Self::GreaterThan,
            "greaterThanEqual" => Self::GreaterThanEqual,
            "contains" => Self::Contains,
            "containsAny" => Self::ContainsAny,
            "notContains" => Self::NotContains,
            "search" => Self::Search,
            "notSearch" => Self::NotSearch,
            "isNull" => Self::IsNull,
            "isNotNull" => Self::IsNotNull,
            "between" => Self::Between,
            "notBetween" => Self::NotBetween,
            "startsWith" => Self::StartsWith,
            "notStartsWith" => Self::NotStartsWith,
            "endsWith" => Self::EndsWith,
            "notEndsWith" => Self::NotEndsWith,
            "regex" => Self::Regex,
            "exists" => Self::Exists,
            "notExists" => Self::NotExists,
            "crosses" => Self::Crosses,
            "notCrosses" => Self::NotCrosses,
            "distanceEqual" => Self::DistanceEqual,
            "distanceNotEqual" => Self::DistanceNotEqual,
            "distanceGreaterThan" => Self::DistanceGreaterThan,
            "distanceLessThan" => Self::DistanceLessThan,
            "intersects" => Self::Intersects,
            "notIntersects" => Self::NotIntersects,
            "overlaps" => Self::Overlaps,
            "notOverlaps" => Self::NotOverlaps,
            "touches" => Self::Touches,
            "notTouches" => Self::NotTouches,
            "vectorDot" => Self::VectorDot,
            "vectorCosine" => Self::VectorCosine,
            "vectorEuclidean" => Self::VectorEuclidean,
            "select" => Self::Select,
            "orderDesc" => Self::OrderDesc,
            "orderAsc" => Self::OrderAsc,
            "orderRandom" => Self::OrderRandom,
            "limit" => Self::Limit,
            "offset" => Self::Offset,
            "cursorAfter" => Self::CursorAfter,
            "cursorBefore" => Self::CursorBefore,
            "and" => Self::And,
            "or" => Self::Or,
            "containsAll" => Self::ContainsAll,
            "elemMatch" => Self::ElemMatch,
            "count" => Self::Count,
            "countDistinct" => Self::CountDistinct,
            "sum" => Self::Sum,
            "avg" => Self::Avg,
            "min" => Self::Min,
            "max" => Self::Max,
            "stddev" => Self::Stddev,
            "stddevPop" => Self::StddevPop,
            "stddevSamp" => Self::StddevSamp,
            "variance" => Self::Variance,
            "varPop" => Self::VarPop,
            "varSamp" => Self::VarSamp,
            "bitAnd" => Self::BitAnd,
            "bitOr" => Self::BitOr,
            "bitXor" => Self::BitXor,
            "groupBy" => Self::GroupBy,
            "groupByTimeBucket" => Self::GroupByTimeBucket,
            "having" => Self::Having,
            "distinct" => Self::Distinct,
            "join" => Self::Join,
            "leftJoin" => Self::LeftJoin,
            "rightJoin" => Self::RightJoin,
            "crossJoin" => Self::CrossJoin,
            "fullOuterJoin" => Self::FullOuterJoin,
            "naturalJoin" => Self::NaturalJoin,
            "union" => Self::Union,
            "unionAll" => Self::UnionAll,
            "jsonContains" => Self::JsonContains,
            "jsonNotContains" => Self::JsonNotContains,
            "jsonOverlaps" => Self::JsonOverlaps,
            "jsonPath" => Self::JsonPath,
            "orderVectorDistance" => Self::OrderVectorDistance,
            "covers" => Self::Covers,
            "notCovers" => Self::NotCovers,
            "spatialEquals" => Self::SpatialEquals,
            "notSpatialEquals" => Self::NotSpatialEquals,
            "raw" => Self::Raw,
            _ => return None,
        })
    }

    /// PHP `Method::cases()`.
    pub const fn cases() -> &'static [Self] {
        &[
            Self::Equal,
            Self::NotEqual,
            Self::LessThan,
            Self::LessThanEqual,
            Self::GreaterThan,
            Self::GreaterThanEqual,
            Self::Contains,
            Self::ContainsAny,
            Self::NotContains,
            Self::Search,
            Self::NotSearch,
            Self::IsNull,
            Self::IsNotNull,
            Self::Between,
            Self::NotBetween,
            Self::StartsWith,
            Self::NotStartsWith,
            Self::EndsWith,
            Self::NotEndsWith,
            Self::Regex,
            Self::Exists,
            Self::NotExists,
            Self::Crosses,
            Self::NotCrosses,
            Self::DistanceEqual,
            Self::DistanceNotEqual,
            Self::DistanceGreaterThan,
            Self::DistanceLessThan,
            Self::Intersects,
            Self::NotIntersects,
            Self::Overlaps,
            Self::NotOverlaps,
            Self::Touches,
            Self::NotTouches,
            Self::VectorDot,
            Self::VectorCosine,
            Self::VectorEuclidean,
            Self::Select,
            Self::OrderDesc,
            Self::OrderAsc,
            Self::OrderRandom,
            Self::Limit,
            Self::Offset,
            Self::CursorAfter,
            Self::CursorBefore,
            Self::And,
            Self::Or,
            Self::ContainsAll,
            Self::ElemMatch,
            Self::Count,
            Self::CountDistinct,
            Self::Sum,
            Self::Avg,
            Self::Min,
            Self::Max,
            Self::Stddev,
            Self::StddevPop,
            Self::StddevSamp,
            Self::Variance,
            Self::VarPop,
            Self::VarSamp,
            Self::BitAnd,
            Self::BitOr,
            Self::BitXor,
            Self::GroupBy,
            Self::GroupByTimeBucket,
            Self::Having,
            Self::Distinct,
            Self::Join,
            Self::LeftJoin,
            Self::RightJoin,
            Self::CrossJoin,
            Self::FullOuterJoin,
            Self::NaturalJoin,
            Self::Union,
            Self::UnionAll,
            Self::JsonContains,
            Self::JsonNotContains,
            Self::JsonOverlaps,
            Self::JsonPath,
            Self::OrderVectorDistance,
            Self::Covers,
            Self::NotCovers,
            Self::SpatialEquals,
            Self::NotSpatialEquals,
            Self::Raw,
        ]
    }

    pub fn is_filter(self) -> bool {
        matches!(
            self,
            Self::Equal
                | Self::NotEqual
                | Self::LessThan
                | Self::LessThanEqual
                | Self::GreaterThan
                | Self::GreaterThanEqual
                | Self::Contains
                | Self::ContainsAny
                | Self::NotContains
                | Self::Search
                | Self::NotSearch
                | Self::IsNull
                | Self::IsNotNull
                | Self::Between
                | Self::NotBetween
                | Self::StartsWith
                | Self::NotStartsWith
                | Self::EndsWith
                | Self::NotEndsWith
                | Self::Regex
                | Self::Exists
                | Self::NotExists
        )
    }

    pub fn is_spatial(self) -> bool {
        matches!(
            self,
            Self::Crosses
                | Self::NotCrosses
                | Self::DistanceEqual
                | Self::DistanceNotEqual
                | Self::DistanceGreaterThan
                | Self::DistanceLessThan
                | Self::Intersects
                | Self::NotIntersects
                | Self::Overlaps
                | Self::NotOverlaps
                | Self::Touches
                | Self::NotTouches
                | Self::Covers
                | Self::NotCovers
                | Self::SpatialEquals
                | Self::NotSpatialEquals
        )
    }

    pub fn is_vector(self) -> bool {
        matches!(
            self,
            Self::VectorDot | Self::VectorCosine | Self::VectorEuclidean
        )
    }

    pub fn is_json(self) -> bool {
        matches!(
            self,
            Self::JsonContains | Self::JsonNotContains | Self::JsonOverlaps | Self::JsonPath
        )
    }

    pub fn is_nested(self) -> bool {
        matches!(
            self,
            Self::And | Self::Or | Self::ElemMatch | Self::Having | Self::Union | Self::UnionAll
        )
    }

    pub fn is_aggregate(self) -> bool {
        matches!(
            self,
            Self::Count
                | Self::CountDistinct
                | Self::Sum
                | Self::Avg
                | Self::Min
                | Self::Max
                | Self::Stddev
                | Self::StddevPop
                | Self::StddevSamp
                | Self::Variance
                | Self::VarPop
                | Self::VarSamp
                | Self::BitAnd
                | Self::BitOr
                | Self::BitXor
        )
    }

    pub fn is_join(self) -> bool {
        matches!(
            self,
            Self::Join
                | Self::LeftJoin
                | Self::RightJoin
                | Self::CrossJoin
                | Self::FullOuterJoin
                | Self::NaturalJoin
        )
    }

    pub fn sql_function(self) -> Option<&'static str> {
        Some(match self {
            Self::Sum => "SUM",
            Self::Count | Self::CountDistinct => "COUNT",
            Self::Avg => "AVG",
            Self::Min => "MIN",
            Self::Max => "MAX",
            Self::Stddev => "STDDEV",
            Self::StddevPop => "STDDEV_POP",
            Self::StddevSamp => "STDDEV_SAMP",
            Self::Variance => "VARIANCE",
            Self::VarPop => "VAR_POP",
            Self::VarSamp => "VAR_SAMP",
            Self::BitAnd => "BIT_AND",
            Self::BitOr => "BIT_OR",
            Self::BitXor => "BIT_XOR",
            _ => return None,
        })
    }
}

impl Display for Method {
    fn fmt(&self, f: &mut Formatter<'_>) -> std::fmt::Result {
        f.write_str(self.as_str())
    }
}

impl TryFrom<&str> for Method {
    type Error = String;

    fn try_from(value: &str) -> Result<Self, Self::Error> {
        Self::from_value(value)
    }
}
