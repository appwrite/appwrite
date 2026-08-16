//! PHP `Utopia\Query\Builder\ParsedQuery`.

use crate::enums::CursorDirection;
use crate::query::Query;
use crate::value::QueryValue;

#[derive(Debug, Clone, PartialEq, Default)]
pub struct TimeBucket {
    pub attribute: String,
    pub interval: String,
}

#[derive(Debug, Clone, PartialEq, Default)]
pub struct ParsedQuery {
    pub filters: Vec<Query>,
    pub selections: Vec<Query>,
    pub aggregations: Vec<Query>,
    pub group_by: Vec<String>,
    pub having: Vec<Query>,
    pub distinct: bool,
    pub joins: Vec<Query>,
    pub unions: Vec<Query>,
    pub limit: Option<i64>,
    pub offset: Option<i64>,
    pub cursor: Option<QueryValue>,
    pub cursor_direction: Option<CursorDirection>,
    pub time_buckets: Vec<TimeBucket>,
}
