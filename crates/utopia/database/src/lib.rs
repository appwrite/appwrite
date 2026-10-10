//! Utopia Database for Rust.
//!
//! This crate reads and writes the **same tables** as `utopia-php/database`
//! so PHP and Rust services can operate on one dataset at the same time:
//!
//! * identical table naming (`"appwrite"."<namespace>_<collection>"`), system
//!   columns (`_id`, `_uid`, `_createdAt`, `_updatedAt`, `_permissions`,
//!   `_tenant`) and `_perms` side tables;
//! * identical query semantics (filters, fulltext search, cursor pagination,
//!   default ordering) and validation messages;
//! * identical cache keys, so writes purge PHP's Redis cache entries.
//!
//! It is intentionally *not* a port of the PHP class hierarchy. Rows are
//! decoded into caller-defined types via [`FromRow`]; writes are expressed
//! as column/value lists and executed as single statements (data-modifying
//! CTEs) so each write is one network round trip.

pub mod datetime;
pub mod id;
pub mod permission;
pub mod query;
pub mod schema;
pub mod validator;

mod error;
mod postgres;
pub mod sql;

pub use error::Error;
pub use postgres::{Database, FindOptions, FromRow, Pool, PoolOptions, row};
pub use query::{Cursor, CursorDirection, Method, Order, OrderDirection, Query, QueryGroups};
pub use schema::{Attribute, AttributeType, Collection};
pub use sql::Param;

pub use deadpool_postgres;
pub use tokio_postgres;
pub use tokio_postgres::Row;

/// Database `schema` used by Appwrite for every table (`APP_DATABASE`).
pub const DEFAULT_SCHEMA: &str = "appwrite";

/// Default page size when no limit query is given.
pub const DEFAULT_LIMIT: i64 = 25;

/// Result alias.
pub type Result<T, E = Error> = std::result::Result<T, E>;
