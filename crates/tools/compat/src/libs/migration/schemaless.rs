//! The Memory adapter without defined attributes: PHP's `db.new` with
//! `schemaless`, an anonymous `Memory` subclass whose `capabilities()` drops
//! `Capability::DefinedAttributes`. Every other call runs on Memory.

use std::any::Any;
use std::sync::Arc;

use async_trait::async_trait;
use utopia_database::adapter::memory::Memory;
use utopia_database::adapter::{Find, Limits, Relationships, State};
use utopia_database::capability::Capabilities;
use utopia_database::collection::Collection;
use utopia_database::{Adapter, Attribute, Capability, Document, Index, Query, Result, Value, schema};
use utopia_query::schema::ColumnType;

/// Memory, schemaless.
#[derive(Debug, Default)]
pub(super) struct Schemaless {
    inner: Memory,
}

impl Schemaless {
    pub(super) fn new() -> Self {
        Schemaless { inner: Memory::new() }
    }
}

#[async_trait]
impl Adapter for Schemaless {
    fn state(&self) -> &State {
        self.inner.state()
    }

    fn as_any(&self) -> &dyn Any {
        self
    }

    fn capabilities(&self) -> Capabilities {
        let mut out = Vec::new();
        for c in self.inner.capabilities().iter() {
            if c != Capability::DefinedAttributes {
                out.push(c);
            }
        }
        Capabilities::of(&out)
    }

    fn limits(&self) -> Arc<Limits> {
        self.inner.limits()
    }

    fn relationships(&self) -> Option<&dyn Relationships> {
        self.inner.relationships()
    }

    async fn start_transaction(&self) -> Result<bool> {
        self.inner.start_transaction().await
    }

    async fn commit_transaction(&self) -> Result<bool> {
        self.inner.commit_transaction().await
    }

    async fn rollback_transaction(&self) -> Result<bool> {
        self.inner.rollback_transaction().await
    }

    async fn create(&self, name: &str) -> Result<bool> {
        self.inner.create(name).await
    }

    async fn update(&self, name: &str, new: &str) -> Result<bool> {
        self.inner.update(name, new).await
    }

    async fn exists(&self, database: &str) -> Result<bool> {
        self.inner.exists(database).await
    }

    async fn collection_exists(&self, database: &str, collection: &str) -> Result<bool> {
        self.inner.collection_exists(database, collection).await
    }

    async fn list(&self) -> Result<Vec<Document>> {
        self.inner.list().await
    }

    async fn delete(&self, name: &str) -> Result<bool> {
        self.inner.delete(name).await
    }

    async fn create_collection(&self, collection: &str, attributes: &[Attribute], indexes: &[Index]) -> Result<bool> {
        self.inner.create_collection(collection, attributes, indexes).await
    }

    async fn delete_collection(&self, collection: &str) -> Result<bool> {
        self.inner.delete_collection(collection).await
    }

    async fn analyze_collection(&self, collection: &str) -> Result<bool> {
        self.inner.analyze_collection(collection).await
    }

    async fn size_of_collection(&self, collection: &str) -> Result<i64> {
        self.inner.size_of_collection(collection).await
    }

    async fn size_of_collection_on_disk(&self, collection: &str) -> Result<i64> {
        self.inner.size_of_collection_on_disk(collection).await
    }

    fn attribute_width(&self, collection: &Collection) -> Result<i64> {
        self.inner.attribute_width(collection)
    }

    async fn create_attribute(&self, collection: &str, attribute: &Attribute) -> Result<bool> {
        self.inner.create_attribute(collection, attribute).await
    }

    async fn create_attributes(&self, collection: &str, attributes: &[Attribute]) -> Result<bool> {
        self.inner.create_attributes(collection, attributes).await
    }

    async fn update_attribute(&self, collection: &str, key: &str, attribute: &Attribute) -> Result<bool> {
        self.inner.update_attribute(collection, key, attribute).await
    }

    async fn delete_attribute(&self, collection: &str, key: &str) -> Result<bool> {
        self.inner.delete_attribute(collection, key).await
    }

    async fn rename_attribute(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        self.inner.rename_attribute(collection, old, new).await
    }

    async fn schema_attributes(&self, collection: &str) -> Result<Vec<schema::Column>> {
        self.inner.schema_attributes(collection).await
    }

    async fn schema_indexes(&self, collection: &str) -> Result<Vec<schema::Index>> {
        self.inner.schema_indexes(collection).await
    }

    fn column_type(&self, attribute: &Attribute) -> Result<Option<String>> {
        self.inner.column_type(attribute)
    }

    async fn create_index(
        &self,
        collection: &str,
        index: &Index,
        attribute_types: &[(String, ColumnType)],
        collation: &[(String, String)],
    ) -> Result<bool> {
        self.inner.create_index(collection, index, attribute_types, collation).await
    }

    async fn delete_index(&self, collection: &str, key: &str) -> Result<bool> {
        self.inner.delete_index(collection, key).await
    }

    async fn rename_index(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        self.inner.rename_index(collection, old, new).await
    }

    async fn create_document(&self, collection: &Collection, document: Document) -> Result<Document> {
        self.inner.create_document(collection, document).await
    }

    async fn create_documents(&self, collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>> {
        self.inner.create_documents(collection, documents).await
    }

    async fn get_document(
        &self,
        collection: &Collection,
        id: &str,
        queries: Vec<Query>,
        for_update: bool,
    ) -> Result<Document> {
        self.inner.get_document(collection, id, queries, for_update).await
    }

    async fn update_document(
        &self,
        collection: &Collection,
        id: &str,
        document: Document,
        skip_permissions: bool,
    ) -> Result<Document> {
        self.inner.update_document(collection, id, document, skip_permissions).await
    }

    async fn update_documents(
        &self,
        collection: &Collection,
        updates: &Document,
        documents: Vec<Document>,
        skip_permissions: &[String],
    ) -> Result<i64> {
        self.inner.update_documents(collection, updates, documents, skip_permissions).await
    }

    #[allow(clippy::too_many_arguments)]
    async fn increase_document_attribute(
        &self,
        collection: &Collection,
        id: &str,
        attribute: &str,
        value: Value,
        updated_at: &str,
        min: Option<Value>,
        max: Option<Value>,
    ) -> Result<bool> {
        self.inner.increase_document_attribute(collection, id, attribute, value, updated_at, min, max).await
    }

    async fn delete_document(&self, collection: &Collection, id: &str) -> Result<bool> {
        self.inner.delete_document(collection, id).await
    }

    async fn delete_documents(
        &self,
        collection: &Collection,
        sequences: Vec<Value>,
        permission_ids: Vec<String>,
    ) -> Result<i64> {
        self.inner.delete_documents(collection, sequences, permission_ids).await
    }

    async fn find(&self, collection: &Collection, queries: Vec<Query>, options: Find) -> Result<Vec<Document>> {
        self.inner.find(collection, queries, options).await
    }

    async fn count(&self, collection: &Collection, queries: Vec<Query>, max: Option<i64>) -> Result<i64> {
        self.inner.count(collection, queries, max).await
    }

    async fn sum(
        &self,
        collection: &Collection,
        attribute: &str,
        queries: Vec<Query>,
        max: Option<i64>,
    ) -> Result<Value> {
        self.inner.sum(collection, attribute, queries, max).await
    }

    async fn get_sequences(&self, collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>> {
        self.inner.get_sequences(collection, documents).await
    }
}
