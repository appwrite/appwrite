//! Counts, sums and aggregates.
//!
//! PHP: `count()`, `sum()` and the aggregate machinery of `SQL`.

#![allow(unused_variables, clippy::unused_async)]

use crate::collection::Collection;
use crate::error::Result;
use crate::query::Query;
use crate::value::Value;

use super::Sql;

impl Sql {
    /// `count()`
    pub(crate) async fn count(&self, collection: &Collection, queries: Vec<Query>, max: Option<i64>) -> Result<i64> {
        todo!("port `count()`")
    }

    /// `sum()`
    pub(crate) async fn sum(
        &self,
        collection: &Collection,
        attribute: &str,
        queries: Vec<Query>,
        max: Option<i64>,
    ) -> Result<Value> {
        todo!("port `sum()`")
    }
}
