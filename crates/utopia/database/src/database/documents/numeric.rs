//! `increaseDocumentAttribute()` and `decreaseDocumentAttribute()`.

use super::super::Database;
use crate::document::Document;
use crate::error::Result;
use crate::value::Value;

impl Database {
    /// Adds `value` (an integer, a float or a numeric string) to a numeric
    /// attribute, up to `max` (`increaseDocumentAttribute()`); returns the
    /// document as updated.
    ///
    /// Fails ([`crate::Kind::Database`]) for a value that is not positive,
    /// ([`crate::Kind::Limit`]) when the result would exceed `max`,
    /// ([`crate::Kind::NotFound`]) for a missing document or attribute,
    /// ([`crate::Kind::Type`]) for an attribute that is not numeric.
    pub async fn increase_document_attribute(
        &self,
        collection: &str,
        id: &str,
        attribute: &str,
        value: Value,
        max: Option<Value>,
    ) -> Result<Document> {
        let _ = (collection, id, attribute, value, max);
        todo!("writes area: increaseDocumentAttribute")
    }

    /// Subtracts `value` from a numeric attribute, down to `min`
    /// (`decreaseDocumentAttribute()`).
    pub async fn decrease_document_attribute(
        &self,
        collection: &str,
        id: &str,
        attribute: &str,
        value: Value,
        min: Option<Value>,
    ) -> Result<Document> {
        let _ = (collection, id, attribute, value, min);
        todo!("writes area: decreaseDocumentAttribute")
    }
}
