//! Statement classifiers: whether a wire protocol message reads, writes or
//! controls a transaction, so a proxy can route it to a replica or the
//! primary.
//!
//! A classifier does not parse: the SQL classifiers read the leading keyword
//! (skipping whitespace, comments and quoted literals), and the MongoDB
//! classifier reads the first key of an `OP_MSG` command document. For a
//! structural parse of SQL, see [`crate::ast`].
//!
//! Every classifier implements [`Classifier`]; the SQL ones also implement
//! [`Sql`], which classifies bare SQL text and holds the shared keyword logic.
//! Messages are bytes (`&[u8]`), as they arrive on the wire.
//!
//! PHP: `Utopia\Query\Classifier`, `Utopia\Query\Classifier\*`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Classifier` (interface), `classify()` | [`Classifier`], [`Classifier::classify`] |
//! | `Classifier\SQL` (abstract), `classifySQL()`, `extractKeyword()` | [`Sql`], [`Sql::classify_sql`], [`Sql::extract_keyword`] |
//! | `Classifier\MySQL` | [`MySql`] |
//! | `Classifier\PostgreSQL` | [`PostgreSql`] |
//! | `Classifier\MongoDB` | [`MongoDb`] |
//! | `Type` | [`crate::Type`] |
//!
//! ```
//! use utopia_query::Type;
//! use utopia_query::classifier::{Classifier, PostgreSql, Sql};
//!
//! let classifier = PostgreSql;
//! assert_eq!(classifier.classify(b"Q\x00\x00\x00\x0dSELECT 1\x00"), Type::Read);
//! assert_eq!(classifier.classify_sql(b"/* hint */ insert into t values (1)"), Type::Write);
//! assert_eq!(classifier.extract_keyword(b"  begin;"), b"BEGIN");
//! ```
//!
//! ```php
//! use Utopia\Query\Classifier\PostgreSQL;
//! use Utopia\Query\Type;
//!
//! $classifier = new PostgreSQL();
//! var_dump($classifier->classify("Q\x00\x00\x00\x0dSELECT 1\x00") === Type::Read); // bool(true)
//! var_dump($classifier->classifySQL('/* hint */ insert into t values (1)') === Type::Write); // bool(true)
//! echo $classifier->extractKeyword('  begin;'), "\n"; // BEGIN
//! ```

mod mongodb;
mod mysql;
mod postgresql;
mod sql;

pub use mongodb::MongoDb;
pub use mysql::MySql;
pub use postgresql::PostgreSql;
pub use sql::Sql;

use crate::Type;

/// Classifies a raw wire protocol message as a read, a write, a
/// transaction statement or unknown. Implement it to route another protocol.
///
/// PHP: `Utopia\Query\Classifier`.
pub trait Classifier {
    /// The classification of the raw message `data`.
    ///
    /// PHP: `Classifier::classify()`.
    fn classify(&self, data: &[u8]) -> Type;
}
