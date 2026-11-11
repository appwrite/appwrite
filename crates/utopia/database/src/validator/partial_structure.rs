//! [`PartialStructure`]: partial documents (the attributes of an update)
//! against their collection.
//!
//! PHP: `Utopia\Database\Validator\PartialStructure`.

use crate::adapter::Profile;
use crate::document::Document;
use crate::error::Result;
use crate::validator::structure::{OperatorCheck, Structure, internal_attributes};
use crate::value::{Ext, Value};

/// A [`Structure`] check of the attributes a document has: it need not
/// name its collection or carry required attributes, except the required
/// internal attributes it does carry (even as `null`).
///
/// PHP: `Utopia\Database\Validator\PartialStructure` (the constructor of
/// `Structure`).
#[derive(Clone, Debug)]
pub struct PartialStructure {
    structure: Structure,
}

impl PartialStructure {
    /// `new PartialStructure($collection, $profile)`.
    pub fn new(collection: Document, profile: Profile) -> Self {
        Self { structure: Structure::new(collection, profile) }
    }

    /// The same validator with `$currentDocument` ([`Structure::current_document`]).
    #[must_use]
    pub fn current_document(self, current: Option<Document>) -> Self {
        Self { structure: self.structure.current_document(current) }
    }

    /// The same validator with `$storedAttributes` ([`Structure::stored_attributes`]).
    #[must_use]
    pub fn stored_attributes<S: Into<String>>(self, keys: impl IntoIterator<Item = S>) -> Self {
        Self { structure: self.structure.stored_attributes(keys) }
    }

    /// The same validator checking operators with `check` ([`Structure::operators`]).
    #[must_use]
    pub fn operators(self, check: OperatorCheck) -> Self {
        Self { structure: self.structure.operators(check) }
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
    /// [`PartialStructure::validate`] (leaving it as it is), anything else
    /// invalid.
    ///
    /// Fails as [`Structure::validate`] does.
    pub fn is_valid(&self, value: &Value) -> Result<bool> {
        match value {
            Value::Ext(Ext::Document(d)) => self.run(d, None),
            _ => self.structure.fail("Value must be an instance of Document"),
        }
    }

    /// `isValid($document)`.
    ///
    /// Fails as [`Structure::validate`] does.
    pub fn validate(&self, document: &mut Document) -> Result<bool> {
        let copy = document.clone();
        self.run(&copy, Some(document))
    }

    fn run(&self, document: &Document, write: Option<&mut Document>) -> Result<bool> {
        let s = &self.structure;
        if !s.collection_ok() {
            return s.fail("Collection not found");
        }
        let structure = document.array_copy();
        let definitions = s.definitions()?;
        let required =
            internal_attributes().iter().filter(|a| a.required && document.as_array().get_str(&a.key).is_some());
        if !s.check_required(&structure, required) {
            return Ok(false);
        }
        if !s.check_unknown(&structure, definitions) {
            return Ok(false);
        }
        s.check_values(write, &structure, definitions)
    }
}

crate::validator::validator!(PartialStructure, utopia_validators::Type::Array, false, fallible);
