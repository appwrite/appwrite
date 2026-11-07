//! The PostgreSQL frontend protocol classifier.

use super::{Classifier, Sql};
use crate::Type;

/// Classifies PostgreSQL frontend messages: type byte, big-endian length,
/// body.
///
/// A simple query (`Q`) is classified by its SQL text (up to the first NUL);
/// the extended query messages `Parse`, `Bind` and `Execute` are writes, so
/// they reach the primary; anything else is unknown.
///
/// PHP: `Utopia\Query\Classifier\PostgreSQL`.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct PostgreSql;

impl Classifier for PostgreSql {
    /// PHP: `PostgreSQL::classify()`.
    fn classify(&self, data: &[u8]) -> Type {
        if data.len() < 6 {
            return Type::Unknown;
        }
        match data[0] {
            b'Q' => {
                let query = &data[5..];
                let end = query.iter().position(|&b| b == 0).unwrap_or(query.len());
                self.classify_sql(&query[..end])
            }
            b'P' | b'B' | b'E' => Type::Write,
            _ => Type::Unknown,
        }
    }
}

impl Sql for PostgreSql {}
