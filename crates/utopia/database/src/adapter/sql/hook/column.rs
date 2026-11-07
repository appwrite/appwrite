//! Conditions that let through rows a column is `NULL` in.
//!
//! PHP: `Utopia\Database\Adapter\SQL\Hook\Column\*`.

use std::sync::Arc;

use utopia_query::builder::Condition;
use utopia_query::hook::Filter;

use super::{hook_error, is_name_byte, is_word_or_dash, matches_identifier};
use crate::error::{Error, Kind, Result};

/// A filter whose condition also passes the rows where a column is `NULL`:
/// `(condition OR column IS NULL)`.
///
/// PHP: `Utopia\Database\Adapter\SQL\Hook\Column\AllowNull`.
#[derive(Clone)]
pub struct AllowNull {
    filter: Arc<dyn Filter>,
    column: String,
    quote: String,
}

impl AllowNull {
    /// Relaxes `filter` for the rows where `column` is `NULL`, quoting
    /// `column` with `quote` (PHP's default is `` ` ``). Fails with
    /// `InvalidArgumentException` for a column outside
    /// `/^[a-zA-Z0-9_\-][a-zA-Z0-9_.\-]*$/`.
    pub fn new(filter: Arc<dyn Filter>, column: impl Into<String>, quote: impl Into<String>) -> Result<Self> {
        let column = column.into();
        if !is_column(&column) {
            return Err(Error::new(Kind::InvalidArgument, format!("Invalid column name: {column}")));
        }
        Ok(AllowNull { filter, column, quote: quote.into() })
    }

    /// `(condition OR column IS NULL)`, with the condition's bindings. Fails
    /// with `Utopia\Database\Exception` for a column outside
    /// `/^[a-zA-Z0-9_\-][a-zA-Z0-9_.\-]*$/`.
    ///
    /// PHP: `AllowNull::wrap()`.
    pub fn wrap(condition: Condition, column: &str, quote: &str) -> Result<Condition> {
        if !is_column(column) {
            return Err(Error::database(format!("Invalid column name: {column}")));
        }
        Ok(Condition::new(
            format!("({} OR {} IS NULL)", condition.expression, Self::quote(column, quote)),
            condition.bindings,
        ))
    }

    /// Each dot-separated part of `identifier` in `quote`, a quote inside
    /// doubled.
    ///
    /// PHP: `AllowNull::quote()`.
    pub fn quote(identifier: &str, quote: &str) -> String {
        identifier
            .split('.')
            .map(|part| {
                let escaped = if quote.is_empty() { part.to_owned() } else { part.replace(quote, &quote.repeat(2)) };
                format!("{quote}{escaped}{quote}")
            })
            .collect::<Vec<_>>()
            .join(".")
    }
}

impl Filter for AllowNull {
    /// The wrapped filter's condition, relaxed for a `NULL` column.
    fn filter(&self, table: &str) -> utopia_query::Result<Condition> {
        let condition = self.filter.filter(table)?;
        Self::wrap(condition, &self.column, &self.quote).map_err(hook_error)
    }
}

/// `/^[a-zA-Z0-9_\-][a-zA-Z0-9_.\-]*$/`.
fn is_column(column: &str) -> bool {
    matches_identifier(column, is_word_or_dash, is_name_byte)
}
