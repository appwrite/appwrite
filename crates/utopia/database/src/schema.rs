//! What an engine reports of a collection's schema: its columns and
//! indexes, read back from its catalog.
//!
//! PHP: `Utopia\Database\Schema\Column`, `Schema\Index`.

use utopia_query::schema::IndexType;

/// A column of a collection's table.
///
/// PHP: `Utopia\Database\Schema\Column`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Column {
    /// The column's name.
    pub name: String,
    /// The engine's canonical native type.
    pub kind: String,
    /// The length, where the type has one.
    pub length: Option<i64>,
    /// Whether it admits `NULL`.
    pub nullable: bool,
}

/// An index of a collection's table.
///
/// PHP: `Utopia\Database\Schema\Index`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Index {
    /// The index's name.
    pub name: String,
    /// Its type.
    pub kind: IndexType,
    /// Its columns.
    pub columns: Vec<String>,
    /// The prefix length of each column.
    pub lengths: Vec<Option<i64>>,
}
