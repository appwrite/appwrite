//! Attributes and their columns.
//!
//! PHP: the attribute methods and column types (`getSqlType()`, `getColumnType()`, `addTableColumn()`, ...) of `SQL` and its subclasses.

#![allow(unused_variables, clippy::unused_async)]

use crate::attribute::Attribute;
use crate::error::Result;

use super::Sql;

impl Sql {
    /// `createAttribute()`
    pub(crate) async fn create_attribute(&self, collection: &str, attribute: &Attribute) -> Result<bool> {
        todo!("port `createAttribute()`")
    }

    /// `createAttributes()`
    pub(crate) async fn create_attributes(&self, collection: &str, attributes: &[Attribute]) -> Result<bool> {
        todo!("port `createAttributes()`")
    }

    /// `updateAttribute()`
    pub(crate) async fn update_attribute(&self, collection: &str, key: &str, attribute: &Attribute) -> Result<bool> {
        todo!("port `updateAttribute()`")
    }

    /// `relaxAttributeRequired()`
    pub(crate) async fn relax_attribute_required(&self, collection: &str, id: &str) -> Result<bool> {
        todo!("port `relaxAttributeRequired()`")
    }

    /// `deleteAttribute()`
    pub(crate) async fn delete_attribute(&self, collection: &str, key: &str) -> Result<bool> {
        todo!("port `deleteAttribute()`")
    }

    /// `renameAttribute()`
    pub(crate) async fn rename_attribute(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        todo!("port `renameAttribute()`")
    }

    /// `getColumnType()`
    pub(crate) fn column_type(&self, attribute: &Attribute) -> Result<Option<String>> {
        todo!("port `getColumnType()`")
    }
}
