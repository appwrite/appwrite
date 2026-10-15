//! Collections.
//!
//! PHP: `Trait\Collections`.

use super::Database;
use crate::collection::{Collection, Update};
use crate::document::Document;
use crate::error::Result;
use crate::event::{Domain, Event};

impl Database {
    /// Creates a collection: its storage, then its definition in the
    /// metadata collection (`createCollection()`).
    ///
    /// Fails ([`crate::Kind::Duplicate`]) for an existing collection,
    /// ([`crate::Kind::Limit`]) beyond the adapter's limits, and as the
    /// definition validators do.
    pub async fn create_collection(&self, collection: Collection) -> Result<Collection> {
        let _ = collection;
        todo!("schema area: createCollection")
    }

    /// Changes a collection's permissions or document security
    /// (`updateCollection()`).
    pub async fn update_collection(&self, collection: &str, update: Update) -> Result<Collection> {
        let _ = (collection, update);
        todo!("schema area: updateCollection")
    }

    /// The collection's definition (`getCollection()`).
    ///
    /// Fails ([`crate::Kind::NotFound`]) for a missing collection
    /// (`Collection not found`).
    pub async fn get_collection(&self, collection: &str) -> Result<Collection> {
        match self.find_collection(collection).await? {
            Some(c) => Ok(c),
            None => Err(crate::Error::not_found("Collection not found")),
        }
    }

    /// The collection's definition, `None` when it does not exist or
    /// belongs to another tenant under shared tables (`findCollection()`).
    pub async fn find_collection(&self, collection: &str) -> Result<Option<Collection>> {
        if collection == super::METADATA {
            let definition = super::collection_definition();
            self.trigger(Event::CollectionRead, || Domain::CollectionRead {
                collection: definition.id().to_owned(),
                definition: definition.clone(),
            })
            .await?;
            return Ok(Some(definition));
        }
        let (stored, epoch) =
            self.silent(self.get_document_with_epoch(super::METADATA, collection, Vec::new(), false)).await?;
        if stored.id().is_empty() {
            return Ok(None);
        }
        let adapter = &self.inner.adapter;
        if adapter.shared_tables()
            && let Some(tenant) = stored.tenant()
            && !tenant.strict_eq(&adapter.tenant())
        {
            return Ok(None);
        }
        let mut definition = self.to_collection(stored)?;
        definition.set_cache_epoch(epoch);
        self.trigger(Event::CollectionRead, || Domain::CollectionRead {
            collection: definition.id().to_owned(),
            definition: definition.clone(),
        })
        .await?;
        Ok(Some(definition))
    }

    /// The collections' definitions (`listCollections()`).
    pub async fn list_collections(&self, limit: i64, offset: i64) -> Result<Vec<Collection>> {
        let _ = (limit, offset);
        todo!("schema area: listCollections")
    }

    /// The size of a collection's data (`getSizeOfCollection()`).
    pub async fn size_of_collection(&self, collection: &str) -> Result<i64> {
        let _ = collection;
        todo!("schema area: getSizeOfCollection")
    }

    /// The size of a collection on disk (`getSizeOfCollectionOnDisk()`).
    pub async fn size_of_collection_on_disk(&self, collection: &str) -> Result<i64> {
        let _ = collection;
        todo!("schema area: getSizeOfCollectionOnDisk")
    }

    /// Refreshes a collection's planner statistics (`analyzeCollection()`).
    pub async fn analyze_collection(&self, collection: &str) -> Result<bool> {
        let _ = collection;
        todo!("schema area: analyzeCollection")
    }

    /// Deletes a collection, its relationships and its storage
    /// (`deleteCollection()`).
    pub async fn delete_collection(&self, collection: &str) -> Result<()> {
        let _ = collection;
        todo!("schema area: deleteCollection")
    }

    /// A metadata document as a [`Collection`] (`toCollection()`).
    pub(crate) fn to_collection(&self, definition: Document) -> Result<Collection> {
        Collection::from_array(definition.into_array())
    }
}
