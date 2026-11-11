//! Statements the document API cannot express: `from()`, `schema()`,
//! `query()` and `mutate()`.

use utopia_query::builder::Statement;

use super::Database;
use crate::adapter::SqlSchema;
use crate::document::Document;
use crate::error::{Error, Result};
use crate::hook::SqlBuilder;

impl Database {
    /// A query builder over a collection's table, its tenant scope applied
    /// (`from()`). Its statements run as written: no permissions, caches,
    /// validation, hooks or events. It is only handed out while
    /// authorization is skipped. PHP's builder runs its statements through
    /// an executor; here the caller runs what it builds with
    /// [`Database::query`] or [`Database::mutate`].
    ///
    /// Fails ([`crate::Kind::Authorization`]) while authorization is enabled,
    /// and ([`crate::Kind::Database`]) when the adapter has no query builder.
    pub fn from(&self, collection: &str, alias: &str) -> Result<SqlBuilder> {
        self.require_skipped_authorization()?;
        let Some(builders) = self.inner.adapter.query_builder() else {
            return Err(Error::database("Query builder is not supported by this adapter"));
        };
        let mut builder = builders.builder()?;
        match &mut builder {
            SqlBuilder::MySql(b) => {
                b.from(collection, alias);
            }
            SqlBuilder::MariaDb(b) => {
                b.from(collection, alias);
            }
            SqlBuilder::PostgreSql(b) => {
                b.from(collection, alias);
            }
            SqlBuilder::Sqlite(b) => {
                b.from(collection, alias);
            }
        }
        Ok(builder)
    }

    /// A schema builder in the adapter's dialect (`schema()`).
    ///
    /// Fails ([`crate::Kind::Database`]) when the adapter has no query builder.
    pub fn schema(&self) -> Result<SqlSchema> {
        match self.inner.adapter.query_builder() {
            Some(builders) => builders.schema(),
            None => Err(Error::database("Schema builder is not supported by this adapter")),
        }
    }

    /// Runs a read as written (`query()`), returning its rows.
    ///
    /// Fails ([`crate::Kind::Authorization`]) while authorization is enabled,
    /// and ([`crate::Kind::Database`]) when the adapter cannot run raw
    /// statements.
    pub async fn query(&self, statement: &Statement) -> Result<Vec<Document>> {
        self.require_skipped_authorization()?;
        let Some(raw) = self.inner.adapter.raw_query() else {
            return Err(Error::database("Raw queries are not supported by this adapter"));
        };
        raw.raw_query(&statement.query, statement.bindings.iter().cloned().collect()).await
    }

    /// Runs a write as written (`mutate()`), returning the rows it changed.
    ///
    /// Fails as [`Database::query`] does.
    pub async fn mutate(&self, statement: &Statement) -> Result<i64> {
        self.require_skipped_authorization()?;
        let Some(raw) = self.inner.adapter.raw_query() else {
            return Err(Error::database("Raw queries are not supported by this adapter"));
        };
        raw.raw_mutation(&statement.query, statement.bindings.iter().cloned().collect()).await
    }

    /// `requireSkippedAuthorization()`.
    fn require_skipped_authorization(&self) -> Result<()> {
        if self.authorization().status() {
            return Err(Error::authorization(
                "The query builder bypasses permissions, caches and events: build and run it inside getAuthorization()->skip()",
            ));
        }
        Ok(())
    }
}
