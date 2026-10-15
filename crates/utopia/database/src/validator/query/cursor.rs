//! [`Cursor`]: `cursorAfter` and `cursorBefore` queries.
//!
//! PHP: `Utopia\Database\Validator\Query\Cursor`.

use utopia_query::Method;

use crate::database;
use crate::validator::Message;
use crate::validator::query::base::{INVALID_QUERY, MethodType, query_of, query_validator};
use crate::validator::uid::Uid;
use crate::value::{self as dbvalue, Value, as_document};

/// A `cursorAfter` or `cursorBefore` query whose value is a document id, or
/// a document holding one. A document without an id is a row a join or a
/// distinct read returned and is accepted; any other value, an array
/// included, is refused.
///
/// ```
/// use utopia_database::Query;
/// use utopia_database::validator::query::cursor::Cursor;
///
/// let cursor = Cursor::default();
/// assert!(cursor.check(&Query::cursor_after_value(utopia_query::string("movie1"))));
/// assert!(!cursor.check(&Query::cursor_after_value(utopia_query::string(""))));
/// assert!(cursor.description().starts_with("Invalid cursor: UID must contain"));
/// ```
///
/// PHP: `Utopia\Database\Validator\Query\Cursor` (`new Cursor($maxLength)`).
#[derive(Debug, Clone)]
pub struct Cursor {
    max_length: i64,
    message: Message,
}

impl Default for Cursor {
    /// `new Cursor()`: ids of at most 36 characters.
    fn default() -> Self {
        Cursor::new(database::MAX_UID_DEFAULT_LENGTH)
    }
}

impl Cursor {
    /// `new Cursor($maxLength)`.
    pub fn new(max_length: i64) -> Self {
        Cursor { max_length, message: Message::new(INVALID_QUERY) }
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
        if !matches!(query.method(), Method::CursorAfter | Method::CursorBefore) {
            return false;
        }
        let mut cursor = query.value().map_or(Value::Null, dbvalue::from_query);
        if let Some(document) = as_document(&cursor) {
            if document.id().is_empty() {
                return true;
            }
            cursor = dbvalue::string(document.id());
        }
        let validator = Uid::new(self.max_length);
        if validator.is_valid(&cursor) {
            return true;
        }
        self.message.set(format!("Invalid cursor: {}", validator.description()));
        false
    }
}

query_validator!(Cursor, MethodType::Cursor);
