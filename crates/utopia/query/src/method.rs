//! [`Method`]: what a [`Query`](crate::Query) does.

/// Defines a string-backed enumeration the way PHP's backed enums behave:
/// `as_str()` is `->value`, `name()` is `->name`, `from()` / `try_from()`
/// parse a value, `CASES` lists them in declaration order.
macro_rules! backed_enum {
    (
        $(#[$meta:meta])*
        $vis:vis enum $name:ident: $php:literal {
            $( $(#[$vmeta:meta])* $variant:ident = $value:literal, )*
        }
    ) => {
        $(#[$meta])*
        #[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
        $vis enum $name {
            $( $(#[$vmeta])* $variant, )*
        }

        impl $name {
            /// Every case, in declaration order.
            ///
            /// PHP: `cases()`.
            pub const CASES: &'static [$name] = &[$($name::$variant),*];

            /// The PHP class of this enumeration.
            pub const PHP_CLASS: &'static str = $php;

            /// The backing value.
            ///
            /// PHP: `->value`.
            pub const fn as_str(self) -> &'static str {
                match self {
                    $($name::$variant => $value,)*
                }
            }

            /// The case name.
            ///
            /// PHP: `->name`.
            pub const fn name(self) -> &'static str {
                match self {
                    $($name::$variant => stringify!($variant),)*
                }
            }

            /// The case with backing value `value`, if any.
            ///
            /// PHP: `tryFrom()`.
            pub fn try_from_value(value: &str) -> Option<Self> {
                match value {
                    $($value => Some($name::$variant),)*
                    _ => None,
                }
            }

            /// The case with backing value `value`.
            ///
            /// PHP: `from()`, which throws `ValueError` ([`Error::InvalidValue`](crate::Error::InvalidValue)) with the same message.
            pub fn from_value(value: &str) -> Result<Self, $crate::Error> {
                Self::try_from_value(value).ok_or_else(|| {
                    $crate::Error::InvalidValue(format!(
                        "\"{}\" is not a valid backing value for enum {}",
                        value,
                        $php
                    ))
                })
            }

            /// The case named `name`, if any.
            pub fn from_name(name: &str) -> Option<Self> {
                match name {
                    $(stringify!($variant) => Some($name::$variant),)*
                    _ => None,
                }
            }
        }

        impl std::fmt::Display for $name {
            /// The backing value.
            fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
                f.write_str(self.as_str())
            }
        }
    };
}

pub(crate) use backed_enum;

backed_enum! {
    /// What a [`Query`](crate::Query) does: filter, order, paginate, select,
    /// aggregate, join, combine, or carry raw SQL.
    ///
    /// PHP: `Utopia\Query\Method`.
    pub enum Method: "Utopia\\Query\\Method" {
        /// `equal`: the attribute is one of the values.
        Equal = "equal",
        /// `notEqual`: the attribute is none of the values.
        NotEqual = "notEqual",
        /// `lessThan`.
        LessThan = "lessThan",
        /// `lessThanEqual`.
        LessThanEqual = "lessThanEqual",
        /// `greaterThan`.
        GreaterThan = "greaterThan",
        /// `greaterThanEqual`.
        GreaterThanEqual = "greaterThanEqual",
        /// `contains`: substring (or array element) match.
        Contains = "contains",
        /// `containsAny`: any of the values.
        ContainsAny = "containsAny",
        /// `notContains`.
        NotContains = "notContains",
        /// `search`: full-text search.
        Search = "search",
        /// `notSearch`.
        NotSearch = "notSearch",
        /// `isNull`.
        IsNull = "isNull",
        /// `isNotNull`.
        IsNotNull = "isNotNull",
        /// `between`.
        Between = "between",
        /// `notBetween`.
        NotBetween = "notBetween",
        /// `startsWith`.
        StartsWith = "startsWith",
        /// `notStartsWith`.
        NotStartsWith = "notStartsWith",
        /// `endsWith`.
        EndsWith = "endsWith",
        /// `notEndsWith`.
        NotEndsWith = "notEndsWith",
        /// `regex`.
        Regex = "regex",
        /// `exists`: the attributes are present.
        Exists = "exists",
        /// `notExists`.
        NotExists = "notExists",
        /// `crosses` (spatial).
        Crosses = "crosses",
        /// `notCrosses` (spatial).
        NotCrosses = "notCrosses",
        /// `distanceEqual` (spatial).
        DistanceEqual = "distanceEqual",
        /// `distanceNotEqual` (spatial).
        DistanceNotEqual = "distanceNotEqual",
        /// `distanceGreaterThan` (spatial).
        DistanceGreaterThan = "distanceGreaterThan",
        /// `distanceLessThan` (spatial).
        DistanceLessThan = "distanceLessThan",
        /// `intersects` (spatial).
        Intersects = "intersects",
        /// `notIntersects` (spatial).
        NotIntersects = "notIntersects",
        /// `overlaps` (spatial).
        Overlaps = "overlaps",
        /// `notOverlaps` (spatial).
        NotOverlaps = "notOverlaps",
        /// `touches` (spatial).
        Touches = "touches",
        /// `notTouches` (spatial).
        NotTouches = "notTouches",
        /// `vectorDot` (vector similarity).
        VectorDot = "vectorDot",
        /// `vectorCosine` (vector similarity).
        VectorCosine = "vectorCosine",
        /// `vectorEuclidean` (vector similarity).
        VectorEuclidean = "vectorEuclidean",
        /// `select`: the columns to return.
        Select = "select",
        /// `orderDesc`.
        OrderDesc = "orderDesc",
        /// `orderAsc`.
        OrderAsc = "orderAsc",
        /// `orderRandom`.
        OrderRandom = "orderRandom",
        /// `limit`.
        Limit = "limit",
        /// `offset`.
        Offset = "offset",
        /// `cursorAfter`: page after a row.
        CursorAfter = "cursorAfter",
        /// `cursorBefore`: page before a row.
        CursorBefore = "cursorBefore",
        /// `and`.
        And = "and",
        /// `or`.
        Or = "or",
        /// `containsAll`.
        ContainsAll = "containsAll",
        /// `elemMatch`: an array element matches every nested query.
        ElemMatch = "elemMatch",
        /// `count`.
        Count = "count",
        /// `countDistinct`.
        CountDistinct = "countDistinct",
        /// `sum`.
        Sum = "sum",
        /// `avg`.
        Avg = "avg",
        /// `min`.
        Min = "min",
        /// `max`.
        Max = "max",
        /// `stddev`.
        Stddev = "stddev",
        /// `stddevPop`.
        StddevPop = "stddevPop",
        /// `stddevSamp`.
        StddevSamp = "stddevSamp",
        /// `variance`.
        Variance = "variance",
        /// `varPop`.
        VarPop = "varPop",
        /// `varSamp`.
        VarSamp = "varSamp",
        /// `bitAnd`.
        BitAnd = "bitAnd",
        /// `bitOr`.
        BitOr = "bitOr",
        /// `bitXor`.
        BitXor = "bitXor",
        /// `groupBy`.
        GroupBy = "groupBy",
        /// `groupByTimeBucket`.
        GroupByTimeBucket = "groupByTimeBucket",
        /// `having`.
        Having = "having",
        /// `distinct`.
        Distinct = "distinct",
        /// `join` (inner).
        Join = "join",
        /// `leftJoin`.
        LeftJoin = "leftJoin",
        /// `rightJoin`.
        RightJoin = "rightJoin",
        /// `crossJoin`.
        CrossJoin = "crossJoin",
        /// `fullOuterJoin`.
        FullOuterJoin = "fullOuterJoin",
        /// `naturalJoin`.
        NaturalJoin = "naturalJoin",
        /// `on`: a column-to-column join condition.
        On = "on",
        /// `union`.
        Union = "union",
        /// `unionAll`.
        UnionAll = "unionAll",
        /// `jsonContains`.
        JsonContains = "jsonContains",
        /// `jsonNotContains`.
        JsonNotContains = "jsonNotContains",
        /// `jsonOverlaps`.
        JsonOverlaps = "jsonOverlaps",
        /// `jsonPath`.
        JsonPath = "jsonPath",
        /// `orderVectorDistance`.
        OrderVectorDistance = "orderVectorDistance",
        /// `covers` (spatial).
        Covers = "covers",
        /// `notCovers` (spatial).
        NotCovers = "notCovers",
        /// `spatialEquals` (spatial).
        SpatialEquals = "spatialEquals",
        /// `notSpatialEquals` (spatial).
        NotSpatialEquals = "notSpatialEquals",
        /// `raw`: SQL used as given.
        Raw = "raw",
    }
}

impl Method {
    /// Whether this is a comparison filter (`equal` through `notExists`).
    ///
    /// PHP: `isFilter()`.
    pub fn is_filter(self) -> bool {
        use Method::*;
        matches!(
            self,
            Equal
                | NotEqual
                | LessThan
                | LessThanEqual
                | GreaterThan
                | GreaterThanEqual
                | Contains
                | ContainsAny
                | NotContains
                | Search
                | NotSearch
                | IsNull
                | IsNotNull
                | Between
                | NotBetween
                | StartsWith
                | NotStartsWith
                | EndsWith
                | NotEndsWith
                | Regex
                | Exists
                | NotExists
        )
    }

    /// Whether this is a spatial predicate.
    ///
    /// PHP: `isSpatial()`.
    pub fn is_spatial(self) -> bool {
        use Method::*;
        matches!(
            self,
            Crosses
                | NotCrosses
                | DistanceEqual
                | DistanceNotEqual
                | DistanceGreaterThan
                | DistanceLessThan
                | Intersects
                | NotIntersects
                | Overlaps
                | NotOverlaps
                | Touches
                | NotTouches
                | Covers
                | NotCovers
                | SpatialEquals
                | NotSpatialEquals
        )
    }

    /// Whether this is a vector similarity filter.
    ///
    /// PHP: `isVector()`.
    pub fn is_vector(self) -> bool {
        matches!(self, Method::VectorDot | Method::VectorCosine | Method::VectorEuclidean)
    }

    /// Whether this is a JSON filter.
    ///
    /// PHP: `isJson()`.
    pub fn is_json(self) -> bool {
        matches!(self, Method::JsonContains | Method::JsonNotContains | Method::JsonOverlaps | Method::JsonPath)
    }

    /// Whether queries of this method hold other queries.
    ///
    /// PHP: `isNested()`.
    pub fn is_nested(self) -> bool {
        matches!(self, Method::And | Method::Or | Method::ElemMatch | Method::Having | Method::Union | Method::UnionAll)
    }

    /// Whether this is an aggregate.
    ///
    /// PHP: `isAggregate()`.
    pub fn is_aggregate(self) -> bool {
        use Method::*;
        matches!(
            self,
            Count
                | CountDistinct
                | Sum
                | Avg
                | Min
                | Max
                | Stddev
                | StddevPop
                | StddevSamp
                | Variance
                | VarPop
                | VarSamp
                | BitAnd
                | BitOr
                | BitXor
        )
    }

    /// Whether a join's ON list may hold this method: `on` column
    /// comparisons, the filters every builder compiles inside ON, and
    /// `and`/`or` of those.
    ///
    /// PHP: `isJoinCondition()`.
    pub fn is_join_condition(self) -> bool {
        use Method::*;
        matches!(
            self,
            On | Equal
                | NotEqual
                | GreaterThan
                | GreaterThanEqual
                | LessThan
                | LessThanEqual
                | Between
                | NotBetween
                | IsNull
                | IsNotNull
                | Contains
                | ContainsAny
                | NotContains
                | StartsWith
                | NotStartsWith
                | EndsWith
                | NotEndsWith
                | And
                | Or
        )
    }

    /// Whether this is a join.
    ///
    /// PHP: `isJoin()`.
    pub fn is_join(self) -> bool {
        use Method::*;
        matches!(self, Join | LeftJoin | RightJoin | CrossJoin | FullOuterJoin | NaturalJoin)
    }

    /// The standard SQL function of an aggregate (`SUM`, `COUNT`, ...), or
    /// `None` when the method has no direct mapping.
    ///
    /// PHP: `sqlFunction()`.
    ///
    /// ```
    /// use utopia_query::Method;
    ///
    /// assert_eq!(Method::StddevPop.sql_function(), Some("STDDEV_POP"));
    /// assert_eq!(Method::Equal.sql_function(), None);
    /// ```
    ///
    /// ```php
    /// use Utopia\Query\Method;
    ///
    /// echo Method::StddevPop->sqlFunction(), "\n"; // STDDEV_POP
    /// var_dump(Method::Equal->sqlFunction());      // NULL
    /// ```
    pub fn sql_function(self) -> Option<&'static str> {
        use Method::*;
        Some(match self {
            Sum => "SUM",
            Count | CountDistinct => "COUNT",
            Avg => "AVG",
            Min => "MIN",
            Max => "MAX",
            Stddev => "STDDEV",
            StddevPop => "STDDEV_POP",
            StddevSamp => "STDDEV_SAMP",
            Variance => "VARIANCE",
            VarPop => "VAR_POP",
            VarSamp => "VAR_SAMP",
            BitAnd => "BIT_AND",
            BitOr => "BIT_OR",
            BitXor => "BIT_XOR",
            _ => return None,
        })
    }
}
