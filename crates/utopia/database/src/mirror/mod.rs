//! [`Mirror`]: a database whose writes are replicated to a second one.
//!
//! A mirror is a [`Database`] over its source's adapter and cache: it reads
//! as the source does, and every write it makes runs on the source and,
//! once that succeeded, is applied to the destination (when it has one)
//! through the write [`Filter`]s. A failure on the destination is only
//! reported to the [`Mirror::on_error`] callbacks: the source stays the
//! truth. Document writes reach the destination only for collections the
//! source's `upgrades` collection marks `upgraded`, which a collection
//! created through the mirror is.
//!
//! [`Mirror::new`] returns the mirror as a [`Mirror`] handle, which derefs to
//! the [`Database`] it is; that database (and every clone of it) behaves as
//! the mirror whatever handle it is reached through, as PHP's `Mirror`
//! extends `Database`.
//!
//! PHP applies a replication in a coroutine of its own when called inside
//! one; the Rust port applies every replication before the write returns,
//! as PHP does outside a coroutine, so [`Mirror::await_replications`] never
//! waits.
//!
//! ```
//! # tokio::runtime::Runtime::new().unwrap().block_on(async {
//! use std::sync::Arc;
//!
//! use utopia_cache::{Cache, Memory};
//! use utopia_database::adapter::memory::Memory as MemoryAdapter;
//! use utopia_database::mirror::Mirror;
//! use utopia_database::{Attribute, Collection, Database, Document, Value};
//!
//! let cache = || Arc::new(Cache::from_dyn(Arc::new(Memory::new())));
//! let source = Database::new(Arc::new(MemoryAdapter::new()), cache());
//! let destination = Database::new(Arc::new(MemoryAdapter::new()), cache());
//! for db in [&source, &destination] {
//!     db.set_database("app");
//!     db.create(None).await.unwrap();
//! }
//!
//! let mirror = Mirror::new(source, Some(destination.clone()), Vec::new());
//! mirror.on_error(Arc::new(|failure: &utopia_database::mirror::Failure| {
//!     eprintln!("{}: {}", failure.method, failure.error.message());
//!     Ok(())
//! }));
//! let people = Collection::create("people", "", vec![Attribute::string("name", 64)], vec![], None, false, Default::default()).unwrap();
//! mirror.create_collection(people).await.unwrap();
//! let mut ada = Document::default();
//! ada.put("$id", Value::from("ada")).put("name", Value::from("Ada"));
//! mirror.authorization().skip(mirror.create_document("people", ada)).await.unwrap();
//!
//! let copy = destination.authorization().skip(destination.get_document("people", "ada", vec![], false)).await.unwrap();
//! assert_eq!(copy.get("name"), Some(&Value::from("Ada")));
//! # });
//! ```
//!
//! PHP: `Utopia\Database\Mirror`.

mod filter;
#[cfg(all(test, feature = "memory"))]
mod tests;

pub use filter::Filter;

use std::ops::Deref;
use std::sync::{Arc, Mutex};

use async_trait::async_trait;

use crate::attribute::{self, Attribute, Patch};
use crate::collection::{self, Collection};
use crate::database::{Callback, Database, LENGTH_KEY, METADATA};
use crate::document::{self, Document};
use crate::error::{Error, Result};
use crate::event::{Domain, Event};
use crate::index::Index;
use crate::permission::PermissionType;
use crate::query::Query;
use crate::relationship::{self, Relationship};
use crate::value::Value;

/// A change the destination of a [`Mirror`] failed to apply: the
/// [`Database`] method that made it (PHP's name, `createDocument`), the
/// event of that change when it has one, and the error the destination
/// raised.
///
/// PHP: `Utopia\Database\Mirror\Failure`.
#[derive(Debug, Clone)]
pub struct Failure {
    /// The method (`createDocument`, `updateCollection`, ...).
    pub method: String,
    /// The event of the change, if it has one.
    pub event: Option<Event>,
    /// The error the destination raised.
    pub error: Error,
}

impl Failure {
    /// A failure (`new Failure($method, $event, $error)`).
    pub fn new(method: impl Into<String>, event: Option<Event>, error: Error) -> Self {
        Failure { method: method.into(), event, error }
    }
}

/// A callback [`Mirror::on_error`] registers. An error it returns leaves the
/// mirror's call, as an exception thrown from PHP's callback does.
pub type ErrorCallback = Arc<dyn Fn(&Failure) -> Result<()> + Send + Sync>;

/// A mirror's change applied as PHP's `delegateInOrder()` does: on the
/// source (its failure reaches the caller), then on the destination (its
/// failure is reported to the error callbacks); the source's result.
macro_rules! in_order {
    ($parts:expr, $method:literal, |$db:ident| $call:expr) => {{
        let parts = $parts;
        let result = {
            let $db = &parts.source;
            Box::pin($call).await?
        };
        if let Some($db) = &parts.destination {
            let attempt = Box::pin($call).await;
            parts.report($method, attempt)?;
        }
        Ok(result)
    }};
}
pub(crate) use in_order;

/// Collections only the source keeps (`SOURCE_ONLY_COLLECTIONS`).
const SOURCE_ONLY_COLLECTIONS: [&str; 1] = ["upgrades"];

/// The id of the collection that marks which collections the destination
/// has.
const UPGRADES: &str = "upgrades";

/// A database whose writes are replicated to a destination. See the
/// [module docs](self).
///
/// PHP: `Utopia\Database\Mirror`.
#[derive(Clone, Debug)]
pub struct Mirror {
    database: Database,
}

impl Deref for Mirror {
    type Target = Database;

    fn deref(&self) -> &Database {
        &self.database
    }
}

impl From<Mirror> for Database {
    fn from(mirror: Mirror) -> Database {
        mirror.database
    }
}

/// What a mirror holds besides the [`Database`] it is.
pub(crate) struct Parts {
    /// `$source`.
    pub(crate) source: Database,
    /// `$destination`.
    pub(crate) destination: Option<Database>,
    /// `$writeFilters`.
    filters: Vec<Arc<dyn Filter>>,
    /// `$errorCallbacks`.
    callbacks: Mutex<Vec<ErrorCallback>>,
}

impl Mirror {
    /// A mirror of `source` onto `destination` (none: a database over the
    /// source that replicates nothing), with `filters` around every
    /// replicated change. The mirror is a [`Database`] over the source's
    /// adapter and cache, and uses the source's authorization; the source
    /// and the destination keep their own.
    ///
    /// PHP: `new Mirror($source, $destination, $filters)`.
    pub fn new(source: Database, destination: Option<Database>, filters: Vec<Arc<dyn Filter>>) -> Mirror {
        let database = Database::new(source.adapter().clone(), source.cache());
        database.set_authorization(source.authorization());
        let parts = Parts { source, destination, filters, callbacks: Mutex::new(Vec::new()) };
        database.inner.mirror.get_or_init(|| Arc::new(parts));
        Mirror { database }
    }

    /// The mirror a database is, if it is one.
    pub fn of(database: &Database) -> Option<Mirror> {
        database.inner.mirror.get().map(|_| Mirror { database: database.clone() })
    }

    /// The database the mirror is.
    pub fn database(&self) -> &Database {
        &self.database
    }

    fn parts(&self) -> &Parts {
        self.database.inner.mirror.get().expect("a mirror has its parts")
    }

    /// PHP: `getSource()`.
    pub fn source(&self) -> &Database {
        &self.parts().source
    }

    /// PHP: `getDestination()`.
    pub fn destination(&self) -> Option<&Database> {
        self.parts().destination.as_ref()
    }

    /// Adds a callback that is given every change the destination fails to
    /// apply (`onError()`).
    pub fn on_error(&self, callback: ErrorCallback) -> &Self {
        self.parts().callbacks.lock().unwrap_or_else(|e| e.into_inner()).push(callback);
        self
    }

    /// Waits until every replication queued through the mirror so far has
    /// reached the destination or has been reported, or until `timeout`
    /// milliseconds have passed (`awaitReplications()`; `None` waits as
    /// long as they take). Replications are applied before each write
    /// returns, so there is nothing to wait for.
    ///
    /// Fails ([`crate::Kind::Database`]) for a negative timeout (`A
    /// replication timeout cannot be negative, <n> given`).
    pub fn await_replications(&self, timeout: Option<i64>) -> Result<()> {
        if let Some(timeout) = timeout
            && timeout < 0
        {
            return Err(Error::database(format!("A replication timeout cannot be negative, {timeout} given")));
        }
        Ok(())
    }

    /// Creates the `upgrades` collection on the source, unless it exists
    /// (`createUpgrades()`).
    ///
    /// Fails as [`Database::create_collection`] does.
    pub async fn create_upgrades(&self) -> Result<()> {
        self.parts().create_upgrades().await
    }
}

/// Whether the `upgrades` row of a collection marks it upgraded.
fn upgraded(upgrade: &Option<Document>) -> bool {
    matches!(
        upgrade.as_ref().and_then(|u| u.get("status")),
        Some(Value::Str(s)) if s.as_bytes() == b"upgraded"
    )
}

/// The event of the change a mirror method makes (`eventOf()`).
fn event_of(method: &str) -> Option<Event> {
    Some(match method {
        "create" => Event::DatabaseCreate,
        "delete" => Event::DatabaseDelete,
        "createCollection" => Event::CollectionCreate,
        "updateCollection" => Event::CollectionUpdate,
        "deleteCollection" => Event::CollectionDelete,
        "createAttribute" => Event::AttributeCreate,
        "createAttributes" => Event::AttributesCreate,
        "updateAttribute" => Event::AttributeUpdate,
        "deleteAttribute" => Event::AttributeDelete,
        "createIndex" => Event::IndexCreate,
        "createIndexes" => Event::IndexesCreate,
        "renameIndex" => Event::IndexRename,
        "deleteIndex" => Event::IndexDelete,
        "createDocument" => Event::DocumentCreate,
        "createDocuments" => Event::DocumentsCreate,
        "updateDocument" => Event::DocumentUpdate,
        "updateDocuments" => Event::DocumentsUpdate,
        "upsertDocument" => Event::DocumentUpsert,
        "upsertDocuments" => Event::DocumentsUpsert,
        "deleteDocument" => Event::DocumentDelete,
        "deleteDocuments" => Event::DocumentsDelete,
        "increaseDocumentAttribute" => Event::DocumentIncrease,
        "decreaseDocumentAttribute" => Event::DocumentDecrease,
        _ => return None,
    })
}

/// The update the destination applies (`filteredUpdate()`): every field the
/// source update set or a write filter changed, at the filtered value, and
/// nothing else, so an unchanged column is not rewritten.
fn filtered_update(update: &attribute::Update, source: &Attribute, filtered: &Attribute) -> attribute::Update {
    let format_changed = filtered.format.as_ref().map(|f| &f.name) != source.format.as_ref().map(|f| &f.name)
        || !options_identical(filtered.format.as_ref(), source.format.as_ref());
    attribute::Update {
        kind: (update.kind.is_some() || filtered.kind != source.kind).then_some(filtered.kind),
        size: if update.size.is_some() || filtered.size != source.size { filtered.size } else { None },
        required: (update.required.is_some() || filtered.required != source.required).then_some(filtered.required),
        default: if update.changes_default() || !filtered.default.strict_eq(&source.default) {
            Patch::Set(filtered.default.clone())
        } else {
            Patch::Unchanged
        },
        signed: (update.signed.is_some() || filtered.signed != source.signed).then_some(filtered.signed),
        array: (update.array.is_some() || filtered.array != source.array).then_some(filtered.array),
        format: if update.changes_format() || format_changed {
            Patch::Set(filtered.format.clone())
        } else {
            Patch::Unchanged
        },
        filters: (update.filters.is_some() || filtered.filters != source.filters)
            .then(|| filtered.filters.iter().map(|f| f.as_str().into()).collect()),
        key: (update.key.is_some() || filtered.key != source.key).then(|| filtered.key.clone()),
    }
}

/// `$a?->options !== $b?->options`, negated.
fn options_identical(a: Option<&attribute::Format>, b: Option<&attribute::Format>) -> bool {
    match (a, b) {
        (None, None) => true,
        (Some(a), Some(b)) => Value::Array(a.options.clone()).strict_eq(&Value::Array(b.options.clone())),
        _ => false,
    }
}

/// `$onNext` as the mirror hands it to the source (`decorating()`): each
/// document decorated by the mirror's decorators before the caller's
/// callback sees it.
struct Decorating<'a> {
    mirror: &'a Database,
    event: Event,
    collection: &'a str,
    callback: &'a mut dyn Callback<(Document, Option<Document>)>,
}

#[async_trait]
impl Callback<(Document, Option<Document>)> for Decorating<'_> {
    async fn call(&mut self, (document, previous): (Document, Option<Document>)) -> Result<()> {
        let parts = self.mirror.inner.mirror.get().expect("a mirror has its parts");
        let document = parts.decorate(self.mirror, self.event, self.collection, document).await?;
        self.callback.call((document, previous)).await
    }
}

impl Parts {
    /// Reports a destination failure to every callback (`logError()`).
    pub(crate) fn log_error(&self, method: &str, error: Error) -> Result<()> {
        let failure = Failure::new(method, event_of(method), error);
        let callbacks = self.callbacks.lock().unwrap_or_else(|e| e.into_inner()).clone();
        for callback in callbacks {
            callback(&failure)?;
        }
        Ok(())
    }

    /// Reports `result`'s error, if any (the `catch` of every replication).
    pub(crate) fn report<T>(&self, method: &str, result: Result<T>) -> Result<()> {
        match result {
            Ok(_) => Ok(()),
            Err(e) => self.log_error(method, e),
        }
    }

    /// `createUpgrades()`.
    pub(crate) async fn create_upgrades(&self) -> Result<()> {
        if self.source.find_collection(UPGRADES).await?.is_some() {
            return Ok(());
        }
        let upgrades = Collection::create(
            UPGRADES,
            "",
            vec![Attribute::string("collectionId", LENGTH_KEY).required(true), Attribute::string("status", LENGTH_KEY)],
            vec![
                Index::unique("_unique_collection", ["collectionId"], vec![Some(LENGTH_KEY)], Vec::new())?,
                Index::key(
                    "_status_index",
                    ["status"],
                    vec![Some(LENGTH_KEY)],
                    vec![Some(utopia_query::OrderDirection::Asc)],
                )?,
            ],
            None,
            true,
            Default::default(),
        )?;
        self.source.create_collection(upgrades).await?;
        Ok(())
    }

    /// The source's `upgrades` row of a collection (`getUpgradeStatus()`):
    /// an empty document for `upgrades` and the metadata collection, `None`
    /// when it cannot be read.
    async fn upgrade_status(&self, collection: &str) -> Option<Document> {
        if collection == UPGRADES || collection == METADATA {
            return Some(Document::default());
        }
        let source = &self.source;
        source
            .authorization()
            .skip(source.skip_query_hooks(source.get_document(UPGRADES, collection, Vec::new(), false)))
            .await
            .ok()
    }

    /// The destination a document write on `collection` replicates to: none
    /// for a source-only collection, without a destination, or for a
    /// collection the source has not marked upgraded.
    async fn replicating(&self, mirror: &Database, collection: &str) -> Option<&Database> {
        if SOURCE_ONLY_COLLECTIONS.contains(&collection) {
            return None;
        }
        let destination = self.destination.as_ref()?;
        let upgrade = mirror.silent(Box::pin(self.upgrade_status(collection))).await;
        upgraded(&upgrade).then_some(destination)
    }

    /// A document one of the mirror's writes returns, decorated by the
    /// mirror's decorators (`decorate()`); the source has none of them.
    pub(crate) async fn decorate(
        &self,
        mirror: &Database,
        event: Event,
        collection: &str,
        document: Document,
    ) -> Result<Document> {
        if !mirror.has_decorators() {
            return Ok(document);
        }
        let definition = mirror.silent(Box::pin(mirror.get_collection(collection))).await?;
        mirror.decorate_document(event, &definition, document).await
    }

    /// Runs a replicated write on the destination under the source's
    /// authorization, relationship, silence, tenant and toggle state, without
    /// its request timestamp or grant check, and reports its failure
    /// (`replicate()`).
    async fn replicate<F: std::future::Future<Output = Result<()>>>(
        &self,
        method: &str,
        destination: &Database,
        write: F,
    ) -> Result<()> {
        let snapshot = self.source.snapshot();
        let result = destination
            .with_snapshot(
                &snapshot,
                destination.with_check_grants(false, destination.with_request_timestamp(None, Box::pin(write))),
            )
            .await;
        self.report(method, result)
    }

    // ---- Databases ----------------------------------------------------

    /// `create()`: a destination failure reaches the caller.
    pub(crate) async fn create(&self, name: Option<&str>) -> Result<bool> {
        let result = self.source.create(name).await?;
        if let Some(destination) = &self.destination {
            destination.create(name).await?;
        }
        Ok(result)
    }

    // ---- Collections --------------------------------------------------

    /// `listCollections()`: the source's, without the source-only ones.
    pub(crate) async fn list_collections(&self, mirror: &Database, limit: i64, offset: i64) -> Result<Vec<Collection>> {
        let source = &self.source;
        let queries = vec![
            // SOURCE_ONLY_COLLECTIONS has one collection: notEqual() of a one-item list.
            Query::not_equal(document::ID, SOURCE_ONLY_COLLECTIONS[0]),
            Query::limit(limit),
            Query::offset(offset),
        ];
        let result = mirror.silent(Box::pin(source.find(METADATA, queries, PermissionType::Read))).await?;
        let collections = result.iter().map(Collection::from_document).collect::<Result<Vec<_>>>()?;
        mirror.trigger(Event::CollectionList, || Domain::CollectionListed { collections: collections.clone() }).await?;
        Ok(collections)
    }

    /// `createCollection()`.
    pub(crate) async fn create_collection(&self, mirror: &Database, collection: Collection) -> Result<Collection> {
        let id = collection.id().to_owned();
        let result = self.source.create_collection(collection.clone()).await?;
        let Some(destination) = &self.destination else {
            return Ok(result);
        };
        let attempt: Result<Option<Document>> = async {
            let mut filtered = Some(result.document().clone());
            for filter in &self.filters {
                filtered = filter.before_create_collection(&self.source, destination, &id, filtered).await?;
                if filtered.is_none() {
                    return Ok(None);
                }
            }
            destination.create_collection(collection).await?;
            Ok(filtered)
        }
        .await;
        let replicated = match attempt {
            Ok(None) => return Ok(result),
            Ok(Some(replicated)) => replicated,
            Err(e) => {
                self.log_error("createCollection", e)?;
                return Collection::from_document(result.document());
            }
        };

        let upgrade = mirror
            .silent(Box::pin(async {
                self.create_upgrades().await?;
                let mut upgrade = Document::default();
                upgrade
                    .put(document::ID, Value::from(id.as_str()))
                    .put("collectionId", Value::from(id.as_str()))
                    .put("status", Value::from("upgraded"));
                self.source.create_document(UPGRADES, upgrade).await.map(|_| ())
            }))
            .await;
        self.report("createCollection", upgrade)?;
        Collection::from_document(&replicated)
    }

    /// `updateCollection()`.
    pub(crate) async fn update_collection(&self, collection: &str, update: collection::Update) -> Result<Collection> {
        let result = self.source.update_collection(collection, update.clone()).await?;
        let Some(destination) = &self.destination else {
            return Ok(result);
        };
        let attempt: Result<()> = async {
            let mut filtered = Some(result.document().clone());
            for filter in &self.filters {
                filtered = filter.before_update_collection(&self.source, destination, collection, filtered).await?;
                if filtered.is_none() {
                    return Ok(());
                }
            }
            destination.update_collection(collection, update).await?;
            Ok(())
        }
        .await;
        self.report("updateCollection", attempt)?;
        Ok(result)
    }

    /// `deleteCollection()`.
    pub(crate) async fn delete_collection(&self, collection: &str) -> Result<()> {
        self.source.delete_collection(collection).await?;
        let Some(destination) = &self.destination else {
            return Ok(());
        };
        let attempt: Result<()> = async {
            destination.delete_collection(collection).await?;
            for filter in &self.filters {
                filter.before_delete_collection(&self.source, destination, collection).await?;
            }
            Ok(())
        }
        .await;
        self.report("deleteCollection", attempt)
    }

    // ---- Attributes ---------------------------------------------------

    /// The attribute the write filters let through to the destination, or
    /// `None` when one drops it (`filterCreatedAttribute()`).
    async fn filter_created_attribute(
        &self,
        destination: &Database,
        collection: &str,
        attribute: &Attribute,
    ) -> Result<Option<Attribute>> {
        let mut document = Some(attribute.to_document()?);
        for filter in &self.filters {
            document =
                filter.before_create_attribute(&self.source, destination, collection, &attribute.key, document).await?;
            if document.is_none() {
                return Ok(None);
            }
        }
        match document {
            Some(document) => Ok(Some(Attribute::from_document(&document)?)),
            None => Ok(None),
        }
    }

    /// `createAttribute()`.
    pub(crate) async fn create_attribute(&self, collection: &str, attribute: Attribute) -> Result<Attribute> {
        let result = self.source.create_attribute(collection, attribute).await?;
        let Some(destination) = &self.destination else {
            return Ok(result);
        };
        let attempt: Result<()> = async {
            if let Some(filtered) = self.filter_created_attribute(destination, collection, &result).await? {
                destination.create_attribute(collection, filtered).await?;
            }
            Ok(())
        }
        .await;
        self.report("createAttribute", attempt)?;
        Ok(result)
    }

    /// `createAttributes()`.
    pub(crate) async fn create_attributes(
        &self,
        collection: &str,
        attributes: Vec<Attribute>,
    ) -> Result<Vec<Attribute>> {
        let result = self.source.create_attributes(collection, attributes).await?;
        let Some(destination) = &self.destination else {
            return Ok(result);
        };
        let attempt: Result<()> = async {
            let mut filtered = Vec::new();
            for attribute in &result {
                if let Some(attribute) = self.filter_created_attribute(destination, collection, attribute).await? {
                    filtered.push(attribute);
                }
            }
            if !filtered.is_empty() {
                destination.create_attributes(collection, filtered).await?;
            }
            Ok(())
        }
        .await;
        self.report("createAttributes", attempt)?;
        Ok(result)
    }

    /// `updateAttribute()`.
    pub(crate) async fn update_attribute(
        &self,
        collection: &str,
        key: &str,
        update: attribute::Update,
    ) -> Result<Attribute> {
        let result = self.source.update_attribute(collection, key, update.clone()).await?;
        let Some(destination) = &self.destination else {
            return Ok(result);
        };
        let attempt: Result<()> = async {
            let mut filtered = Some(result.to_document()?);
            for filter in &self.filters {
                filtered = filter.before_update_attribute(&self.source, destination, collection, key, filtered).await?;
                if filtered.is_none() {
                    return Ok(());
                }
            }
            let Some(filtered) = filtered else { return Ok(()) };
            let filtered = Attribute::from_document(&filtered)?;
            destination.update_attribute(collection, key, filtered_update(&update, &result, &filtered)).await?;
            Ok(())
        }
        .await;
        self.report("updateAttribute", attempt)?;
        Ok(result)
    }

    /// `deleteAttribute()`.
    pub(crate) async fn delete_attribute(&self, collection: &str, key: &str) -> Result<()> {
        self.source.delete_attribute(collection, key).await?;
        let Some(destination) = &self.destination else {
            return Ok(());
        };
        let attempt: Result<()> = async {
            for filter in &self.filters {
                filter.before_delete_attribute(&self.source, destination, collection, key).await?;
            }
            destination.delete_attribute(collection, key).await
        }
        .await;
        self.report("deleteAttribute", attempt)
    }

    // ---- Indexes ------------------------------------------------------

    /// The index the write filters let through, or `None`.
    async fn filter_created_index(
        &self,
        destination: &Database,
        collection: &str,
        index: &Index,
    ) -> Result<Option<Index>> {
        let mut document = Some(index.to_document());
        for filter in &self.filters {
            document = filter.before_create_index(&self.source, destination, collection, &index.key, document).await?;
            if document.is_none() {
                return Ok(None);
            }
        }
        match document {
            Some(document) => Ok(Some(Index::from_document(&document)?)),
            None => Ok(None),
        }
    }

    /// `createIndex()`.
    pub(crate) async fn create_index(&self, collection: &str, index: Index) -> Result<Index> {
        let result = self.source.create_index(collection, index).await?;
        let Some(destination) = &self.destination else {
            return Ok(result);
        };
        let attempt: Result<()> = async {
            if let Some(filtered) = self.filter_created_index(destination, collection, &result).await? {
                destination.create_index(collection, filtered).await?;
            }
            Ok(())
        }
        .await;
        self.report("createIndex", attempt)?;
        Ok(result)
    }

    /// `createIndexes()`.
    pub(crate) async fn create_indexes(&self, collection: &str, indexes: Vec<Index>) -> Result<Vec<Index>> {
        let result = self.source.create_indexes(collection, indexes).await?;
        let Some(destination) = &self.destination else {
            return Ok(result);
        };
        let attempt: Result<()> = async {
            let mut filtered = Vec::new();
            for index in &result {
                if let Some(index) = self.filter_created_index(destination, collection, index).await? {
                    filtered.push(index);
                }
            }
            if !filtered.is_empty() {
                destination.create_indexes(collection, filtered).await?;
            }
            Ok(())
        }
        .await;
        self.report("createIndexes", attempt)?;
        Ok(result)
    }

    /// `deleteIndex()`.
    pub(crate) async fn delete_index(&self, collection: &str, key: &str) -> Result<()> {
        self.source.delete_index(collection, key).await?;
        let Some(destination) = &self.destination else {
            return Ok(());
        };
        let attempt: Result<()> = async {
            destination.delete_index(collection, key).await?;
            for filter in &self.filters {
                filter.before_delete_index(&self.source, destination, collection, key).await?;
            }
            Ok(())
        }
        .await;
        self.report("deleteIndex", attempt)
    }

    // ---- Relationships and renames: on both, in order ---------------

    /// `createRelationship()`.
    pub(crate) async fn create_relationship(
        &self,
        collection: &str,
        relationship: Relationship,
    ) -> Result<Relationship> {
        let result = self.source.create_relationship(collection, relationship.clone()).await?;
        if let Some(destination) = &self.destination {
            let attempt = destination.create_relationship(collection, relationship).await;
            self.report("createRelationship", attempt)?;
        }
        Ok(result)
    }

    /// `updateRelationship()`.
    pub(crate) async fn update_relationship(
        &self,
        collection: &str,
        key: &str,
        update: relationship::Update,
    ) -> Result<Relationship> {
        let result = self.source.update_relationship(collection, key, update.clone()).await?;
        if let Some(destination) = &self.destination {
            let attempt = destination.update_relationship(collection, key, update).await;
            self.report("updateRelationship", attempt)?;
        }
        Ok(result)
    }

    // ---- Documents ----------------------------------------------------

    /// `createDocument()`.
    pub(crate) async fn create_document(
        &self,
        mirror: &Database,
        collection: &str,
        document: Document,
    ) -> Result<Document> {
        let document = self.source.create_document(collection, document).await?;
        if let Some(destination) = self.replicating(mirror, collection).await {
            let attempt: Result<()> = async {
                let mut clone = document.clone();
                for filter in &self.filters {
                    clone = filter.before_create_document(&self.source, destination, collection, clone).await?;
                }
                destination
                    .with_preserve_dates(true, Box::pin(destination.create_document(collection, clone.clone())))
                    .await?;
                for filter in &self.filters {
                    filter.after_create_document(&self.source, destination, collection, clone.clone()).await?;
                }
                Ok(())
            }
            .await;
            self.report("createDocument", attempt)?;
        }
        self.decorate(mirror, Event::DocumentCreate, collection, document).await
    }

    /// `createDocuments()`.
    pub(crate) async fn create_documents(
        &self,
        mirror: &Database,
        collection: &str,
        documents: Vec<Document>,
        batch_size: i64,
        on_next: Option<&mut dyn Callback<(Document, Option<Document>)>>,
    ) -> Result<i64> {
        let mut decorating = match on_next {
            Some(callback) if mirror.has_decorators() => {
                Some(Decorating { mirror, event: Event::DocumentsCreate, collection, callback })
            }
            other => {
                return self.create_documents_with(mirror, collection, documents, batch_size, other).await;
            }
        };
        let on_next = decorating.as_mut().map(|d| d as &mut dyn Callback<(Document, Option<Document>)>);
        self.create_documents_with(mirror, collection, documents, batch_size, on_next).await
    }

    async fn create_documents_with(
        &self,
        mirror: &Database,
        collection: &str,
        documents: Vec<Document>,
        batch_size: i64,
        on_next: Option<&mut dyn Callback<(Document, Option<Document>)>>,
    ) -> Result<i64> {
        let ignore_duplicates = mirror.is_ignoring_duplicates();
        let mut clones = documents.clone();
        let create = Box::pin(self.source.create_documents_tracked(
            collection,
            documents,
            batch_size,
            on_next,
            Some(&mut clones),
        ));
        let modified = if ignore_duplicates { self.source.ignore_duplicates(create).await? } else { create.await? };

        let Some(destination) = self.replicating(mirror, collection).await else {
            return Ok(modified);
        };

        let write = async {
            let mut filtered = Vec::with_capacity(clones.len());
            for clone in clones {
                let mut clone = clone;
                for filter in &self.filters {
                    clone = filter.before_create_document(&self.source, destination, collection, clone).await?;
                }
                filtered.push(clone);
            }
            let create = destination.with_preserve_dates(
                true,
                Box::pin(destination.create_documents(collection, filtered.clone(), batch_size, None)),
            );
            if ignore_duplicates {
                destination.ignore_duplicates(Box::pin(create)).await?;
            } else {
                create.await?;
            }
            for clone in filtered {
                for filter in &self.filters {
                    filter.after_create_document(&self.source, destination, collection, clone.clone()).await?;
                }
            }
            Ok(())
        };
        self.replicate("createDocuments", destination, write).await?;
        Ok(modified)
    }

    /// `updateDocument()`.
    pub(crate) async fn update_document(
        &self,
        mirror: &Database,
        collection: &str,
        id: &str,
        document: Document,
    ) -> Result<Document> {
        let document = self.source.update_document(collection, id, document).await?;
        if let Some(destination) = self.replicating(mirror, collection).await {
            let attempt: Result<()> = async {
                let mut clone = document.clone();
                for filter in &self.filters {
                    clone = filter.before_update_document(&self.source, destination, collection, clone).await?;
                }
                destination
                    .with_preserve_dates(true, Box::pin(destination.update_document(collection, id, clone.clone())))
                    .await?;
                for filter in &self.filters {
                    filter.after_update_document(&self.source, destination, collection, clone.clone()).await?;
                }
                Ok(())
            }
            .await;
            self.report("updateDocument", attempt)?;
        }
        self.decorate(mirror, Event::DocumentUpdate, collection, document).await
    }

    /// `updateDocuments()`.
    pub(crate) async fn update_documents(
        &self,
        mirror: &Database,
        collection: &str,
        updates: Document,
        queries: Vec<Query>,
        batch_size: i64,
        on_next: Option<&mut dyn Callback<(Document, Option<Document>)>>,
    ) -> Result<i64> {
        let mut clone = updates.clone();
        let modified = match on_next {
            Some(callback) if mirror.has_decorators() => {
                let mut decorating = Decorating { mirror, event: Event::DocumentsUpdate, collection, callback };
                self.source
                    .update_documents_tracked(
                        collection,
                        updates,
                        queries.clone(),
                        batch_size,
                        Some(&mut decorating),
                        Some(&mut clone),
                    )
                    .await?
            }
            other => {
                self.source
                    .update_documents_tracked(collection, updates, queries.clone(), batch_size, other, Some(&mut clone))
                    .await?
            }
        };

        let Some(destination) = self.replicating(mirror, collection).await else {
            return Ok(modified);
        };

        let write = async {
            let mut clone = clone;
            for filter in &self.filters {
                clone = filter.before_update_documents(&self.source, destination, collection, clone, &queries).await?;
            }
            destination
                .with_preserve_dates(
                    true,
                    Box::pin(destination.update_documents(
                        collection,
                        clone.clone(),
                        queries.clone(),
                        batch_size,
                        None,
                    )),
                )
                .await?;
            for filter in &self.filters {
                filter.after_update_documents(&self.source, destination, collection, &clone, &queries).await?;
            }
            Ok(())
        };
        self.replicate("updateDocuments", destination, write).await?;
        Ok(modified)
    }

    /// `upsertDocument()`.
    pub(crate) async fn upsert_document(
        &self,
        mirror: &Database,
        collection: &str,
        document: Document,
    ) -> Result<Document> {
        let mut clone = document.clone();
        let upserted = self.source.upsert_document_tracked(collection, document, Some(&mut clone)).await?;

        if let Some(destination) = self.replicating(mirror, collection).await {
            let write = async {
                let mut clone = clone;
                for filter in &self.filters {
                    clone =
                        filter.before_create_or_update_document(&self.source, destination, collection, clone).await?;
                }
                destination
                    .with_preserve_dates(true, Box::pin(destination.upsert_document(collection, clone.clone())))
                    .await?;
                for filter in &self.filters {
                    filter
                        .after_create_or_update_document(&self.source, destination, collection, clone.clone())
                        .await?;
                }
                Ok(())
            };
            self.replicate("upsertDocument", destination, write).await?;
        }
        self.decorate(mirror, Event::DocumentUpsert, collection, upserted).await
    }

    /// `upsertDocuments()`.
    pub(crate) async fn upsert_documents(
        &self,
        mirror: &Database,
        collection: &str,
        documents: Vec<Document>,
        batch_size: i64,
        on_next: Option<&mut dyn Callback<(Document, Option<Document>)>>,
        increase: Option<&str>,
    ) -> Result<i64> {
        let mut clones = documents.clone();
        let modified = match on_next {
            Some(callback) if mirror.has_decorators() => {
                let mut decorating = Decorating { mirror, event: Event::DocumentsUpsert, collection, callback };
                self.source
                    .upsert_documents_tracked(
                        collection,
                        documents,
                        batch_size,
                        Some(&mut decorating),
                        increase,
                        Some(&mut clones),
                    )
                    .await?
            }
            other => {
                self.source
                    .upsert_documents_tracked(collection, documents, batch_size, other, increase, Some(&mut clones))
                    .await?
            }
        };

        let Some(destination) = self.replicating(mirror, collection).await else {
            return Ok(modified);
        };

        let write = async {
            let mut filtered = Vec::with_capacity(clones.len());
            for clone in clones {
                let mut clone = clone;
                for filter in &self.filters {
                    clone =
                        filter.before_create_or_update_document(&self.source, destination, collection, clone).await?;
                }
                filtered.push(clone);
            }
            destination
                .with_preserve_dates(
                    true,
                    Box::pin(destination.upsert_documents(collection, filtered.clone(), batch_size, None, increase)),
                )
                .await?;
            for clone in filtered {
                for filter in &self.filters {
                    filter
                        .after_create_or_update_document(&self.source, destination, collection, clone.clone())
                        .await?;
                }
            }
            Ok(())
        };
        self.replicate("upsertDocuments", destination, write).await?;
        Ok(modified)
    }

    /// `deleteDocument()`.
    pub(crate) async fn delete_document(&self, mirror: &Database, collection: &str, id: &str) -> Result<bool> {
        let result = self.source.delete_document(collection, id).await?;
        let Some(destination) = self.replicating(mirror, collection).await else {
            return Ok(result);
        };
        let write = async {
            for filter in &self.filters {
                filter.before_delete_document(&self.source, destination, collection, id).await?;
            }
            destination.delete_document(collection, id).await?;
            for filter in &self.filters {
                filter.after_delete_document(&self.source, destination, collection, id).await?;
            }
            Ok(())
        };
        self.replicate("deleteDocument", destination, write).await?;
        Ok(result)
    }

    /// `deleteDocuments()`.
    pub(crate) async fn delete_documents(
        &self,
        mirror: &Database,
        collection: &str,
        queries: Vec<Query>,
        batch_size: i64,
        on_next: Option<&mut dyn Callback<Document>>,
    ) -> Result<i64> {
        let modified = self.source.delete_documents(collection, queries.clone(), batch_size, on_next).await?;
        let Some(destination) = self.replicating(mirror, collection).await else {
            return Ok(modified);
        };
        let write = async {
            for filter in &self.filters {
                filter.before_delete_documents(&self.source, destination, collection, &queries).await?;
            }
            destination.delete_documents(collection, queries.clone(), batch_size, None).await?;
            for filter in &self.filters {
                filter.after_delete_documents(&self.source, destination, collection, &queries).await?;
            }
            Ok(())
        };
        self.replicate("deleteDocuments", destination, write).await?;
        Ok(modified)
    }
}
