//! [`Event`]: the lifecycle events of database operations, [`Domain`]: the
//! typed event a lifecycle hook receives for each, and [`DispatcherHook`],
//! a hook that forwards typed events to listeners.
//!
//! PHP: `Utopia\Database\Event`, `Event\Domain` and its final subclasses
//! under `Event\{Database,Collection,Attribute,Index,Document}`,
//! `Event\DispatcherHook`.

use std::collections::HashMap;
use std::sync::{Arc, RwLock};

use async_trait::async_trait;

use crate::attribute::Attribute;
use crate::collection::Collection;
use crate::document::Document;
use crate::error::Result;
use crate::hook::{Lifecycle, Selective};
use crate::index::Index;
use crate::macros::backed_enum;
use crate::value::{Array, Value};

backed_enum! {
    /// A lifecycle event of a database operation; it also labels the
    /// statements an operation runs, for transforms and timeouts.
    ///
    /// PHP: `Utopia\Database\Event`.
    pub enum Event: "Utopia\\Database\\Event" {
        /// Every event (selects timeouts; never dispatched).
        All = "*",
        /// `database_list`.
        DatabaseList = "database_list",
        /// `database_create`.
        DatabaseCreate = "database_create",
        /// `database_update`.
        DatabaseUpdate = "database_update",
        /// `database_delete`.
        DatabaseDelete = "database_delete",
        /// `collection_list`.
        CollectionList = "collection_list",
        /// `collection_create`.
        CollectionCreate = "collection_create",
        /// `collection_update`.
        CollectionUpdate = "collection_update",
        /// `collection_read`.
        CollectionRead = "collection_read",
        /// `collection_delete`.
        CollectionDelete = "collection_delete",
        /// `document_find`.
        DocumentFind = "document_find",
        /// `document_aggregate`.
        DocumentAggregate = "document_aggregate",
        /// `document_purge`.
        DocumentPurge = "document_purge",
        /// `document_create`.
        DocumentCreate = "document_create",
        /// `documents_create`.
        DocumentsCreate = "documents_create",
        /// `document_read`.
        DocumentRead = "document_read",
        /// `document_update`.
        DocumentUpdate = "document_update",
        /// `documents_update`.
        DocumentsUpdate = "documents_update",
        /// `document_upsert`.
        DocumentUpsert = "document_upsert",
        /// `documents_upsert`.
        DocumentsUpsert = "documents_upsert",
        /// `document_delete`.
        DocumentDelete = "document_delete",
        /// `documents_delete`.
        DocumentsDelete = "documents_delete",
        /// `document_count`.
        DocumentCount = "document_count",
        /// `document_sum`.
        DocumentSum = "document_sum",
        /// `document_increase`.
        DocumentIncrease = "document_increase",
        /// `document_decrease`.
        DocumentDecrease = "document_decrease",
        /// `permissions_create` (labels statements only).
        PermissionsCreate = "permissions_create",
        /// `permissions_read` (labels statements only).
        PermissionsRead = "permissions_read",
        /// `permissions_delete` (labels statements only).
        PermissionsDelete = "permissions_delete",
        /// `attribute_create`.
        AttributeCreate = "attribute_create",
        /// `attributes_create`.
        AttributesCreate = "attributes_create",
        /// `attribute_update`.
        AttributeUpdate = "attribute_update",
        /// `attribute_rename`.
        AttributeRename = "attribute_rename",
        /// `attribute_delete`.
        AttributeDelete = "attribute_delete",
        /// `index_rename`.
        IndexRename = "index_rename",
        /// `index_create`.
        IndexCreate = "index_create",
        /// `indexes_create`.
        IndexesCreate = "indexes_create",
        /// `index_delete`.
        IndexDelete = "index_delete",
    }
}

impl Event {
    /// The PHP class of the typed event a hook receives for this case;
    /// `None` for [`Event::All`] and the permission cases, which reach no
    /// hook (`domain()`).
    pub fn domain(self) -> Option<&'static str> {
        Some(match self {
            Event::All | Event::PermissionsCreate | Event::PermissionsRead | Event::PermissionsDelete => return None,
            Event::DatabaseList => "Utopia\\Database\\Event\\Database\\Listed",
            Event::DatabaseCreate => "Utopia\\Database\\Event\\Database\\Created",
            Event::DatabaseUpdate => "Utopia\\Database\\Event\\Database\\Updated",
            Event::DatabaseDelete => "Utopia\\Database\\Event\\Database\\Deleted",
            Event::CollectionList => "Utopia\\Database\\Event\\Collection\\Listed",
            Event::CollectionCreate => "Utopia\\Database\\Event\\Collection\\Created",
            Event::CollectionRead => "Utopia\\Database\\Event\\Collection\\Read",
            Event::CollectionUpdate => "Utopia\\Database\\Event\\Collection\\Updated",
            Event::CollectionDelete => "Utopia\\Database\\Event\\Collection\\Deleted",
            Event::AttributeCreate => "Utopia\\Database\\Event\\Attribute\\Created",
            Event::AttributesCreate => "Utopia\\Database\\Event\\Attribute\\BatchCreated",
            Event::AttributeUpdate => "Utopia\\Database\\Event\\Attribute\\Updated",
            Event::AttributeRename => "Utopia\\Database\\Event\\Attribute\\Renamed",
            Event::AttributeDelete => "Utopia\\Database\\Event\\Attribute\\Deleted",
            Event::IndexCreate => "Utopia\\Database\\Event\\Index\\Created",
            Event::IndexesCreate => "Utopia\\Database\\Event\\Index\\BatchCreated",
            Event::IndexRename => "Utopia\\Database\\Event\\Index\\Renamed",
            Event::IndexDelete => "Utopia\\Database\\Event\\Index\\Deleted",
            Event::DocumentRead => "Utopia\\Database\\Event\\Document\\Read",
            Event::DocumentFind => "Utopia\\Database\\Event\\Document\\Found",
            Event::DocumentAggregate => "Utopia\\Database\\Event\\Document\\Aggregated",
            Event::DocumentCreate => "Utopia\\Database\\Event\\Document\\Created",
            Event::DocumentsCreate => "Utopia\\Database\\Event\\Document\\BatchCreated",
            Event::DocumentUpdate => "Utopia\\Database\\Event\\Document\\Updated",
            Event::DocumentsUpdate => "Utopia\\Database\\Event\\Document\\BatchUpdated",
            Event::DocumentUpsert => "Utopia\\Database\\Event\\Document\\Upserted",
            Event::DocumentsUpsert => "Utopia\\Database\\Event\\Document\\BatchUpserted",
            Event::DocumentDelete => "Utopia\\Database\\Event\\Document\\Deleted",
            Event::DocumentsDelete => "Utopia\\Database\\Event\\Document\\BatchDeleted",
            Event::DocumentIncrease => "Utopia\\Database\\Event\\Document\\Increased",
            Event::DocumentDecrease => "Utopia\\Database\\Event\\Document\\Decreased",
            Event::DocumentCount => "Utopia\\Database\\Event\\Document\\Counted",
            Event::DocumentSum => "Utopia\\Database\\Event\\Document\\Summed",
            Event::DocumentPurge => "Utopia\\Database\\Event\\Document\\Purged",
        })
    }
}

/// A lifecycle event, with its typed payload: one variant per [`Event`]
/// case that reaches hooks. It is built only when a registered hook handles
/// its event.
///
/// PHP: `Utopia\Database\Event\Domain` and its final subclasses (variant
/// `<Group><Class>`: `Event\Document\Created` is [`Domain::DocumentCreated`]).
#[derive(Debug, Clone, PartialEq)]
#[allow(missing_docs)]
pub enum Domain {
    /// `Event\Database\Listed`.
    DatabaseListed { databases: Vec<Document> },
    /// `Event\Database\Created`.
    DatabaseCreated { database: String },
    /// `Event\Database\Updated`.
    DatabaseUpdated { database: String, new: String },
    /// `Event\Database\Deleted`.
    DatabaseDeleted { database: String },
    /// `Event\Collection\Listed`.
    CollectionListed { collections: Vec<Collection> },
    /// `Event\Collection\Created`.
    CollectionCreated { collection: String, definition: Collection },
    /// `Event\Collection\Read`.
    CollectionRead { collection: String, definition: Collection },
    /// `Event\Collection\Updated`.
    CollectionUpdated { collection: String, definition: Collection },
    /// `Event\Collection\Deleted`.
    CollectionDeleted { collection: String, definition: Collection },
    /// `Event\Attribute\Created`.
    AttributeCreated { collection: String, attribute: Attribute },
    /// `Event\Attribute\BatchCreated`.
    AttributeBatchCreated { collection: String, attributes: Vec<Attribute> },
    /// `Event\Attribute\Updated`.
    AttributeUpdated { collection: String, attribute: Attribute },
    /// `Event\Attribute\Renamed`.
    AttributeRenamed { collection: String, old: String, attribute: Attribute },
    /// `Event\Attribute\Deleted`.
    AttributeDeleted { collection: String, attribute: Attribute },
    /// `Event\Index\Created`.
    IndexCreated { collection: String, index: Index },
    /// `Event\Index\BatchCreated`.
    IndexBatchCreated { collection: String, indexes: Vec<Index> },
    /// `Event\Index\Renamed`.
    IndexRenamed { collection: String, old: String, index: Index },
    /// `Event\Index\Deleted`.
    IndexDeleted { collection: String, index: Index },
    /// `Event\Document\Read`.
    DocumentRead { collection: String, document: Document },
    /// `Event\Document\Found`.
    DocumentFound { collection: String, documents: Vec<Document> },
    /// `Event\Document\Aggregated`.
    DocumentAggregated { collection: String, rows: Vec<Array> },
    /// `Event\Document\Created`.
    DocumentCreated { collection: String, document: Document },
    /// `Event\Document\BatchCreated`.
    DocumentBatchCreated { collection: String, count: i64 },
    /// `Event\Document\Updated`.
    DocumentUpdated { collection: String, document: Document },
    /// `Event\Document\BatchUpdated`.
    DocumentBatchUpdated { collection: String, count: i64 },
    /// `Event\Document\Upserted`.
    DocumentUpserted { collection: String, document: Document, created: bool },
    /// `Event\Document\BatchUpserted` (`count` is `created + updated`).
    DocumentBatchUpserted { collection: String, created: i64, updated: i64, count: i64 },
    /// `Event\Document\Deleted`.
    DocumentDeleted { collection: String, document: Document },
    /// `Event\Document\BatchDeleted`.
    DocumentBatchDeleted { collection: String, count: i64 },
    /// `Event\Document\Increased`.
    DocumentIncreased { collection: String, document: Document, attribute: String },
    /// `Event\Document\Decreased`.
    DocumentDecreased { collection: String, document: Document, attribute: String },
    /// `Event\Document\Counted`.
    DocumentCounted { collection: String, count: i64 },
    /// `Event\Document\Summed` (`sum` is an integer or a float).
    DocumentSummed { collection: String, attribute: String, sum: Value },
    /// `Event\Document\Purged`.
    DocumentPurged { collection: String, id: String },
}

impl Domain {
    /// `Event\Document\BatchUpserted`, its count the sum of the others.
    pub fn document_batch_upserted(collection: impl Into<String>, created: i64, updated: i64) -> Domain {
        Domain::DocumentBatchUpserted { collection: collection.into(), created, updated, count: created + updated }
    }

    /// The event (`$event->event`).
    pub fn event(&self) -> Event {
        match self {
            Domain::DatabaseListed { .. } => Event::DatabaseList,
            Domain::DatabaseCreated { .. } => Event::DatabaseCreate,
            Domain::DatabaseUpdated { .. } => Event::DatabaseUpdate,
            Domain::DatabaseDeleted { .. } => Event::DatabaseDelete,
            Domain::CollectionListed { .. } => Event::CollectionList,
            Domain::CollectionCreated { .. } => Event::CollectionCreate,
            Domain::CollectionRead { .. } => Event::CollectionRead,
            Domain::CollectionUpdated { .. } => Event::CollectionUpdate,
            Domain::CollectionDeleted { .. } => Event::CollectionDelete,
            Domain::AttributeCreated { .. } => Event::AttributeCreate,
            Domain::AttributeBatchCreated { .. } => Event::AttributesCreate,
            Domain::AttributeUpdated { .. } => Event::AttributeUpdate,
            Domain::AttributeRenamed { .. } => Event::AttributeRename,
            Domain::AttributeDeleted { .. } => Event::AttributeDelete,
            Domain::IndexCreated { .. } => Event::IndexCreate,
            Domain::IndexBatchCreated { .. } => Event::IndexesCreate,
            Domain::IndexRenamed { .. } => Event::IndexRename,
            Domain::IndexDeleted { .. } => Event::IndexDelete,
            Domain::DocumentRead { .. } => Event::DocumentRead,
            Domain::DocumentFound { .. } => Event::DocumentFind,
            Domain::DocumentAggregated { .. } => Event::DocumentAggregate,
            Domain::DocumentCreated { .. } => Event::DocumentCreate,
            Domain::DocumentBatchCreated { .. } => Event::DocumentsCreate,
            Domain::DocumentUpdated { .. } => Event::DocumentUpdate,
            Domain::DocumentBatchUpdated { .. } => Event::DocumentsUpdate,
            Domain::DocumentUpserted { .. } => Event::DocumentUpsert,
            Domain::DocumentBatchUpserted { .. } => Event::DocumentsUpsert,
            Domain::DocumentDeleted { .. } => Event::DocumentDelete,
            Domain::DocumentBatchDeleted { .. } => Event::DocumentsDelete,
            Domain::DocumentIncreased { .. } => Event::DocumentIncrease,
            Domain::DocumentDecreased { .. } => Event::DocumentDecrease,
            Domain::DocumentCounted { .. } => Event::DocumentCount,
            Domain::DocumentSummed { .. } => Event::DocumentSum,
            Domain::DocumentPurged { .. } => Event::DocumentPurge,
        }
    }

    /// The PHP class of the event (`$event::class`).
    pub fn php_class(&self) -> &'static str {
        self.event().domain().unwrap_or("Utopia\\Database\\Event\\Domain")
    }
}

/// A listener of a [`DispatcherHook`].
pub type Listener = Arc<dyn Fn(&Domain) -> Result<()> + Send + Sync>;

/// A dispatcher of typed events (PHP: a PSR-14 dispatcher's `dispatch()`).
#[async_trait]
pub trait Dispatcher: Send + Sync {
    /// Dispatches `event`.
    async fn dispatch(&self, event: &Domain) -> Result<()>;
}

/// A lifecycle hook that forwards typed events to listeners registered per
/// event, and to an optional dispatcher. It handles an event only while a
/// listener or the dispatcher can receive it.
///
/// Every listener and the dispatcher run; the first error among them is
/// then returned.
///
/// PHP: `Utopia\Database\Event\DispatcherHook`; listeners are registered
/// per typed event class there, per [`Event`] case here.
#[derive(Default)]
pub struct DispatcherHook {
    listeners: RwLock<HashMap<Event, Vec<Listener>>>,
    dispatcher: Option<Arc<dyn Dispatcher>>,
}

impl DispatcherHook {
    /// A hook forwarding to `dispatcher`, if any.
    pub fn new(dispatcher: Option<Arc<dyn Dispatcher>>) -> Self {
        DispatcherHook { listeners: RwLock::new(HashMap::new()), dispatcher }
    }

    /// Registers `listener` for the typed events of `event` (`on()`).
    pub fn on(&self, event: Event, listener: impl Fn(&Domain) -> Result<()> + Send + Sync + 'static) -> &Self {
        self.listeners.write().unwrap_or_else(|e| e.into_inner()).entry(event).or_default().push(Arc::new(listener));
        self
    }
}

impl Selective for DispatcherHook {
    fn handles(&self, event: Event) -> bool {
        event.domain().is_some()
            && (self.dispatcher.is_some()
                || self.listeners.read().unwrap_or_else(|e| e.into_inner()).contains_key(&event))
    }
}

#[async_trait]
impl Lifecycle for DispatcherHook {
    async fn handle(&self, event: &Domain) -> Result<()> {
        let listeners: Vec<Listener> =
            self.listeners.read().unwrap_or_else(|e| e.into_inner()).get(&event.event()).cloned().unwrap_or_default();
        let mut failure = None;
        for listener in listeners {
            if let Err(e) = listener(event) {
                // An engine `\Error` is not caught.
                if e.is_engine_error() {
                    return Err(e);
                }
                failure.get_or_insert(e);
            }
        }
        if let Some(dispatcher) = &self.dispatcher
            && let Err(e) = dispatcher.dispatch(event).await
        {
            if e.is_engine_error() {
                return Err(e);
            }
            failure.get_or_insert(e);
        }
        failure.map_or(Ok(()), Err)
    }

    fn selective(&self) -> Option<&dyn Selective> {
        Some(self)
    }
}
