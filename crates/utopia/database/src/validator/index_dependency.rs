//! [`IndexDependency`]: whether an attribute can be deleted or renamed
//! while indexes use it.
//!
//! PHP: `Utopia\Database\Validator\IndexDependency`.

use crate::attribute::Attribute;
use crate::document::Document;
use crate::error::Result;
use crate::index::Index;
use crate::value::{Ext, Value};

/// An attribute that no index uses, when the adapter indexes array
/// attributes through casts (`cast_index_support`) and the attribute is an
/// array; any attribute otherwise.
///
/// ```
/// use utopia_database::{Attribute, Index};
/// use utopia_database::validator::index_dependency::IndexDependency;
///
/// let validator = IndexDependency::new(vec![Index::key("idx", ["Tags"], vec![], vec![]).unwrap()], true);
/// assert!(!validator.check(&Attribute::string("tags", 10).array(true)));
/// assert!(validator.check(&Attribute::string("tags", 10)));
/// assert_eq!(validator.description(), "Attribute can't be deleted or renamed because it is used in an index");
/// ```
///
/// PHP: `Utopia\Database\Validator\IndexDependency` (`new
/// IndexDependency($indexes, $castIndexSupport)`).
#[derive(Clone, Debug)]
pub struct IndexDependency {
    indexes: Vec<Index>,
    cast_index_support: bool,
}

impl IndexDependency {
    /// `new IndexDependency($indexes, $castIndexSupport)`.
    pub fn new(indexes: Vec<Index>, cast_index_support: bool) -> Self {
        Self { indexes, cast_index_support }
    }

    /// `new IndexDependency($indexes, ...)` of stored index documents.
    ///
    /// Fails as [`Index::from_document`] does.
    pub fn from_documents(indexes: &[Document], cast_index_support: bool) -> Result<Self> {
        let indexes = indexes.iter().map(Index::from_document).collect::<Result<Vec<_>>>()?;
        Ok(Self::new(indexes, cast_index_support))
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        "Attribute can't be deleted or renamed because it is used in an index".to_owned()
    }

    /// `isValid($attribute)`.
    pub fn check(&self, attribute: &Attribute) -> bool {
        if !self.cast_index_support || !attribute.array {
            return true;
        }
        let key = attribute.key.to_ascii_lowercase();
        !self.indexes.iter().any(|index| index.attributes.iter().any(|a| a.to_ascii_lowercase() == key))
    }

    /// `isValid($value)` of any value: an attribute document as
    /// [`IndexDependency::check`] of its model.
    ///
    /// Fails (as PHP throws) when the value is not an attribute document
    /// ([`crate::Kind::TypeError`]) or not a valid one (as
    /// [`Attribute::from_document`]), with cast index support.
    pub fn is_valid(&self, value: &Value) -> Result<bool> {
        if !self.cast_index_support {
            return Ok(true);
        }
        match value {
            Value::Ext(Ext::Document(d)) => Ok(self.check(&Attribute::from_document(d)?)),
            other => Err(crate::Error::new(
                crate::Kind::TypeError,
                format!(
                    "Utopia\\Database\\Attribute::fromDocument(): Argument #1 ($document) must be of type Utopia\\Database\\Document, {} given",
                    crate::document::value_name(other)
                ),
            )),
        }
    }

    /// `getType()`: `object`.
    pub fn type_name(&self) -> &'static str {
        "object"
    }
}

super::validator!(IndexDependency, utopia_validators::Type::Object, false, fallible);
