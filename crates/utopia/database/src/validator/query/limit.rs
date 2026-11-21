//! [`Limit`]: `limit` queries.
//!
//! PHP: `Utopia\Database\Validator\Query\Limit`.

use utopia_query::{Method, Value as QValue};
use utopia_validators::{Numeric, Range};

use crate::validator::Message;
use crate::validator::query::base::{INVALID_QUERY, MethodType, query_of, query_validator, rejection};
use crate::value::Value;

/// A `limit` query whose value is a number from 1 to `max_limit`.
///
/// ```
/// use utopia_database::Query;
/// use utopia_database::validator::query::limit::Limit;
///
/// let limit = Limit::new(100);
/// assert!(limit.check(&Query::limit(100)));
/// assert!(!limit.check(&Query::limit(0)));
/// assert_eq!(limit.description(), "Invalid limit: Value must be a valid range between 1 and 100");
/// ```
///
/// PHP: `Utopia\Database\Validator\Query\Limit` (`new Limit($maxLimit)`).
#[derive(Debug, Clone)]
pub struct Limit {
    max_limit: i64,
    message: Message,
}

impl Default for Limit {
    /// `new Limit()`: up to `PHP_INT_MAX`.
    fn default() -> Self {
        Limit::new(i64::MAX)
    }
}

impl Limit {
    /// `new Limit($maxLimit)`.
    pub fn new(max_limit: i64) -> Self {
        Limit { max_limit, message: Message::new(INVALID_QUERY) }
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        self.message.get()
    }

    /// `isValid($value)`: anything but a query is refused.
    pub fn is_valid(&self, value: &Value) -> bool {
        query_of(value).is_some_and(|q| self.check(q))
    }

    /// `isValid($query)`.
    pub fn check(&self, query: &utopia_query::Query) -> bool {
        if query.method() != Method::Limit {
            self.message.set(format!("Invalid query method: {}", query.method().as_str()));
            return false;
        }
        let limit = query.value().unwrap_or(&QValue::Null);
        if let Some(message) = rejection(&Numeric, limit) {
            self.message.set(format!("Invalid limit: {message}"));
            return false;
        }
        if let Some(message) = rejection(&Range::new(1, self.max_limit), limit) {
            self.message.set(format!("Invalid limit: {message}"));
            return false;
        }
        true
    }
}

query_validator!(Limit, MethodType::Limit);
