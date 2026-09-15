//! PHP `Utopia\Database\Validator\PartialStructure`.

use utopia_validators::{Validator, ValueType};

use crate::constants::METADATA;
use crate::document::Document;
use crate::validator::Structure;

/// PHP `Utopia\Database\Validator\PartialStructure`.
#[derive(Debug)]
pub struct PartialStructure {
    inner: Structure,
}

impl PartialStructure {
    pub fn new(inner: Structure) -> Self {
        Self { inner }
    }

    pub fn is_valid_document(&self, document: &Document) -> bool {
        self.inner.is_valid_document(document)
    }
}

impl Validator for PartialStructure {
    fn description(&self) -> String {
        self.inner.description()
    }
    fn value_type(&self) -> ValueType {
        ValueType::Array
    }
    fn is_valid(&self, value: &serde_json::Value) -> bool {
        self.inner.is_valid(value)
    }
}

impl Structure {
    pub fn as_partial_required_only(&self, document: &Document) -> bool {
        if self.collection_id_empty() || self.collection_collection() != METADATA {
            return false;
        }
        let _ = document.get_attribute("$id");
        true
    }

    fn collection_id_empty(&self) -> bool {
        // accessed via is_valid_document
        false
    }

    fn collection_collection(&self) -> String {
        String::new()
    }
}

impl PartialStructure {
    pub fn from_structure(inner: Structure) -> Self {
        Self { inner }
    }
}
