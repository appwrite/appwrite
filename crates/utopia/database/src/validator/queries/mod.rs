//! [`Queries`]: lists of queries, each checked by the [`QueryValidator`]
//! of its kind; and the lists for one document ([`document`]) and for
//! document finds ([`documents`]).
//!
//! PHP: `Utopia\Database\Validator\Queries`, `Queries\Document` and
//! `Queries\Documents`.

pub mod document;
pub mod documents;

use std::borrow::Cow;

use crate::query::Query;
use crate::validator::Message;
use crate::validator::query::base::{MethodType, QueryValidator};
use crate::value::{Array, Ext, Value};

/// Where PHP reports a `Query::parse()` argument error from (the
/// `TypeError` message names the calling file and line, as installed in
/// Appwrite's image).
const PARSE_CALLER: &str = "/usr/src/code/vendor/utopia-php/database/src/Database/Validator/Queries.php on line 67";

/// A query of a list, as `Queries::isValid` reads it: a query, or a value
/// parsed with `Query::parse` (strings, and scalars PHP converts to
/// strings). The error is the failure message after `Invalid query: `.
pub(crate) fn query_of(value: &Value) -> Result<Cow<'_, Query>, String> {
    let text = match value {
        Value::Ext(Ext::Query(q)) => return Ok(Cow::Borrowed(q)),
        Value::Str(_) | Value::Int(_) | Value::Float(_) | Value::Bool(_) => crate::value::to_text(value),
        other => {
            return Err(format!(
                "Utopia\\Database\\Query::parse(): Argument #1 ($query) must be of type string, {} given, called in {PARSE_CALLER}",
                other.debug_type()
            ));
        }
    };
    Query::parse(&text).map(Cow::Owned).map_err(|e| e.message().to_owned())
}

/// A list of queries: at most `length` of them (0: no limit), each parsed
/// and valid for every validator of its kind (and at least one), nested
/// queries included.
///
/// ```
/// use utopia_database::query::{Method, Query};
/// use utopia_database::validator::queries::Queries;
/// use utopia_database::validator::query::limit::Limit;
///
/// let queries = Queries::new(vec![Box::new(Limit::default())], 0);
/// assert!(queries.check(&[Query::new(Method::Limit, "", vec![10.into()])]));
/// assert!(!queries.check(&[Query::new(Method::Offset, "", vec![10.into()])]));
/// assert_eq!(queries.description(), "Invalid query method: offset");
/// ```
///
/// PHP: `Utopia\Database\Validator\Queries` (`new Queries($validators, $length)`).
pub struct Queries {
    validators: Vec<Box<dyn QueryValidator>>,
    length: i64,
    message: Message,
}

impl Queries {
    /// `new Queries($validators, $length)`.
    pub fn new(validators: Vec<Box<dyn QueryValidator>>, length: i64) -> Self {
        Self { validators, length, message: Message::new("Invalid queries") }
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        self.message.get()
    }

    pub(crate) fn set_message(&self, message: impl Into<String>) {
        self.message.set(message);
    }

    /// `isValid($value)`: `value` must be an array of queries (or query
    /// strings).
    pub fn is_valid(&self, value: &Value) -> bool {
        let Value::Array(queries) = value else {
            self.message.set("Queries must be an array");
            return false;
        };
        self.check_array(queries)
    }

    /// [`Queries::is_valid`] of a list of queries.
    pub fn check(&self, queries: &[Query]) -> bool {
        self.check_array(&queries.iter().cloned().map(Value::from).collect())
    }

    pub(crate) fn check_array(&self, queries: &Array) -> bool {
        if self.length != 0 && queries.len() as i64 > self.length {
            return false;
        }
        for value in queries.values() {
            let query = match query_of(value) {
                Ok(q) => q,
                Err(message) => {
                    self.message.set(format!("Invalid query: {message}"));
                    return false;
                }
            };
            if query.is_nested() && !self.check_array(&query.values().iter().cloned().collect()) {
                return false;
            }
            let method_type = MethodType::of(query.method());
            let mut method_is_valid = false;
            for validator in &self.validators {
                if Some(validator.method_type()) != method_type {
                    continue;
                }
                if !validator.check(&query) {
                    self.message.set(format!("Invalid query: {}", validator.description()));
                    return false;
                }
                method_is_valid = true;
            }
            if !method_is_valid {
                self.message.set(format!("Invalid query method: {}", query.method()));
                return false;
            }
        }
        true
    }

    /// `getType()`: `object`.
    pub fn type_name(&self) -> &'static str {
        "object"
    }
}

crate::validator::validator!(Queries, utopia_validators::Type::Object, true);
