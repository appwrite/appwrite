//! Attributes.
//!
//! PHP: `Trait\Attributes`.

use super::Database;
use crate::attribute::{Attribute, Update};
use crate::error::Result;

impl Database {
    /// Adds an attribute to a collection (`createAttribute()`).
    pub async fn create_attribute(&self, collection: &str, attribute: Attribute) -> Result<Attribute> {
        let _ = (collection, attribute);
        todo!("schema area: createAttribute")
    }

    /// Adds attributes to a collection at once (`createAttributes()`).
    pub async fn create_attributes(&self, collection: &str, attributes: Vec<Attribute>) -> Result<Vec<Attribute>> {
        let _ = (collection, attributes);
        todo!("schema area: createAttributes")
    }

    /// Changes an attribute (`updateAttribute()`).
    pub async fn update_attribute(&self, collection: &str, key: &str, update: Update) -> Result<Attribute> {
        let _ = (collection, key, update);
        todo!("schema area: updateAttribute")
    }

    /// Whether the collection can take the attribute (`checkAttribute()`).
    pub async fn check_attribute(&self, collection: &str, attribute: &Attribute) -> Result<bool> {
        let _ = (collection, attribute);
        todo!("schema area: checkAttribute")
    }

    /// Removes an attribute (`deleteAttribute()`).
    pub async fn delete_attribute(&self, collection: &str, key: &str) -> Result<()> {
        let _ = (collection, key);
        todo!("schema area: deleteAttribute")
    }

    /// Renames an attribute (`renameAttribute()`).
    pub async fn rename_attribute(&self, collection: &str, old: &str, new: &str) -> Result<()> {
        let _ = (collection, old, new);
        todo!("schema area: renameAttribute")
    }
}
