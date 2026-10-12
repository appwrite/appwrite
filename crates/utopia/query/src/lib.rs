//! Queries as data, and everything that turns them into statements.
//!
//! A [`Query`] is one filter, order, page, selection, aggregate, join or
//! union, as data: built in code (`Query::equal(...)`) or parsed from the
//! JSON clients send ([`Query::parse`]). Builders compile lists of queries
//! into statements for MySQL, MariaDB, PostgreSQL, SQLite, ClickHouse and
//! MongoDB ([`builder`]), with parameter bindings, joins, aggregates,
//! window functions, upserts and dialect features. Schema builders write
//! DDL ([`schema`]). A SQL tokenizer ([`tokenizer`]), parser and serializer
//! ([`ast`]) and a statement classifier ([`classifier`]) read SQL back.
//!
//! Values are PHP values ([`Value`], from `php-std`): queries and statements
//! are shared with PHP, so integers stay distinct from floats and arrays
//! keep their keys and order.
//!
//! PHP: ports `utopia-php/query`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Query` | [`Query`] |
//! | `Method` | [`Method`] |
//! | `Type`, `CursorDirection`, `OrderDirection`, `NullsPosition` | [`Type`], [`CursorDirection`], [`OrderDirection`], [`NullsPosition`] |
//! | `Compiler` | [`Compiler`] |
//! | `QuotesIdentifiers` | [`quote`], [`quote_literal`] |
//! | `Builder\ParsedQuery` | [`ParsedQuery`] |
//! | `Exception`, `Exception\ValidationException`, `Exception\UnsupportedException` | [`Error::Query`], [`Error::Validation`], [`Error::Unsupported`] |
//! | `Builder\*` | [`builder`] |
//! | `Hook\*` | [`hook`] |
//! | `Schema\*` | [`schema`] |
//! | `Tokenizer\*` | [`tokenizer`] |
//! | `AST\*` | [`ast`] |
//! | `Classifier\*` | [`classifier`] |
//!
//! ```
//! use utopia_query::Query;
//! use utopia_query::builder::MySql;
//!
//! let statement = MySql::new()
//!     .from("users", "")
//!     .select(["name", "email"])
//!     .filter([Query::equal("status", ["active"]), Query::greater_than("age", 18)])
//!     .limit(25)
//!     .build()?;
//! assert_eq!(statement.query, "SELECT `name`, `email` FROM `users` WHERE `status` IN (?) AND `age` > ? LIMIT ?");
//! # Ok::<(), utopia_query::Error>(())
//! ```
//!
//! ```php
//! use Utopia\Query\Builder\MySQL;
//! use Utopia\Query\Query;
//!
//! $statement = (new MySQL())
//!     ->from('users')
//!     ->select(['name', 'email'])
//!     ->filter([Query::equal('status', ['active']), Query::greaterThan('age', 18)])
//!     ->limit(25)
//!     ->build();
//! echo $statement->query, "\n"; // SELECT `name`, `email` FROM `users` WHERE `status` IN (?) AND `age` > ? LIMIT ?
//! ```

#![warn(missing_docs)]

pub mod ast;
pub mod builder;
pub mod classifier;
mod enums;
mod error;
pub mod hook;
mod method;
mod query;
mod quote;
pub mod schema;
pub mod tokenizer;
mod value;

pub use enums::{CursorDirection, NullsPosition, OrderDirection, Type};
pub use error::{Code, Error, Result};
pub use method::Method;
pub use query::{Compiler, DEFAULT_ALIAS, GROUP_BY_TIME_BUCKET_INTERVALS, ParsedQuery, Query, QueryOrJson, TimeBucket};
pub use quote::{quote, quote_literal};
pub use value::{Array, IntoValue, IntoValues, Object, Value, list, string};

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;
