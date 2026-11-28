//! [`Change`]: a document before and after an upsert.

use crate::document::Document;

/// The old and new versions of a document an upsert writes.
///
/// PHP: `Utopia\Database\Change` (`getOld`/`setOld`/`getNew`/`setNew` are
/// the public fields).
#[derive(Clone, PartialEq, Debug, Default)]
pub struct Change {
    /// The stored document (empty when the upsert creates it).
    pub old: Document,
    /// The document to store.
    pub new: Document,
}

impl Change {
    /// `new Change($old, $new)`.
    pub fn new(old: Document, new: Document) -> Self {
        Self { old, new }
    }
}
