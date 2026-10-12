//! [`IndexDependency`]: whether an attribute can be deleted or renamed
//! while indexes use it.
//!
//! PHP: `Utopia\Database\Validator\IndexDependency`.

use crate::document::Document;
use crate::validator::{attribute_key, lower};
use crate::value::{Value, as_document};

/// An attribute that no index uses, when the adapter indexes array
/// attributes through casts (`cast_index_support`) and the attribute is an
/// array; any attribute otherwise.
///
/// ```
/// use utopia_database::Document;
/// use utopia_database::validator::index_dependency::IndexDependency;
/// use utopia_database::value::{Value, list, string};
///
/// let index = Document::from_pairs([("attributes", list([string("tags")]))]).unwrap();
/// let validator = IndexDependency::new(vec![index], true);
/// let tags = Document::from_pairs([("key", string("tags")), ("array", Value::Bool(true))]).unwrap();
/// assert!(!validator.check(&tags));
/// ```
///
/// PHP: `Utopia\Database\Validator\IndexDependency` (`new IndexDependency($indexes, $castIndexSupport)`).
#[derive(Clone, Debug)]
pub struct IndexDependency {
    indexes: Vec<Document>,
    cast_index_support: bool,
}

impl IndexDependency {
    /// `new IndexDependency($indexes, $castIndexSupport)`.
    pub fn new(indexes: Vec<Document>, cast_index_support: bool) -> Self {
        Self { indexes, cast_index_support }
    }

    /// Whether the adapter indexes array attributes through casts.
    pub fn cast_index_support(&self) -> bool {
        self.cast_index_support
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        "Attribute can't be deleted or renamed because it is used in an index".to_owned()
    }

    /// `isValid($attribute)` of an attribute document.
    pub fn check(&self, attribute: &Document) -> bool {
        if !self.cast_index_support {
            return true;
        }
        if !attribute.get("array").is_some_and(Value::truthy) {
            return true;
        }
        let key = lower(&attribute_key(attribute));
        !self.indexes.iter().any(|index| match index.get("attributes") {
            Some(Value::Array(attributes)) => attributes.values().any(|a| lower(a) == key),
            _ => false,
        })
    }

    /// `isValid($value)`: a document as [`IndexDependency::check`]. PHP
    /// fails on another value when it reads it (with cast index support);
    /// it is invalid here.
    pub fn is_valid(&self, value: &Value) -> bool {
        match as_document(value) {
            Some(attribute) => self.check(attribute),
            None => !self.cast_index_support,
        }
    }

    /// `getType()`: `object`.
    pub fn type_name(&self) -> &'static str {
        "object"
    }
}

super::validator!(IndexDependency, utopia_validators::Type::Object, false);
