//! A document database: collections with typed attributes and indexes,
//! documents with permissions, queries, relationships, tenancy and caching,
//! over pluggable storage adapters.
//!
//! [`Database`] is the entry point: it validates and encodes documents,
//! checks permissions against an [`Authorization`], caches reads, resolves
//! relationships and hands storage to an [`Adapter`] ([`adapter::postgres`]).
//! [`Document`] carries attributes as PHP values ([`Value`]), [`Query`]
//! filters, orders and pages finds, and [`Operator`] updates attributes
//! atomically.
//!
//! The crate reads and writes the same tables, rows and metadata as
//! `utopia-php/database`, so PHP and Rust services share one dataset.
//!
//! PHP: `utopia-php/database` 7.4. Each PHP class maps onto one item:
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Database\Database` | [`Database`] (constants as associated constants: `Database::VAR_STRING`) |
//! | `Utopia\Database\Document` | [`Document`] |
//! | `Utopia\Database\Query` | [`Query`], [`query::Method`] |
//! | `Utopia\Database\Operator` | [`Operator`], [`operator::OperatorMethod`] |
//! | `Utopia\Database\Change` | [`Change`] |
//! | `Utopia\Database\Connection` | [`connection`] |
//! | `Utopia\Database\DateTime` | [`datetime`] |
//! | `Utopia\Database\Helpers\{ID, Role, Permission}` | [`helpers::id`], [`helpers::role`], [`helpers::permission`] |
//! | `Utopia\Database\Validator\*` | [`validator`] |
//! | `Utopia\Database\Validator\Authorization` | [`Authorization`] |
//! | `Utopia\Database\Adapter` | [`Adapter`] |
//! | `Utopia\Database\Adapter\Postgres` (with `Adapter\SQL`) | [`adapter::postgres::Postgres`] |
//! | `Utopia\Database\Exception` and `Exception\*` | [`Error`], [`Kind`] |
//! | PHP values (`mixed`) | [`Value`] (`php_std::Value` with [`value::Ext`]) |
//!
//! [`legacy`] is the earlier row-level PostgreSQL layer the Appwrite crates
//! still use; it is replaced by [`Database`] as they migrate.

#![warn(missing_docs)]

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;

pub mod adapter;
pub mod change;
pub mod connection;
pub mod database;
pub mod datetime;
pub mod document;
pub mod error;
pub mod helpers;
pub mod operator;
pub mod query;
pub mod validator;
pub mod value;

#[cfg(feature = "postgres")]
#[allow(missing_docs)]
pub mod legacy;

pub use adapter::Adapter;
pub use change::Change;
pub use database::Database;
pub use document::Document;
pub use error::{Error, Kind, Result};
pub use operator::Operator;
pub use query::Query;
pub use validator::authorization::Authorization;
pub use value::{Array, Value};
