//! The value parts of a table blueprint: indexes, check constraints and
//! column renames, and the options of a standalone `CREATE INDEX`.

use super::enums::{IndexAlgorithm, IndexType};
use super::names;
use crate::error::{Error, Result};
use crate::value::Value;

/// An index of a table: its columns (with per-column lengths, orders and
/// collations), raw expressions, method and operator class, and for
/// ClickHouse data-skipping indexes the algorithm, its arguments and the
/// granularity.
///
/// [`Index::new`] makes one with defaults; [`Index::validated`] runs the
/// checks PHP's constructor makes.
///
/// PHP: `Utopia\Query\Schema\Index`.
#[derive(Debug, Clone, PartialEq)]
pub struct Index {
    /// The name.
    pub name: String,
    /// The columns.
    pub columns: Vec<String>,
    /// The kind.
    pub kind: IndexType,
    /// Prefix lengths, by column.
    pub lengths: Vec<(String, i64)>,
    /// Orders (`ASC` / `DESC`, any case), by column.
    pub orders: Vec<(String, String)>,
    /// The method (`USING btree`), or empty.
    pub method: String,
    /// The operator class applied to every column, or empty.
    pub operator_class: String,
    /// Collations, by column.
    pub collations: Vec<(String, String)>,
    /// Raw SQL expressions appended to the column list, unquoted.
    pub raw_columns: Vec<String>,
    /// The ClickHouse skip-index algorithm, if any.
    pub algorithm: Option<IndexAlgorithm>,
    /// The algorithm's arguments: strings, integers or floats.
    pub algorithm_args: Vec<Value>,
    /// The ClickHouse granularity, if set.
    pub granularity: Option<i64>,
}

fn lookup<'a, T>(pairs: &'a [(String, T)], column: &str) -> Option<&'a T> {
    pairs.iter().rev().find(|(c, _)| c == column).map(|(_, v)| v)
}

impl Index {
    /// An index of `kind` named `name` over `columns`, with no other option
    /// (not validated: see [`Index::validated`]).
    pub fn new(name: impl Into<String>, columns: Vec<String>, kind: IndexType) -> Self {
        Index {
            name: name.into(),
            columns,
            kind,
            lengths: Vec::new(),
            orders: Vec::new(),
            method: String::new(),
            operator_class: String::new(),
            collations: Vec::new(),
            raw_columns: Vec::new(),
            algorithm: None,
            algorithm_args: Vec::new(),
            granularity: None,
        }
    }

    /// The index, after PHP's constructor checks: a ClickHouse index name
    /// is an identifier, there is at least one column or raw expression, the
    /// method, operator class and collations are plain words, the
    /// granularity is at least 1, and `minmax` / `inverted` take no
    /// arguments.
    ///
    /// PHP: `new Index(...)`, which throws `ValidationException`.
    pub fn validated(self) -> Result<Self> {
        if self.algorithm.is_some() && !names::is_identifier(&self.name) {
            return Err(Error::Validation(format!("Invalid index name: {}", self.name)));
        }
        if self.columns.is_empty() && self.raw_columns.is_empty() {
            return Err(Error::Validation("Index requires at least one column.".into()));
        }
        if !self.method.is_empty() && !names::is_word(&self.method) {
            return Err(Error::Validation(format!("Invalid index method: {}", self.method)));
        }
        if !self.operator_class.is_empty() && !names::is_dotted_word(&self.operator_class) {
            return Err(Error::Validation(format!("Invalid operator class: {}", self.operator_class)));
        }
        for (_, collation) in &self.collations {
            if !names::is_word(collation) {
                return Err(Error::Validation(format!("Invalid collation: {collation}")));
            }
        }
        if let Some(granularity) = self.granularity
            && granularity < 1
        {
            return Err(Error::Validation("Index granularity must be >= 1.".into()));
        }
        if let Some(algorithm) = self.algorithm
            && !self.algorithm_args.is_empty()
            && !algorithm.accepts_args()
        {
            return Err(Error::Validation(format!("{} does not accept algorithm arguments.", algorithm.as_str())));
        }
        Ok(self)
    }

    /// The name an unnamed index gets: `prefix` and the columns joined by
    /// `_`, each with runs of characters outside `[A-Za-z0-9_]` replaced by
    /// one `_` (`idx_email`, `uniq_tenant_id_email`).
    ///
    /// PHP: `Table::autoIndexName()`.
    pub fn auto_name(prefix: &str, columns: &[String]) -> String {
        let mut name = String::from(prefix);
        for (i, column) in columns.iter().enumerate() {
            if i > 0 {
                name.push('_');
            }
            name.push_str(&names::sanitize(column));
        }
        name
    }

    /// The prefix length of `column`, if set.
    pub fn length(&self, column: &str) -> Option<i64> {
        lookup(&self.lengths, column).copied()
    }

    /// The order of `column`, if set.
    pub fn order(&self, column: &str) -> Option<&str> {
        lookup(&self.orders, column).map(String::as_str)
    }

    /// The collation of `column`, if set.
    pub fn collation(&self, column: &str) -> Option<&str> {
        lookup(&self.collations, column).map(String::as_str)
    }
}

/// The options of a standalone `CREATE INDEX` statement.
///
/// PHP: the optional parameters of `Schema::createIndex()`.
#[derive(Debug, Clone, Default, PartialEq)]
pub struct IndexOptions {
    /// `CREATE UNIQUE INDEX`.
    pub unique: bool,
    /// The kind (`fulltext`, `spatial`, ...), when not unique; `None` for a
    /// plain index.
    pub kind: Option<IndexType>,
    /// The method (`USING ...`), or empty.
    pub method: String,
    /// The operator class, or empty.
    pub operator_class: String,
    /// Prefix lengths, by column.
    pub lengths: Vec<(String, i64)>,
    /// Orders, by column.
    pub orders: Vec<(String, String)>,
    /// Collations, by column.
    pub collations: Vec<(String, String)>,
    /// Raw SQL expressions appended to the column list.
    pub raw_columns: Vec<String>,
    /// `CREATE INDEX CONCURRENTLY` (PostgreSQL only; ignored elsewhere).
    pub concurrently: bool,
}

impl IndexOptions {
    /// The index these options describe, named `name` over `columns`
    /// (validated).
    pub(crate) fn index(&self, name: &str, columns: &[&str]) -> Result<Index> {
        let kind = if self.unique { IndexType::Unique } else { self.kind.unwrap_or(IndexType::Index) };
        Index {
            lengths: self.lengths.clone(),
            orders: self.orders.clone(),
            method: self.method.clone(),
            operator_class: self.operator_class.clone(),
            collations: self.collations.clone(),
            raw_columns: self.raw_columns.clone(),
            ..Index::new(name, columns.iter().map(|c| (*c).to_owned()).collect(), kind)
        }
        .validated()
    }
}

/// A named table-level `CHECK` constraint.
///
/// PHP: `Utopia\Query\Schema\CheckConstraint`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct CheckConstraint {
    /// The constraint name.
    pub name: String,
    /// The SQL expression, emitted verbatim.
    pub expression: String,
}

impl CheckConstraint {
    /// A check named `name` (an identifier) on `expression`.
    ///
    /// PHP: `new CheckConstraint($name, $expression)`, which throws
    /// `ValidationException` for an invalid name.
    pub fn new(name: impl Into<String>, expression: impl Into<String>) -> Result<Self> {
        let name = name.into();
        if !names::is_identifier(&name) {
            return Err(Error::Validation(format!("Invalid check constraint name: {name}")));
        }
        Ok(CheckConstraint { name, expression: expression.into() })
    }
}

/// A column rename of an `ALTER TABLE`.
///
/// PHP: `Utopia\Query\Schema\RenameColumn`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct RenameColumn {
    /// The current name.
    pub from: String,
    /// The new name.
    pub to: String,
}

impl RenameColumn {
    /// A rename of `from` to `to`.
    pub fn new(from: impl Into<String>, to: impl Into<String>) -> Self {
        RenameColumn { from: from.into(), to: to.into() }
    }
}
