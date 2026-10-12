//! Splitting a find's queries by kind (PHP `Query::groupByType()`,
//! `Query::getByType()`, `Query::getCursorQueries()`).

use super::{Method, Query};
use crate::database::Database;
use crate::value::Value;

/// A find's queries by kind.
///
/// PHP: the array `Query::groupByType()` returns (`filters`, `selections`,
/// `limit`, `offset`, `orderAttributes`, `orderTypes`, `cursor`,
/// `cursorDirection`).
#[derive(Clone, PartialEq, Debug, Default)]
pub struct Grouped {
    /// Every query that is not an order, a limit, an offset, a cursor or a
    /// selection (unknown methods included), in order.
    pub filters: Vec<Query>,
    /// The `select` queries, in order.
    pub selections: Vec<Query>,
    /// The first `limit` query's value (the first whose value is not null).
    pub limit: Option<Value>,
    /// The first `offset` query's value; when that value is missing or
    /// null, the limit found so far.
    pub offset: Option<Value>,
    /// The attributes of the order queries, skipping empty ones (and
    /// `"0"`, empty to PHP).
    pub order_attributes: Vec<String>,
    /// One of [`Database::ORDER_ASC`], [`Database::ORDER_DESC`] and
    /// [`Database::ORDER_RANDOM`] per order query, in order.
    pub order_types: Vec<&'static str>,
    /// The first cursor query's value (usually its document); when that
    /// value is missing or null, the limit found so far.
    pub cursor: Option<Value>,
    /// [`Database::CURSOR_AFTER`] or [`Database::CURSOR_BEFORE`]: the
    /// direction of the last cursor query read while no cursor was found.
    pub cursor_direction: Option<&'static str>,
}

impl Query {
    /// The queries whose method is one of `types`, cloned.
    ///
    /// PHP: `Query::getByType()`. Its `$clone` flag has no counterpart: the
    /// result always owns its queries.
    pub fn get_by_type(queries: &[Query], types: &[Method]) -> Vec<Query> {
        queries.iter().filter(|q| types.contains(&q.method)).cloned().collect()
    }

    /// The `cursorAfter` and `cursorBefore` queries.
    ///
    /// PHP: `Query::getCursorQueries()`.
    pub fn get_cursor_queries(queries: &[Query]) -> Vec<Query> {
        Self::get_by_type(queries, &[Method::CursorAfter, Method::CursorBefore])
    }

    /// Splits `queries` into filters, selections, orders and pagination.
    ///
    /// PHP: `Query::groupByType()`, quirks kept: the first limit, offset and
    /// cursor win, but one whose value is missing or null does not count
    /// (a later one is read); a missing offset or cursor value falls back
    /// to the limit found so far (`$values[0] ?? $limit`); the cursor
    /// direction is set even when the cursor stays null.
    pub fn group_by_type(queries: &[Query]) -> Grouped {
        let mut grouped = Grouped::default();
        for query in queries {
            match &query.method {
                Method::OrderAsc | Method::OrderDesc | Method::OrderRandom => {
                    if !(query.attribute.is_empty() || query.attribute == "0") {
                        grouped.order_attributes.push(query.attribute.clone());
                    }
                    grouped.order_types.push(match query.method {
                        Method::OrderAsc => Database::ORDER_ASC,
                        Method::OrderDesc => Database::ORDER_DESC,
                        _ => Database::ORDER_RANDOM,
                    });
                }
                Method::Limit => {
                    if grouped.limit.is_none() {
                        grouped.limit = query.value().cloned();
                    }
                }
                Method::Offset => {
                    if grouped.offset.is_none() {
                        grouped.offset = query.value().cloned().or_else(|| grouped.limit.clone());
                    }
                }
                Method::CursorAfter | Method::CursorBefore => {
                    if grouped.cursor.is_none() {
                        grouped.cursor = query.value().cloned().or_else(|| grouped.limit.clone());
                        grouped.cursor_direction = Some(if query.method == Method::CursorAfter {
                            Database::CURSOR_AFTER
                        } else {
                            Database::CURSOR_BEFORE
                        });
                    }
                }
                Method::Select => grouped.selections.push(query.clone()),
                _ => grouped.filters.push(query.clone()),
            }
        }
        grouped
    }
}
