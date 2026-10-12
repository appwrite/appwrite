//! [`PartialStructure`]: partial documents (the attributes of an update)
//! against their collection.
//!
//! PHP: `Utopia\Database\Validator\PartialStructure`.

use crate::document::Document;
use crate::error::Result;
use crate::validator::structure::{Structure, StructureOptions, internal_attributes};
use crate::validator::{array_key, index, index_or};
use crate::value::{Array, Ext, Value};

/// A [`Structure`] check of the attributes a document has: it need not
/// name its collection or carry required attributes, except internal
/// required ones it does carry.
///
/// PHP: `Utopia\Database\Validator\PartialStructure` (the constructor of
/// `Structure`).
#[derive(Clone, Debug)]
pub struct PartialStructure {
    structure: Structure,
}

impl PartialStructure {
    /// `new PartialStructure($collection, $idAttributeType)` with PHP's defaults.
    pub fn new(collection: Document, id_attribute_type: impl Into<String>) -> Self {
        Self { structure: Structure::new(collection, id_attribute_type) }
    }

    /// `new PartialStructure(...)` with every option.
    pub fn with_options(collection: Document, id_attribute_type: impl Into<String>, options: StructureOptions) -> Self {
        Self { structure: Structure::with_options(collection, id_attribute_type, options) }
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        self.structure.description()
    }

    /// `getType()`: `array`.
    pub fn type_name(&self) -> &'static str {
        "array"
    }

    /// `isValid($value)` of any value: a document as
    /// [`PartialStructure::validate`] (on a copy), anything else invalid.
    pub fn is_valid(&self, value: &Value) -> Result<bool> {
        match value {
            Value::Ext(Ext::Document(d)) => self.validate(&mut d.as_ref().clone()),
            _ => self.structure.fail("Value must be an instance of Document"),
        }
    }

    /// `isValid($document)`.
    pub fn validate(&self, document: &mut Document) -> Result<bool> {
        let s = &self.structure;
        if !s.collection_ok() {
            return s.fail("Collection not found");
        }
        let structure = document.array_copy(&[], &[]);
        let internal = internal_attributes();
        let mut attributes = internal.clone();
        attributes.extend(s.collection_attributes());
        let mut keys = Array::new();
        let empty = Value::from("");
        for attribute in &attributes {
            keys.set(array_key(index_or(attribute, "$id", &empty)), attribute.clone());
        }
        let required: Vec<Value> = internal
            .into_iter()
            .filter(|a| {
                index(a, "required").strict_eq(&Value::Bool(true))
                    && document.has(&crate::validator::text(index(a, "$id")))
            })
            .collect();
        if !s.check_required(&structure, &required, &mut keys) {
            return Ok(false);
        }
        if !s.check_unknown(&structure, &keys) {
            return Ok(false);
        }
        s.check_values(document, &structure, &keys)
    }
}

crate::validator::validator!(PartialStructure, utopia_validators::Type::Array, false, fallible);
