//! [`Distinct`]: `distinct` queries.
//!
//! PHP: `Utopia\Database\Validator\Query\Distinct`.

use crate::validator::Message;
use crate::validator::query::base::{INVALID_QUERY, MethodType, base_is_valid, query_validator};

/// A `distinct` query: any query is valid once it reached this validator.
///
/// ```
/// use utopia_database::Query;
/// use utopia_database::validator::query::distinct::Distinct;
///
/// assert!(Distinct::default().check(&Query::distinct()));
/// ```
///
/// PHP: `Utopia\Database\Validator\Query\Distinct`.
#[derive(Debug, Clone)]
pub struct Distinct {
    message: Message,
}

impl Default for Distinct {
    fn default() -> Self {
        Distinct { message: Message::new(INVALID_QUERY) }
    }
}

impl Distinct {
    /// `isValidQuery($query)`: always true.
    pub fn check(&self, _query: &utopia_query::Query) -> bool {
        true
    }
}

base_is_valid!(Distinct);
query_validator!(Distinct, MethodType::Distinct);
