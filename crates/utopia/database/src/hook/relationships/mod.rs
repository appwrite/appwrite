//! [`Relationships`]: relationship side effects of document writes,
//! population of related documents on reads, and relationship queries.
//!
//! PHP: `Utopia\Database\Hook\Relationships`, `Hook\Cascade`,
//! `Hook\Preparation`, `Hook\PreparedCreate`.
//!
//! The PHP class is split by concern:
//!
//! | File | PHP |
//! |---|---|
//! | `mod.rs` | the state, `attach()`, the scopes, the chunked reads, the shared checks (`authorizeLink()`, `refuseUnreadable()`, ...) |
//! | `create.rs` | `afterDocumentCreate()` and the prepared creates (`relatePrepared()`, `collectCreated()`, `prepare()`, `relate()`, `relateDocuments()`, ...) |
//! | `update.rs` | `afterDocumentUpdate()`, `applyRelationshipOperator()`, `linkRelatedDocuments()` |
//! | `delete.rs` | `beforeDocumentDelete()` and the on-delete rules |
//! | `populate.rs` | `populateDocuments()` and the batches of each relationship type |
//! | `queries.rs` | `processQueries()`, `convertQueries()` and the nested paths |
//! | `nested.rs` | `prepareNested()` and the grants of nested documents |
//! | `authorize.rs` | `authorize()` |
//!
//! # Documents are values
//!
//! A PHP `Document` is an object shared by every reference to it: relating
//! a document changes it in place, and populating related documents sets
//! the same related object on every document that holds it, then keeps
//! changing it level after level. Here documents are values: the writes
//! take a document and return it, and a population keeps every document it
//! reads in an arena and resolves which holds which once it is done (see
//! `populate.rs`). PHP's `$copies`/`restore()`, which give a retried
//! attempt the documents it was given back, are not needed: every attempt
//! starts from a copy of the documents it was given.
//!
//! # Coroutines
//!
//! PHP keeps the write and delete stacks, the prepared creates, the
//! one-by-one replays and the documents being related per coroutine, in
//! arrays keyed by coroutine id. Here each is a [`Scoped`] value, overridden
//! for the futures of the write that changes it.

mod authorize;
mod create;
mod delete;
mod nested;
mod populate;
mod queries;
mod update;

#[cfg(test)]
mod engine;
#[cfg(test)]
mod tests;

use std::future::Future;
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::{Arc, Mutex, Weak};

use futures_util::StreamExt;
use futures_util::stream::FuturesOrdered;

use crate::attribute::Attribute;
use crate::collection::Collection;
use crate::database::{BoxFuture, Database, Inner, RELATION_QUERY_CHUNK_SIZE, Resolve};
use crate::document::{self, Document};
use crate::error::{Error, Kind, Result};
use crate::hook::Nested;
use crate::permission::PermissionType;
use crate::query::Query;
use crate::relationship::RelationshipSide;
use crate::state::{Scoped, Snapshot};
use crate::validator::authorization::Input;
use crate::value::{self, Ext, Value};

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

impl PreparedCreate {
    /// `new PreparedCreate($deferred)`.
    pub fn new(deferred: bool) -> Self {
        PreparedCreate { deferred, ..PreparedCreate::default() }
    }

    /// `isset($prepared->preparing[$collection][$id])`.
    pub(crate) fn is_preparing(&self, collection: &str, id: &str) -> bool {
        self.preparing.iter().any(|(c, ids)| c == collection && ids.iter().any(|i| i == id))
    }

    /// `$prepared->preparing[$collection][$id] = true`.
    pub(crate) fn start_preparing(&mut self, collection: &str, id: &str) {
        match self.preparing.iter_mut().find(|(c, _)| c == collection) {
            Some((_, ids)) => {
                if !ids.iter().any(|i| i == id) {
                    ids.push(id.to_owned());
                }
            }
            None => self.preparing.push((collection.to_owned(), vec![id.to_owned()])),
        }
    }

    /// `unset($prepared->preparing[$collection][$id])` (the collection's
    /// entry stays, possibly empty, as in PHP).
    pub(crate) fn stop_preparing(&mut self, collection: &str, id: &str) {
        if let Some((_, ids)) = self.preparing.iter_mut().find(|(c, _)| c == collection) {
            ids.retain(|i| i != id);
        }
    }

    /// `$prepared->collections[$id] ?? null`.
    pub(crate) fn collection(&self, id: &str) -> Option<&Collection> {
        self.collections.iter().find(|(c, _)| c == id).map(|(_, c)| c)
    }

    /// `$prepared->collections[$id] ??= $collection`.
    pub(crate) fn remember(&mut self, collection: Collection) {
        if self.collection(collection.id()).is_none() {
            self.collections.push((collection.id().to_owned(), collection));
        }
    }
}

/// A prepared create, shared by the writes that prepare its documents.
pub(crate) type Prepared = Arc<Mutex<PreparedCreate>>;

/// Locks a prepared create for one read or write; never held across an
/// `.await`.
pub(crate) fn lock(prepared: &Prepared) -> std::sync::MutexGuard<'_, PreparedCreate> {
    prepared.lock().unwrap_or_else(|e| e.into_inner())
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
    /// Whether a create in progress prepares its related documents
    /// (`isset($prepared[$coroutine])`).
    preparing: Scoped<bool>,
    /// How many creates are relating one document at a time
    /// (`$replays[$coroutine]`).
    replays: Scoped<u32>,
    /// The new documents being related before they are written, as
    /// (collection id, document id) (`$relating[$coroutine]`).
    relating: Scoped<Arc<Vec<(String, String)>>>,
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
            preparing: Scoped::new(false),
            replays: Scoped::new(0),
            relating: Scoped::new(Arc::new(Vec::new())),
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

    /// The collections of the relationship writes the current future is
    /// inside (`$this->writeStacks[$coroutine] ?? []`).
    fn current_write_stack(&self) -> Arc<Vec<String>> {
        self.write_stack.get()
    }

    /// Runs `future` inside a relationship write of `collection`: pushed on
    /// the write stack for it, popped after (`$this->writeStacks[$coroutine][]
    /// = ...` and `leaveWrite()`).
    async fn in_write<F: Future>(&self, collection: &str, future: F) -> F::Output {
        let mut stack = self.current_write_stack().as_ref().clone();
        stack.push(collection.to_owned());
        self.write_stack.with(Arc::new(stack), future).await
    }

    /// The ids one chunked query reads at most (`relationQueryChunkSize()`):
    /// capped by [`RELATION_QUERY_CHUNK_SIZE`] as a memory bound, but never
    /// larger than the configured `maxQueryValues`, so the chunked reads and
    /// writes pass the query validator.
    fn relation_query_chunk_size(&self, database: &Database) -> usize {
        RELATION_QUERY_CHUNK_SIZE.min(database.max_query_values()).max(1) as usize
    }

    /// Runs one read per chunk and returns their documents in chunk order
    /// (`readChunks()`). Several reads run at the same time only on a pooled
    /// adapter (its `read_concurrency()` above one), at most as many as
    /// [`Relationships::READ_CONCURRENCY`] and the pool allow; each starts
    /// from its caller's authorization, relationship and silence state (the
    /// caller's snapshot), and a failure stops the reads not started yet.
    async fn read_chunks<'a>(
        &self,
        database: &Database,
        reads: Vec<BoxFuture<'a, Result<Vec<Document>>>>,
    ) -> Result<Vec<Document>> {
        let concurrency = self.read_concurrency(database, reads.len());
        let chunks: Vec<Vec<Document>> = if concurrency > 1 {
            let snapshot = database.snapshot();
            let mut pending = reads.into_iter();
            let mut running = FuturesOrdered::new();
            for read in pending.by_ref().take(concurrency) {
                let read: BoxFuture<'_, Result<Vec<Document>>> = Box::pin(database.with_snapshot(&snapshot, read));
                running.push_back(read);
            }
            let mut chunks = Vec::new();
            while let Some(chunk) = running.next().await {
                chunks.push(chunk?);
                if let Some(read) = pending.next() {
                    let read: BoxFuture<'_, Result<Vec<Document>>> = Box::pin(database.with_snapshot(&snapshot, read));
                    running.push_back(read);
                }
            }
            chunks
        } else {
            let mut chunks = Vec::with_capacity(reads.len());
            for read in reads {
                chunks.push(read.await?);
            }
            chunks
        };
        Ok(chunks.into_iter().flatten().collect())
    }

    /// How many chunk reads run at the same time (`readConcurrency()`).
    fn read_concurrency(&self, database: &Database, reads: usize) -> usize {
        if reads < 2 {
            return 1;
        }
        let pool = database.adapter().read_concurrency();
        if pool <= 1 {
            return 1;
        }
        reads.min(Self::READ_CONCURRENCY).min(pool)
    }

    /// The documents of `read` over each chunk of `ids`, one chunk after the
    /// other (`readByIds()`).
    async fn read_by_ids<'a, F>(&self, database: &Database, ids: &[String], read: F) -> Result<Vec<Document>>
    where
        F: Fn(Vec<String>) -> BoxFuture<'a, Result<Vec<Document>>>,
    {
        let mut documents = Vec::new();
        for chunk in ids.chunks(self.relation_query_chunk_size(database)) {
            documents.extend(read(chunk.to_vec()).await?);
        }
        Ok(documents)
    }

    /// Linking an existing document to another one needs update permission
    /// on it, whichever relationship type holds the link (`authorizeLink()`).
    ///
    /// Fails ([`crate::Kind::Authorization`]) without it.
    fn authorize_link(&self, database: &Database, collection: &Collection, related: &Document) -> Result<()> {
        let authorization = database.authorization();
        if !authorization.is_valid(&permission_input(collection, related, PermissionType::Update)?) {
            return Err(Error::authorization(authorization.description()));
        }
        Ok(())
    }

    /// Refuses a related document the caller's read came back empty for
    /// although it is stored (`refuseUnreadable()`): a nested write never
    /// links, changes or creates over a document the caller cannot read. The
    /// refusal gives the reason of `check`, or of read when the caller passes
    /// `check`.
    ///
    /// Fails ([`crate::Kind::Authorization`]).
    async fn refuse_unreadable(
        &self,
        database: &Database,
        collection: &Collection,
        id: &str,
        check: PermissionType,
    ) -> Result<()> {
        let authorization = database.authorization();
        if id.is_empty() || !authorization.status() {
            return Ok(());
        }
        let stored = authorization
            .skip(database.skip_relationships(database.skip_query_hooks(database.get_document(
                collection.id(),
                id,
                vec![Query::select([document::ID])],
                false,
            ))))
            .await?;
        if stored.is_empty() {
            return Ok(());
        }
        self.refuse_hidden(database, collection, &stored, check)
    }

    /// Refuses `hidden`, a related document the caller cannot read, for the
    /// reason of `check`, or of read when the caller passes `check`
    /// (`refuseHidden()`).
    fn refuse_hidden(
        &self,
        database: &Database,
        collection: &Collection,
        hidden: &Document,
        check: PermissionType,
    ) -> Result<()> {
        let authorization = database.authorization();
        let types: &[PermissionType] =
            if check == PermissionType::Read { &[PermissionType::Read] } else { &[check, PermissionType::Read] };
        for kind in types {
            if !authorization.is_valid(&permission_input(collection, hidden, *kind)?) {
                return Err(Error::authorization(authorization.description()));
            }
        }
        Ok(())
    }
}

/// The attributes `collection` declares (`self::attributes()`).
fn attributes(collection: &Collection) -> Result<Vec<Attribute>> {
    Ok(collection.attributes()?.to_vec())
}

/// The relationship attributes `collection` declares (`self::relationships()`).
fn relationships(collection: &Collection) -> Result<Vec<Attribute>> {
    Ok(collection.attributes()?.iter().filter(|a| a.relationship.is_some()).cloned().collect())
}

/// The junction collection of a many-to-many relationship
/// (`getJunctionCollection()`): `_<parent sequence>_<child sequence>`.
fn junction(collection: &Collection, related: &Collection, side: RelationshipSide) -> String {
    let sequence = |c: &Collection| c.sequence().map(value::to_text).unwrap_or_default();
    match side {
        RelationshipSide::Parent => format!("_{}_{}", sequence(collection), sequence(related)),
        RelationshipSide::Child => format!("_{}_{}", sequence(related), sequence(collection)),
    }
}

/// A document of `value` (`new Document($value)`), a structure failure as a
/// relationship failure (`Invalid relationship value. ...`).
fn to_document(value: value::Array) -> Result<Document> {
    Document::new(value).map_err(|e| {
        if e.is_a(Kind::Structure) {
            Error::relationship(format!("Invalid relationship value. {}", e.message()))
        } else {
            e
        }
    })
}

/// The relationship value `key` of `document`, an array that is not a list
/// made a document and set back (`coerceToDocument()`).
///
/// Fails ([`crate::Kind::Relationship`]) for an array that is not a valid
/// document.
fn coerce_to_document(document: &mut Document, key: &str) -> Result<Option<Value>> {
    let value = document.get(key).cloned();
    if let Some(Value::Array(array)) = &value
        && !array.is_list()
    {
        let coerced = Value::from(to_document(array.clone())?);
        document.put(key, coerced.clone());
        return Ok(Some(coerced));
    }
    Ok(value)
}

/// [`coerce_to_document`] without setting the document back.
fn coerced(value: Option<&Value>) -> Result<Option<Value>> {
    match value {
        Some(Value::Array(array)) if !array.is_list() => Ok(Some(Value::from(to_document(array.clone())?))),
        other => Ok(other.cloned()),
    }
}

/// The permissions of a document as a value (`$document->getPermissions()`).
fn permissions_value(document: &Document) -> Result<Value> {
    Ok(value::list(document.permissions()?.into_iter().map(value::string)))
}

/// `$value instanceof Document ? $value->getId() : (is_string($value) ? $value : null)`.
fn id_of(value: &Value) -> Option<String> {
    match value {
        Value::Ext(Ext::Document(d)) => Some(d.id().to_owned()),
        Value::Str(_) => Some(value::to_text(value)),
        _ => None,
    }
}

/// The permissions granting `kind` on `document`: its collection's, and its
/// own under document security (`permissionInput()`).
fn permission_input(collection: &Collection, document: &Document, kind: PermissionType) -> Result<Input> {
    let mut permissions = collection.permissions_by_type(kind)?;
    if collection.get(crate::collection::DOCUMENT_SECURITY).is_some_and(Value::truthy) {
        permissions.extend(document.permissions_by_type(kind)?);
    }
    Ok(Input::new(kind, permissions))
}

/// `Query::or([Query::isNull($twoWayKey), Query::notEqual($twoWayKey, $documentId)])`
/// (`notReferencing()`).
fn not_referencing(two_way_key: &str, document_id: &str) -> Query {
    Query::or([Query::is_null(two_way_key), Query::not_equal(two_way_key, document_id)])
}

/// `array_values(array_unique($strings))`.
fn unique(strings: Vec<String>) -> Vec<String> {
    let mut out: Vec<String> = Vec::with_capacity(strings.len());
    for s in strings {
        if !out.contains(&s) {
            out.push(s);
        }
    }
    out
}

/// `array_values(array_diff($strings, $remove))`.
fn diff(strings: &[String], remove: &[String]) -> Vec<String> {
    strings.iter().filter(|s| !remove.contains(s)).cloned().collect()
}

/// `array_values(array_intersect($strings, $keep))`.
fn intersect(strings: &[String], keep: &[String]) -> Vec<String> {
    strings.iter().filter(|s| keep.contains(s)).cloned().collect()
}

/// `empty($string)`.
fn empty(s: &str) -> bool {
    s.is_empty() || s == "0"
}
