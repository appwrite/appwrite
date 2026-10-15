//! Reads: `find()`, its joins, cursors and pages.
//!
//! PHP: `find()` and the read machinery of `SQL` and its subclasses.

#![allow(unused_variables, clippy::unused_async)]

use crate::adapter::Find;
use crate::collection::Collection;
use crate::document::Document;
use crate::error::Result;
use crate::query::Query;

use super::Sql;

impl Sql {
    /// `find()`
    pub(crate) async fn find(
        &self,
        collection: &Collection,
        queries: Vec<Query>,
        options: Find,
    ) -> Result<Vec<Document>> {
        todo!("port `find()`")
    }
}
