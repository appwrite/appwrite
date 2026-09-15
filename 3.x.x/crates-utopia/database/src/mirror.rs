//! PHP `Utopia\Database\Mirror` and `Mirroring\Filter`.

use crate::adapter::Adapter;
use crate::database::Database;
use crate::document::Document;
use crate::error::DatabaseError;
use crate::query::Query;

/// Filter applied when mirroring writes (PHP `Utopia\Database\Mirroring\Filter`).
pub trait MirrorFilter: Send + Sync {
    /// Called before a document is created on the mirror.
    fn on_create(&self, collection: &str, document: &Document) -> Result<bool, DatabaseError>;
    /// Called before a document is updated on the mirror.
    fn on_update(&self, collection: &str, document: &Document) -> Result<bool, DatabaseError>;
    /// Called before a document is deleted on the mirror.
    fn on_delete(&self, collection: &str, document: &Document) -> Result<bool, DatabaseError>;
}

/// Dual-write database (PHP `Utopia\Database\Mirror`).
pub struct Mirror<A: Adapter, B: Adapter> {
    source: Database<A>,
    destination: Database<B>,
}

impl<A: Adapter, B: Adapter> std::fmt::Debug for Mirror<A, B> {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Mirror").finish_non_exhaustive()
    }
}

impl<A: Adapter, B: Adapter> Mirror<A, B> {
    /// Create a mirror of `source` onto `destination`.
    pub fn new(source: Database<A>, destination: Database<B>) -> Self {
        Self {
            source,
            destination,
        }
    }

    /// The source database.
    pub fn source(&self) -> &Database<A> {
        &self.source
    }

    /// The destination database.
    pub fn destination(&self) -> &Database<B> {
        &self.destination
    }

    /// Mutable source.
    pub fn source_mut(&mut self) -> &mut Database<A> {
        &mut self.source
    }

    /// Mutable destination.
    pub fn destination_mut(&mut self) -> &mut Database<B> {
        &mut self.destination
    }
}

/// No-op filter that always mirrors.
#[derive(Debug, Default, Clone, Copy)]
pub struct AllowAllFilter;

impl MirrorFilter for AllowAllFilter {
    fn on_create(&self, _collection: &str, _document: &Document) -> Result<bool, DatabaseError> {
        Ok(true)
    }
    fn on_update(&self, _collection: &str, _document: &Document) -> Result<bool, DatabaseError> {
        Ok(true)
    }
    fn on_delete(&self, _collection: &str, _document: &Document) -> Result<bool, DatabaseError> {
        Ok(true)
    }
}

/// Mirror a find query onto both adapters (used by tests).
pub fn mirror_queries(queries: &[Query]) -> Vec<Query> {
    queries.to_vec()
}
