//! [`Document`]: the queries of a single-document read (selections only).
//!
//! PHP: `Utopia\Database\Validator\Queries\Document`.

use crate::database::Database;
use crate::document::Document as Doc;
use crate::query::Query;
use crate::validator::queries::Queries;
use crate::validator::query::select::Select;
use crate::value::{ArrayKey, Value};

/// An attribute document with `$id`, `key`, `type` and `array: false`, as
/// the query validators add for internal attributes.
pub(crate) fn internal(id: &str, kind: &str) -> Doc {
    Doc::from_array_unchecked(
        [
            (ArrayKey::from("$id"), Value::from(id)),
            (ArrayKey::from("key"), Value::from(id)),
            (ArrayKey::from("type"), Value::from(kind)),
            (ArrayKey::from("array"), Value::Bool(false)),
        ]
        .into_iter()
        .collect(),
    )
}

/// The queries of a document read: `select` queries of the collection's
/// attributes (and `$id`, `$createdAt`, `$updatedAt`).
///
/// PHP: `Utopia\Database\Validator\Queries\Document` (`new Document($attributes,
/// $supportForAttributes)`).
pub struct Document {
    queries: Queries,
}

impl Document {
    /// `new Document($attributes, $supportForAttributes)`.
    pub fn new(attributes: &[Doc], support_for_attributes: bool) -> Self {
        let mut attributes = attributes.to_vec();
        attributes.push(internal("$id", Database::VAR_STRING));
        attributes.push(internal("$createdAt", Database::VAR_DATETIME));
        attributes.push(internal("$updatedAt", Database::VAR_DATETIME));
        Self { queries: Queries::new(vec![Box::new(Select::new(&attributes, support_for_attributes))], 0) }
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        self.queries.description()
    }

    /// `isValid($value)`.
    pub fn is_valid(&self, value: &Value) -> bool {
        self.queries.is_valid(value)
    }

    /// [`Document::is_valid`] of a list of queries.
    pub fn check(&self, queries: &[Query]) -> bool {
        self.queries.check(queries)
    }

    /// `getType()`: `object`.
    pub fn type_name(&self) -> &'static str {
        "object"
    }
}

crate::validator::validator!(Document, utopia_validators::Type::Object, true);
