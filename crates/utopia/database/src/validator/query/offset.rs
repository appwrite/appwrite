//! [`Offset`]: `offset` queries.
//!
//! PHP: `Utopia\Database\Validator\Query\Offset`.

use utopia_query::{Method, Value as QValue};
use utopia_validators::{Numeric, Range};

use crate::validator::Message;
use crate::validator::query::base::{INVALID_QUERY, MethodType, query_of, query_validator, rejection};
use crate::value::Value;

/// An `offset` query whose value is a number from 0 to `max_offset`.
///
/// ```
/// use utopia_database::Query;
/// use utopia_database::validator::query::offset::Offset;
///
/// let offset = Offset::new(5000);
/// assert!(offset.check(&Query::offset(0)));
/// assert!(!offset.check(&Query::offset(-1)));
/// assert_eq!(offset.description(), "Invalid offset: Value must be a valid range between 0 and 5,000");
/// ```
///
/// PHP: `Utopia\Database\Validator\Query\Offset` (`new Offset($maxOffset)`).
#[derive(Debug, Clone)]
pub struct Offset {
    max_offset: i64,
    message: Message,
}

impl Default for Offset {
    /// `new Offset()`: up to `PHP_INT_MAX`.
    fn default() -> Self {
        Offset::new(i64::MAX)
    }
}

impl Offset {
    /// `new Offset($maxOffset)`.
    pub fn new(max_offset: i64) -> Self {
        Offset { max_offset, message: Message::new(INVALID_QUERY) }
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
        if query.method() != Method::Offset {
            self.message.set(format!("Query method invalid: {}", query.method().as_str()));
            return false;
        }
        let offset = query.value().unwrap_or(&QValue::Null);
        if let Some(message) = rejection(&Numeric, offset) {
            self.message.set(format!("Invalid offset: {message}"));
            return false;
        }
        if let Some(message) = rejection(&Range::new(0, self.max_offset), offset) {
            self.message.set(format!("Invalid offset: {message}"));
            return false;
        }
        true
    }
}

query_validator!(Offset, MethodType::Offset);
