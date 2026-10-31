//! Hooks: the extension points a database runs during its operations.
//!
//! Each PHP hook interface is a trait. A database registers hooks by kind
//! (`Database::addHook()` dispatches on the interfaces a hook implements);
//! a hook of several kinds is registered as each.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Hook\Lifecycle` | [`Lifecycle`] (typed events, side effects) |
//! | `Hook\Named` | [`Named`], reached through [`Lifecycle::named`] |
//! | `Hook\Selective` | [`Selective`], reached through [`Lifecycle::selective`] |
//! | `Hook\Decorator` | [`Decorator`] |
//! | `Hook\Transform` | [`Transform`] |
//! | `Hook\Write`, `Hook\Interceptor` | [`Write`] (the interceptor's no-ops are its default methods) |
//! | `Hook\WriteContext` | [`WriteContext`] |
//! | `Hook\RowMetadata` | [`RowMetadata`] |
//! | `Hook\Tenancy` | [`Tenancy`] |
//! | `Hook\Nested` | [`Nested`] |
//! | `Hook\Queries` | [`Queries`] |
//! | `Hook\Attachable` | [`Attachable`] |
//! | `Hook\Mongo\{Read, Permission, Tenant}` | [`mongo::Read`], [`mongo::Permission`], [`mongo::Tenant`] |
//!
//! The implementations the database layer provides: [`Permissions`]
//! (`Hook\Permissions`) and [`relationships::Relationships`]
//! (`Hook\Relationships`, with `Cascade`, `Preparation` and
//! `PreparedCreate`). The MongoDB hooks come with its adapter.

pub mod permissions;
pub mod relationships;

pub use permissions::Permissions;

pub mod mongo;

use async_trait::async_trait;

use crate::change::Change;
use crate::collection::Collection;
use crate::document::Document;
use crate::error::Result;
use crate::event::{Domain, Event};
use crate::query::Query;
use crate::storage;
use crate::value::{Array, ArrayKey, Str, Value};

/// A lifecycle hook: side effects on database events. It receives one typed
/// event ([`Domain`]) per event it handles.
///
/// Whether its error reaches the caller depends on the event: for document
/// reads and writes, the purges they cause and index creation it does (and
/// the remaining hooks do not run); for every other event it is swallowed
/// and the remaining hooks run. An engine error ([`crate::Error::is_engine_error`])
/// always reaches the caller.
///
/// PHP: `Utopia\Database\Hook\Lifecycle`.
#[async_trait]
pub trait Lifecycle: Send + Sync {
    /// Handles `event` (`handle()`).
    async fn handle(&self, event: &Domain) -> Result<()>;

    /// The hook's name, when it has one ([`Named`]).
    fn named(&self) -> Option<&dyn Named> {
        None
    }

    /// The events the hook handles, when it names them ([`Selective`]).
    fn selective(&self) -> Option<&dyn Selective> {
        None
    }
}

/// Gives a [`Lifecycle`] hook a stable name: registering a named hook
/// replaces the one registered under that name, in its position, and
/// `silent()` can silence it by name.
///
/// PHP: `Utopia\Database\Hook\Named` (`getName()`).
pub trait Named: Send + Sync {
    /// The name (`getName()`).
    fn name(&self) -> &str;
}

/// Lets a [`Lifecycle`] hook name the events it handles.
///
/// PHP: `Utopia\Database\Hook\Selective`.
pub trait Selective: Send + Sync {
    /// Whether the hook handles `event` (`handles()`).
    fn handles(&self, event: Event) -> bool;
}

/// A hook that transforms each document a read or write returns; its
/// errors always reach the caller.
///
/// PHP: `Utopia\Database\Hook\Decorator`.
#[async_trait]
pub trait Decorator: Send + Sync {
    /// The document the caller receives (`decorate()`).
    async fn decorate(&self, event: Event, collection: &Collection, document: Document) -> Result<Document>;
}

/// A hook that rewrites each SQL statement before it runs, given the event
/// that runs it.
///
/// PHP: `Utopia\Database\Hook\Transform`.
pub trait Transform: Send + Sync {
    /// The statement to run instead of `query` (`transform()`).
    fn transform(&self, event: Event, query: String) -> Result<String>;
}

/// What a [`Write`] hook knows about the document a row is written for.
///
/// PHP: `Utopia\Database\Hook\RowMetadata`.
#[derive(Debug, Clone, PartialEq)]
pub struct RowMetadata {
    /// The document's tenant, or the adapter's when the document names none
    /// (`null`, an integer or a string).
    pub tenant: Value,
}

/// The builder a [`WriteContext`] hands out: a builder of `utopia-query` in
/// the adapter's dialect, scoped by the adapter.
///
/// PHP: `Builder&Scoping`.
#[derive(Clone)]
#[allow(missing_debug_implementations)]
pub enum SqlBuilder {
    /// MySQL.
    MySql(utopia_query::builder::MySql),
    /// MariaDB.
    MariaDb(utopia_query::builder::MariaDb),
    /// PostgreSQL.
    PostgreSql(utopia_query::builder::PostgreSql),
    /// SQLite.
    Sqlite(utopia_query::builder::Sqlite),
}

/// What a [`Write`] hook writes its own rows through: builders and
/// statements on the adapter's connection, in the write that called it.
///
/// PHP: `Utopia\Database\Hook\WriteContext` (implemented by the SQL
/// adapters).
#[async_trait]
pub trait WriteContext: Send + Sync {
    /// A builder on the adapter's connection (`builder()`): its `from()`
    /// takes a table as [`WriteContext::raw_table`] does, limited to the
    /// adapter's tenant under shared tables.
    fn builder(&self) -> SqlBuilder;

    /// The name the table is stored under (`rawTable()`).
    fn raw_table(&self, table: &str) -> String;

    /// A builder reading the stored table `table` (a name
    /// [`WriteContext::raw_table`] returned) as it is, kept to no tenant
    /// (`builder()->fromTable($table)`). By default the builder's plain
    /// `from()`; an adapter whose builders scope `from()` overrides it.
    fn builder_from_table(&self, table: &str) -> SqlBuilder {
        let mut builder = self.builder();
        match &mut builder {
            SqlBuilder::MySql(b) => {
                b.from(table, "");
            }
            SqlBuilder::MariaDb(b) => {
                b.from(table, "");
            }
            SqlBuilder::PostgreSql(b) => {
                b.from(table, "");
            }
            SqlBuilder::Sqlite(b) => {
                b.from(table, "");
            }
        }
        builder
    }

    /// Runs a statement; the event names it to the transforms and timeouts
    /// (`run()`).
    async fn run(&self, statement: utopia_query::builder::Statement, event: Event) -> Result<bool>;

    /// Runs a statement and returns the rows it reads (`fetch()`).
    async fn fetch(&self, statement: utopia_query::builder::Statement, event: Event) -> Result<Vec<Array>>;

    /// The row as every registered write hook decorates a row written for
    /// the document (`decorateRow()`).
    fn decorate_row(&self, row: Array, document: &Document) -> Array;

    /// Whether the update keeps the document's permissions
    /// (`skipPermissions()`).
    fn skip_permissions(&self, document: &Document) -> bool;

    /// Whether the write skips documents already stored (`ignoreDuplicates()`).
    fn ignore_duplicates(&self) -> bool;
}

/// A hook on the adapter's document writes: it decorates every row written
/// and keeps rows of its own in step with the documents. Every method does
/// nothing by default, so a hook implements only what it intercepts.
///
/// PHP: `Utopia\Database\Hook\Write`; the defaults are
/// `Hook\Interceptor`'s.
#[async_trait]
pub trait Write: Send + Sync {
    /// Decorates a row written for a document, to its table or to a side
    /// table (`decorateRow()`).
    fn decorate_row(&self, row: Array, metadata: &RowMetadata) -> Array {
        let _ = metadata;
        row
    }

    /// After documents are created (`afterDocumentCreate()`).
    async fn after_document_create(
        &self,
        collection: &str,
        documents: &[Document],
        context: &dyn WriteContext,
    ) -> Result<()> {
        let _ = (collection, documents, context);
        Ok(())
    }

    /// After a document is updated; `id` is the id it was stored under
    /// before the update (`afterDocumentUpdate()`).
    async fn after_document_update(
        &self,
        collection: &str,
        id: &str,
        document: &Document,
        context: &dyn WriteContext,
    ) -> Result<()> {
        let _ = (collection, id, document, context);
        Ok(())
    }

    /// After documents are updated in a batch (`afterDocumentBatchUpdate()`).
    async fn after_document_batch_update(
        &self,
        collection: &str,
        updates: &Document,
        documents: &[Document],
        context: &dyn WriteContext,
    ) -> Result<()> {
        let _ = (collection, updates, documents, context);
        Ok(())
    }

    /// After documents are upserted (`afterDocumentUpsert()`).
    async fn after_document_upsert(
        &self,
        collection: &str,
        changes: &[Change],
        context: &dyn WriteContext,
    ) -> Result<()> {
        let _ = (collection, changes, context);
        Ok(())
    }

    /// After documents are deleted (`afterDocumentDelete()`).
    async fn after_document_delete(
        &self,
        collection: &str,
        document_ids: &[String],
        context: &dyn WriteContext,
    ) -> Result<()> {
        let _ = (collection, document_ids, context);
        Ok(())
    }

    /// The hook as a [`Tenancy`] hook, if it is one (PHP: `instanceof
    /// Hook\Tenancy`, for `Adapter::getTenantHook()`).
    fn tenancy(&self) -> Option<&Tenancy> {
        None
    }
}

/// Stores the tenant in every row written to a shared table: the
/// document's own, or the adapter's when the document names none.
///
/// PHP: `Utopia\Database\Hook\Tenancy`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Tenancy {
    column: String,
}

impl Default for Tenancy {
    fn default() -> Self {
        Tenancy { column: storage::TENANT.to_owned() }
    }
}

impl Tenancy {
    /// A hook writing the tenant to `column` (`_tenant` by default).
    pub fn new(column: impl Into<String>) -> Self {
        Tenancy { column: column.into() }
    }

    /// The column the tenant is written to.
    pub fn column(&self) -> &str {
        &self.column
    }
}

#[async_trait]
impl Write for Tenancy {
    fn decorate_row(&self, mut row: Array, metadata: &RowMetadata) -> Array {
        row.set(ArrayKey::normalize(Str::from(self.column.as_str())), metadata.tenant.clone());
        row
    }

    fn tenancy(&self) -> Option<&Tenancy> {
        Some(self)
    }
}

/// A hook that prepares each nested related document of a write before the
/// write relates it (given a copy; the document it returns is the one the
/// write uses). It must return the same document when given its own output.
///
/// PHP: `Utopia\Database\Hook\Nested`.
#[async_trait]
pub trait Nested: Send + Sync {
    /// The related document the write checks and relates (`prepare()`).
    async fn prepare(&self, collection: &Collection, document: Document) -> Result<Document>;
}

/// A hook that rewrites the queries of a read before the read uses them;
/// an error refuses the read. It must return the same queries when given
/// its own output.
///
/// PHP: `Utopia\Database\Hook\Queries`.
#[async_trait]
pub trait Queries: Send + Sync {
    /// The queries the read uses (`rewrite()`).
    async fn rewrite(&self, event: Event, collection: &Collection, queries: Vec<Query>) -> Result<Vec<Query>>;
}

/// A hook that works through the database it is added to, which hands it
/// over before registering it. Generic over the database type, which the
/// database layer defines.
///
/// PHP: `Utopia\Database\Hook\Attachable`.
pub trait Attachable<D: ?Sized>: Send + Sync {
    /// Takes the database the hook is added to (`attach()`).
    fn attach(&self, database: &D) -> Result<()>;
}
