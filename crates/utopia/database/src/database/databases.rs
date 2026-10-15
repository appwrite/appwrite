//! Databases: `create()`, `exists()`, `collectionExists()`, `list()`,
//! `update()`, `delete()`.
//!
//! PHP: `Trait\Databases`.

use std::future::Future;

use futures_util::StreamExt;

use super::{Database, Finally, METADATA};
use crate::document::Document;
use crate::error::{Error, Result};
use crate::event::{Domain, Event};
use crate::permission::PermissionType;

impl Database {
    /// Creates the database `name` (this one by default) and its metadata
    /// collection (`create()`).
    ///
    /// Fails ([`crate::Kind::Refused`]) when the adapter does not create it
    /// (`Failed to create database`).
    pub async fn create(&self, name: Option<&str>) -> Result<bool> {
        let database = name.map_or_else(|| self.inner.adapter.database(), str::to_owned);
        if !self.inner.adapter.create(&database).await? {
            return Err(Error::refused("Failed to create database"));
        }
        self.silent(self.create_collection(super::collection_definition())).await?;
        self.trigger(Event::DatabaseCreate, || Domain::DatabaseCreated { database }).await?;
        Ok(true)
    }

    /// Whether the database exists (`exists()`).
    pub async fn exists(&self, name: Option<&str>) -> Result<bool> {
        let database = name.map_or_else(|| self.inner.adapter.database(), str::to_owned);
        self.inner.adapter.exists(&database).await
    }

    /// Whether a collection's storage exists in the database
    /// (`collectionExists()`).
    pub async fn collection_exists(&self, collection: &str, database: Option<&str>) -> Result<bool> {
        let database = database.map_or_else(|| self.inner.adapter.database(), str::to_owned);
        self.inner.adapter.collection_exists(&database, collection).await
    }

    /// The databases (`list()`).
    pub async fn list(&self) -> Result<Vec<Document>> {
        let databases = self.inner.adapter.list().await?;
        let listeners = self.listens(Event::DatabaseList);
        if !listeners.is_empty() {
            self.dispatch(&Domain::DatabaseListed { databases: databases.clone() }, &listeners).await?;
        }
        Ok(databases)
    }

    /// Renames a database (`update()`). The definitions and documents cached
    /// under either name are retired, and a database that was current stays
    /// current under its new name.
    ///
    /// Fails ([`crate::Kind::Database`]) under shared tables (`Cannot rename
    /// a database while shared tables are enabled`), as the adapter does
    /// ([`crate::Kind::Duplicate`], [`crate::Kind::NotFound`]), and
    /// ([`crate::Kind::Refused`]) when the adapter does not rename it.
    pub async fn update(&self, database: &str, new: &str) -> Result<bool> {
        let adapter = &self.inner.adapter;
        if adapter.shared_tables() {
            return Err(Error::database("Cannot rename a database while shared tables are enabled"));
        }
        let collections =
            if adapter.exists(database).await? { self.in_database(database, self.collection_ids()).await? } else { Vec::new() };
        if !adapter.update(database, new).await? {
            return Err(Error::refused(format!("Failed to rename database '{database}' to '{new}'")));
        }
        for name in [database, new] {
            self.in_database(name, self.purge_cached_collections(&collections)).await?;
        }
        if adapter.database() == crate::adapter::filter(database) {
            self.set_database(new);
        }
        self.trigger(Event::DatabaseUpdate, || Domain::DatabaseUpdated {
            database: database.to_owned(),
            new: new.to_owned(),
        })
        .await?;
        Ok(true)
    }

    /// Deletes the database (this one by default) and flushes the cache
    /// (`delete()`).
    ///
    /// Fails ([`crate::Kind::Refused`]) when the adapter does not drop it
    /// (`Failed to delete database`).
    pub async fn delete(&self, name: Option<&str>) -> Result<bool> {
        let database = name.map_or_else(|| self.inner.adapter.database(), str::to_owned);
        if !self.inner.adapter.delete(&database).await? {
            return Err(Error::refused("Failed to delete database"));
        }
        self.cache().flush().await?;
        self.trigger(Event::DatabaseDelete, || Domain::DatabaseDeleted { database }).await?;
        Ok(true)
    }

    /// Runs `future` with the adapter on `database`, restoring the current
    /// one after (`inDatabase()`).
    async fn in_database<F: Future>(&self, database: &str, future: F) -> F::Output {
        let adapter = self.inner.adapter.clone();
        let current = adapter.database();
        if current == crate::adapter::filter(database) {
            return future.await;
        }
        adapter.set_database(database);
        let _restore = Finally::new(move || adapter.set_database(&current));
        future.await
    }

    /// The ids of every collection the metadata collection lists
    /// (`getCollectionIds()`).
    async fn collection_ids(&self) -> Result<Vec<String>> {
        let authorization = self.authorization();
        self.silent(authorization.skip(async {
            let mut ids = Vec::new();
            let mut definitions = self.cursor(METADATA, Vec::new(), 25, PermissionType::Read).await?;
            while let Some(definition) = definitions.next().await {
                ids.push(definition?.id().to_owned());
            }
            Ok(ids)
        }))
        .await
    }

    /// Retires the cached documents of `collections` and the metadata
    /// collection's query cache (`purgeCachedCollections()`).
    async fn purge_cached_collections(&self, collections: &[String]) -> Result<()> {
        for collection in collections {
            self.purge_cached_collection(collection).await?;
        }
        if let Some(query_cache) = self.query_cache() {
            query_cache.invalidate_collection(&self.query_cache_scope(None).await, METADATA).await?;
        }
        Ok(())
    }
}
