//! Document reads by id and document writes.
//!
//! PHP: `getDocument()`, `createDocument(s)()`, `updateDocument(s)()`, `increaseDocumentAttribute()`, `deleteDocument(s)()`, `getSequences()`, `rawQuery()`, `rawMutation()` of `SQL` and its subclasses.

#![allow(unused_variables, clippy::unused_async)]

use crate::collection::Collection;
use crate::document::Document;
use crate::error::Result;
use crate::query::Query;
use crate::value::Value;

use super::Sql;

impl Sql {
    /// `createDocument()`
    pub(crate) async fn create_document(&self, collection: &Collection, document: Document) -> Result<Document> {
        todo!("port `createDocument()`")
    }

    /// `createDocuments()`
    pub(crate) async fn create_documents(
        &self,
        collection: &Collection,
        documents: Vec<Document>,
    ) -> Result<Vec<Document>> {
        todo!("port `createDocuments()`")
    }

    /// `getDocument()`
    pub(crate) async fn get_document(
        &self,
        collection: &Collection,
        id: &str,
        queries: Vec<Query>,
        for_update: bool,
    ) -> Result<Document> {
        todo!("port `getDocument()`")
    }

    /// `updateDocument()`
    pub(crate) async fn update_document(
        &self,
        collection: &Collection,
        id: &str,
        document: Document,
        skip_permissions: bool,
    ) -> Result<Document> {
        todo!("port `updateDocument()`")
    }

    /// `updateDocuments()`
    pub(crate) async fn update_documents(
        &self,
        collection: &Collection,
        updates: &Document,
        documents: Vec<Document>,
        skip_permissions: &[String],
    ) -> Result<i64> {
        todo!("port `updateDocuments()`")
    }

    /// `increaseDocumentAttribute()`
    #[allow(clippy::too_many_arguments)]
    pub(crate) async fn increase_document_attribute(
        &self,
        collection: &Collection,
        id: &str,
        attribute: &str,
        value: Value,
        updated_at: &str,
        min: Option<Value>,
        max: Option<Value>,
    ) -> Result<bool> {
        todo!("port `increaseDocumentAttribute()`")
    }

    /// `deleteDocument()`
    pub(crate) async fn delete_document(&self, collection: &Collection, id: &str) -> Result<bool> {
        todo!("port `deleteDocument()`")
    }

    /// `deleteDocuments()`
    pub(crate) async fn delete_documents(
        &self,
        collection: &Collection,
        sequences: Vec<Value>,
        permission_ids: Vec<String>,
    ) -> Result<i64> {
        todo!("port `deleteDocuments()`")
    }

    /// `getSequences()`
    pub(crate) async fn get_sequences(
        &self,
        collection: &Collection,
        documents: Vec<Document>,
    ) -> Result<Vec<Document>> {
        todo!("port `getSequences()`")
    }

    /// `rawQuery()`
    pub(crate) async fn raw_query(&self, query: &str, bindings: utopia_query::Array) -> Result<Vec<Document>> {
        todo!("port `rawQuery()`")
    }

    /// `rawMutation()`
    pub(crate) async fn raw_mutation(&self, query: &str, bindings: utopia_query::Array) -> Result<i64> {
        todo!("port `rawMutation()`")
    }
}
