//! [`Cursor`]: `cursorAfter` and `cursorBefore` queries.
//!
//! PHP: `Utopia\Database\Validator\Query\Cursor`.

use crate::database::Database;
use crate::query::{Method, Query};
use crate::validator::Message;
use crate::validator::query::base::{INVALID_QUERY, MethodType, query_validator};
use crate::validator::uid::Uid;
use crate::value::{Ext, Str, Value};

/// A cursor query whose value (a document id, or a document) is a valid
/// [`Uid`] of at most `max_length` characters.
///
/// ```
/// use utopia_database::query::{Method, Query};
/// use utopia_database::validator::query::cursor::Cursor;
/// use utopia_database::value::string;
///
/// let cursor = Cursor::default();
/// assert!(cursor.check(&Query::new(Method::CursorAfter, "", vec![string("doc1")])));
/// assert!(!cursor.check(&Query::new(Method::CursorAfter, "", vec![string("_doc")])));
/// ```
///
/// PHP: `Utopia\Database\Validator\Query\Cursor` (`new Cursor($maxLength)`).
#[derive(Clone, Debug)]
pub struct Cursor {
    max_length: i64,
    message: Message,
}

impl Default for Cursor {
    /// `new Cursor()`: ids of at most 36 characters.
    fn default() -> Self {
        Self::new(Database::MAX_UID_DEFAULT_LENGTH)
    }
}

impl Cursor {
    /// `new Cursor($maxLength)`.
    pub fn new(max_length: i64) -> Self {
        Self { max_length, message: Message::new(INVALID_QUERY) }
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        self.message.get()
    }

    /// `isValid($query)`.
    pub fn check(&self, query: &Query) -> bool {
        if !matches!(query.method(), Method::CursorAfter | Method::CursorBefore) {
            return false;
        }
        let cursor = match query.value() {
            Some(Value::Ext(Ext::Document(d))) => Value::Str(Str::from(d.id())),
            Some(v) => v.clone(),
            None => Value::Null,
        };
        let uid = Uid::new(self.max_length);
        if uid.is_valid(&cursor) {
            return true;
        }
        self.message.set(format!("Invalid cursor: {}", uid.description()));
        false
    }
}

query_validator!(Cursor, MethodType::Cursor);
