//! [`Relationships`]: relationship side effects of document writes,
//! population of related documents on reads, and relationship queries.
//!
//! PHP: `Utopia\Database\Hook\Relationships`, `Hook\Cascade`,
//! `Hook\Preparation`, `Hook\PreparedCreate`.

use std::future::Future;
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::{Arc, Mutex, Weak};

use crate::attribute::Attribute;
use crate::collection::Collection;
use crate::database::{BoxFuture, Database, Inner, Resolve, Selects};
use crate::document::Document;
use crate::error::{Error, Result};
use crate::hook::Nested;
use crate::query::Query;
use crate::state::{Scoped, Snapshot};

/// One relationship a cascading delete is following: the relationship
/// attribute, the collection declaring it and the document whose delete
/// reached it.
///
/// PHP: `Utopia\Database\Hook\Cascade` (internal).
#[derive(Debug, Clone, PartialEq)]
pub struct Cascade {
    /// `$collection`.
    pub collection: String,
    /// `$document`.
    pub document: String,
    /// `$attribute`.
    pub attribute: Attribute,
}

/// How [`Relationships`] prepares the related documents of a write.
///
/// PHP: `Utopia\Database\Hook\Preparation` (internal).
#[derive(Clone, Default)]
pub struct Preparation {
    /// The hooks each related document is given to, in order, before it is
    /// checked (`$hooks`).
    pub hooks: Vec<Arc<dyn Nested>>,
    /// Whether a related document may only add permissions for roles the
    /// caller holds (`$check`).
    pub check: bool,
    /// Whether stored permissions are read locked (`$lock`).
    pub lock: bool,
    /// The staged view of a related document (`$resolve`); `None` reads the
    /// stored one.
    pub resolve: Option<Resolve>,
}

impl std::fmt::Debug for Preparation {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Preparation").field("check", &self.check).field("lock", &self.lock).finish_non_exhaustive()
    }
}

/// One create whose new related documents the hook prepares before writing
/// them.
///
/// PHP: `Utopia\Database\Hook\PreparedCreate`.
#[derive(Debug, Clone, Default)]
pub struct PreparedCreate {
    /// Whether the prepared documents wait to be written together, in a
    /// savepoint that can take the attempt back (`$deferred`).
    pub deferred: bool,
    /// The prepared documents not written yet, each after its collection, in
    /// the order they are to be written (`$documents`).
    pub documents: Vec<(Collection, Document)>,
    /// The documents not finished preparing, by collection id and document
    /// id (`$preparing`).
    pub preparing: Vec<(String, Vec<String>)>,
    /// The collections read while preparing, by id (`$collections`).
    pub collections: Vec<(String, Collection)>,
}

/// The relationships hook: relates the related documents of created and
/// updated documents, applies on-delete rules, populates related documents
/// on reads, and turns queries on relationship attributes into queries the
/// adapter runs.
///
/// A hook is bound to the one database it is attached to; attach a
/// [`Relationships::fresh`] copy to another.
///
/// PHP: `Utopia\Database\Hook\Relationships`. Its per-coroutine state (the
/// write and delete stacks, the prepared creates, the documents being
/// related) is scoped to the futures of each write here.
pub struct Relationships {
    /// `$prepare`: whether a create whose related documents are all new
    /// prepares them instead of creating each through `createDocument()`.
    prepare: bool,
    database: Mutex<Option<Weak<Inner>>>,
    enabled: Scoped<bool>,
    check_exist: Scoped<bool>,
    in_batch_population: Scoped<bool>,
    fetch_depth: AtomicI64,
    /// The collections of the relationship writes in progress, innermost
    /// last (`$writeStacks[$coroutine]`).
    write_stack: Scoped<Arc<Vec<String>>>,
    /// The relationships of the cascading deletes in progress
    /// (`$deleteStacks[$coroutine]`).
    delete_stack: Scoped<Arc<Vec<Cascade>>>,
}

impl std::fmt::Debug for Relationships {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Relationships").field("prepare", &self.prepare).finish_non_exhaustive()
    }
}

impl Default for Relationships {
    fn default() -> Self {
        Relationships::new(true)
    }
}

impl Relationships {
    /// The most chunks of related ids one population reads at the same time
    /// (`READ_CONCURRENCY`).
    pub const READ_CONCURRENCY: usize = 4;

    /// A hook (`new Relationships($prepare)`).
    pub fn new(prepare: bool) -> Self {
        Relationships {
            prepare,
            database: Mutex::new(None),
            enabled: Scoped::new(true),
            check_exist: Scoped::new(true),
            in_batch_population: Scoped::new(false),
            fetch_depth: AtomicI64::new(0),
            write_stack: Scoped::new(Arc::new(Vec::new())),
            delete_stack: Scoped::new(Arc::new(Vec::new())),
        }
    }

    /// A copy configured like this hook, with none of its state and attached
    /// to no database (`clone $hook`).
    pub fn fresh(&self) -> Self {
        Relationships::new(self.prepare)
    }

    /// Binds the hook to `database` (`attach()`); attaching again to the
    /// same database does nothing.
    ///
    /// Fails ([`crate::Kind::Database`]) when the hook is attached to
    /// another database.
    pub fn attach(&self, database: &Database) -> Result<()> {
        let mut slot = self.database.lock().unwrap_or_else(|e| e.into_inner());
        if let Some(current) = slot.as_ref().and_then(Weak::upgrade)
            && !Arc::ptr_eq(&current, &database.inner)
        {
            return Err(Error::database(
                "This relationships hook is already attached to another database; attach a clone of it instead",
            ));
        }
        *slot = Some(Arc::downgrade(&database.inner));
        Ok(())
    }

    /// The database the hook is attached to.
    ///
    /// Fails ([`crate::Kind::Database`]) when it is attached to none, or the
    /// database is gone.
    pub(crate) fn database(&self) -> Result<Database> {
        let slot = self.database.lock().unwrap_or_else(|e| e.into_inner());
        match slot.as_ref().and_then(Weak::upgrade) {
            Some(inner) => Ok(Database { inner }),
            None => Err(Error::database("The relationships hook is not attached to a database")),
        }
    }

    /// Whether relationships are populated and written (`isEnabled()`).
    pub fn is_enabled(&self) -> bool {
        self.enabled.get()
    }

    /// `setEnabled()`.
    pub fn set_enabled(&self, enabled: bool) {
        self.enabled.set(enabled);
    }

    /// Runs `future` with relationships enabled or disabled (`withEnabled()`).
    pub async fn with_enabled<F: Future>(&self, enabled: bool, future: F) -> F::Output {
        self.enabled.with(enabled, future).await
    }

    /// Whether related documents must exist before they are linked
    /// (`shouldCheckExist()`).
    pub fn should_check_exist(&self) -> bool {
        self.check_exist.get()
    }

    /// Runs `future` with existence checks on or off (`withCheckExist()`).
    pub async fn with_check_exist<F: Future>(&self, check: bool, future: F) -> F::Output {
        self.check_exist.with(check, future).await
    }

    /// How many relationship writes the current future is inside
    /// (`getWriteStackCount()`).
    pub fn write_stack_count(&self) -> i64 {
        self.write_stack.get().len() as i64
    }

    /// `getFetchDepth()`.
    pub fn fetch_depth(&self) -> i64 {
        self.fetch_depth.load(Ordering::SeqCst)
    }

    /// Whether a relationship population is running (`isInBatchPopulation()`).
    pub fn is_in_batch_population(&self) -> bool {
        self.in_batch_population.get()
    }

    /// Runs `future` under the relationship state a snapshot carries
    /// (`withSnapshot()`).
    pub async fn with_snapshot<F: Future>(&self, snapshot: &Snapshot, future: F) -> F::Output {
        self.enabled
            .with(
                snapshot.relationships,
                self.check_exist.with(snapshot.exist_check, self.in_batch_population.with(snapshot.population, future)),
            )
            .await
    }

    /// Relates the related documents of a new document, replacing its
    /// relationship values with what is stored for them
    /// (`afterDocumentCreate()`). The caller retrying a transaction gives it
    /// the document it was given again (PHP's `$copies` and `restore()`).
    pub(crate) fn after_document_create<'a>(
        &'a self,
        collection: &'a Collection,
        document: Document,
    ) -> BoxFuture<'a, Result<Document>> {
        Box::pin(async move {
            let _ = (collection, document);
            todo!("relationships area: afterDocumentCreate")
        })
    }

    /// Relates the related documents an update changes (`afterDocumentUpdate()`).
    pub(crate) fn after_document_update<'a>(
        &'a self,
        collection: &'a Collection,
        old: &'a Document,
        document: Document,
    ) -> BoxFuture<'a, Result<Document>> {
        Box::pin(async move {
            let _ = (collection, old, document);
            todo!("relationships area: afterDocumentUpdate")
        })
    }

    /// Applies the on-delete rule of each relationship before `document` is
    /// deleted (`beforeDocumentDelete()`); with `report`, returns the
    /// documents on the other side of a two-way relationship the delete
    /// leaves changed.
    ///
    /// Fails ([`crate::Kind::Restricted`]) when a restricting relationship
    /// holds the document.
    pub(crate) fn before_document_delete<'a>(
        &'a self,
        collection: &'a Collection,
        document: &'a Document,
        report: bool,
    ) -> BoxFuture<'a, Result<Vec<Document>>> {
        Box::pin(async move {
            let _ = (collection, document, report);
            todo!("relationships area: beforeDocumentDelete")
        })
    }

    /// The documents with their related documents populated, `fetch_depth`
    /// levels down at most, as `selects` select them (`populateDocuments()`).
    pub(crate) fn populate_documents<'a>(
        &'a self,
        documents: Vec<Document>,
        collection: &'a Collection,
        fetch_depth: i64,
        selects: Selects,
    ) -> BoxFuture<'a, Result<Vec<Document>>> {
        Box::pin(async move {
            let _ = (documents, collection, fetch_depth, selects);
            todo!("relationships area: populateDocuments")
        })
    }

    /// The nested selections of the select queries naming relationship
    /// paths (`processQueries()`), which it rewrites to select the
    /// relationship keys the populate needs.
    ///
    /// Fails ([`crate::Kind::Query`]) for a select value that is not a string.
    pub(crate) fn process_queries(&self, relationships: &[Attribute], queries: &mut [Query]) -> Result<Selects> {
        let _ = (relationships, queries);
        todo!("relationships area: processQueries")
    }

    /// Queries on relationship attributes turned into queries on the ids
    /// they match (`convertQueries()`); `None` when no document can match.
    pub(crate) fn convert_queries<'a>(
        &'a self,
        relationships: &'a [Attribute],
        queries: Vec<Query>,
        collection: Option<&'a Collection>,
    ) -> BoxFuture<'a, Result<Option<Vec<Query>>>> {
        Box::pin(async move {
            let _ = (relationships, queries, collection);
            todo!("relationships area: convertQueries")
        })
    }

    /// Each nested related document of a write prepared by the nested hooks,
    /// and its grants checked, as deep as the write relates it
    /// (`prepareNested()`).
    pub(crate) fn prepare_nested<'a>(
        &'a self,
        collection: &'a Collection,
        document: Document,
        preparation: &'a Preparation,
    ) -> BoxFuture<'a, Result<Document>> {
        Box::pin(async move {
            let _ = (collection, document, preparation);
            todo!("relationships area: prepareNested")
        })
    }

    /// Checks, without writing, that the caller may relate the related
    /// documents `document` carries the way writing it relates them
    /// (`authorize()`).
    pub(crate) fn authorize<'a>(
        &'a self,
        collection: &'a Collection,
        document: &'a Document,
        old: Option<&'a Document>,
        resolve: Option<Resolve>,
    ) -> BoxFuture<'a, Result<()>> {
        Box::pin(async move {
            let _ = (collection, document, old, resolve, &self.write_stack, &self.delete_stack);
            todo!("relationships area: authorize")
        })
    }
}
