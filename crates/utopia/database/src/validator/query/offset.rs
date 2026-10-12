//! [`Offset`]: `offset` queries.
//!
//! PHP: `Utopia\Database\Validator\Query\Offset`.

use php_std::value::Number;
use utopia_validators::{Numeric, Range, Type, Validator as _};

use crate::query::{Method, Query};
use crate::validator::query::base::{INVALID_QUERY, MethodType, query_validator};
use crate::validator::{Message, accepts};
use crate::value::Value;

/// An `offset` query whose value is a number between 0 and `max_offset`.
///
/// ```
/// use utopia_database::query::{Method, Query};
/// use utopia_database::validator::query::offset::Offset;
/// use utopia_database::value::Value;
///
/// let offset = Offset::new(5000);
/// assert!(offset.check(&Query::new(Method::Offset, "", vec![Value::Int(0)])));
/// assert!(!offset.check(&Query::new(Method::Offset, "", vec![Value::Int(-1)])));
/// ```
///
/// PHP: `Utopia\Database\Validator\Query\Offset` (`new Offset($maxOffset)`).
#[derive(Clone, Debug)]
pub struct Offset {
    max_offset: i64,
    message: Message,
}

impl Default for Offset {
    /// `new Offset()`: up to `PHP_INT_MAX`.
    fn default() -> Self {
        Self::new(i64::MAX)
    }
}

impl Offset {
    /// `new Offset($maxOffset)`.
    pub fn new(max_offset: i64) -> Self {
        Self { max_offset, message: Message::new(INVALID_QUERY) }
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        self.message.get()
    }

    /// `isValid($query)`. A value that is not a number is reported as an
    /// `Invalid limit`, as PHP does.
    pub fn check(&self, query: &Query) -> bool {
        if query.method() != &Method::Offset {
            self.message.set(format!("Query method invalid: {}", query.method()));
            return false;
        }
        let offset = query.value().unwrap_or(&Value::Null);
        if !accepts(&Numeric, offset) {
            self.message.set(format!("Invalid limit: {}", Numeric.description()));
            return false;
        }
        let range = Range::with_format(Number::Int(0), Number::Int(self.max_offset), Type::Integer);
        if !accepts(&range, offset) {
            self.message.set(format!("Invalid offset: {}", range.description()));
            return false;
        }
        true
    }
}

query_validator!(Offset, MethodType::Offset);
