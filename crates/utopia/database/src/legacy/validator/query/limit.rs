//! [`Limit`]: `limit` queries.
//!
//! PHP: `Utopia\Database\Validator\Query\Limit`.

use php_std::value::Number;
use utopia_validators::{Numeric, Range, Type, Validator as _};

use crate::query::{Method, Query};
use crate::validator::query::base::{INVALID_QUERY, MethodType, query_validator};
use crate::validator::{Message, accepts};
use crate::value::Value;

/// A `limit` query whose value is a number between 1 and `max_limit`.
///
/// ```
/// use utopia_database::query::{Method, Query};
/// use utopia_database::validator::query::limit::Limit;
/// use utopia_database::value::Value;
///
/// let limit = Limit::new(100);
/// assert!(limit.check(&Query::new(Method::Limit, "", vec![Value::Int(25)])));
/// assert!(!limit.check(&Query::new(Method::Limit, "", vec![Value::Int(101)])));
/// assert_eq!(limit.description(), "Invalid limit: Value must be a valid range between 1 and 100");
/// ```
///
/// PHP: `Utopia\Database\Validator\Query\Limit` (`new Limit($maxLimit)`).
#[derive(Clone, Debug)]
pub struct Limit {
    max_limit: i64,
    message: Message,
}

impl Default for Limit {
    /// `new Limit()`: up to `PHP_INT_MAX`.
    fn default() -> Self {
        Self::new(i64::MAX)
    }
}

impl Limit {
    /// `new Limit($maxLimit)`.
    pub fn new(max_limit: i64) -> Self {
        Self { max_limit, message: Message::new(INVALID_QUERY) }
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        self.message.get()
    }

    /// `isValid($query)`.
    pub fn check(&self, query: &Query) -> bool {
        if query.method() != &Method::Limit {
            self.message.set(format!("Invalid query method: {}", query.method()));
            return false;
        }
        let limit = query.value().unwrap_or(&Value::Null);
        if !accepts(&Numeric, limit) {
            self.message.set(format!("Invalid limit: {}", Numeric.description()));
            return false;
        }
        let range = Range::with_format(Number::Int(1), Number::Int(self.max_limit), Type::Integer);
        if !accepts(&range, limit) {
            self.message.set(format!("Invalid limit: {}", range.description()));
            return false;
        }
        true
    }
}

query_validator!(Limit, MethodType::Limit);
