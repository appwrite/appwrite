//! [`Mongo`]: the MongoDB adapter.
//!
//! A collection is a MongoDB collection named `<namespace>_<id>` in the
//! adapter's database; a document is a BSON document whose internal
//! attributes are stored under `_uid` (`$id`), `_id` (`$sequence`, a UUIDv7
//! string), `_tenant`, `_createdAt`, `_updatedAt` and `_permissions`.
//! Attributes need no schema: creating one is a no-op, and in schemaless
//! mode ([`Adapter::set_support_for_attributes`] `false`) documents carry
//! any attribute. Filters, sorts, cursors and permissions become a `find`
//! filter; operators an aggregation pipeline update; transactions are the
//! driver's, on a replica set.
//!
//! It runs on [`utopia_mongo::Client`] (the official driver) and sends the
//! commands PHP's adapter sends through utopia-php/mongo, so collections,
//! indexes and documents are the ones PHP's adapter writes.
//!
//! ```no_run
//! # async fn demo() -> utopia_database::Result<()> {
//! use std::sync::Arc;
//!
//! use utopia_database::Adapter;
//! use utopia_database::adapter::mongo::Mongo;
//! use utopia_mongo::{Client, Config};
//!
//! let client = Client::new(Config::new("appwrite", "localhost", 27017, "root", "password"))
//!     .map_err(utopia_database::adapter::mongo::from_client)?;
//! let adapter = Mongo::new(Arc::new(client)).await?;
//! adapter.set_database("appwrite")?;
//! adapter.set_namespace("_1")?;
//! # Ok(()) }
//! ```
//!
//! PHP: `Utopia\Database\Adapter\Mongo`.

use std::sync::Arc;
use std::sync::Mutex as SyncMutex;
use std::sync::atomic::{AtomicBool, Ordering};

use async_trait::async_trait;
use php_std::datetime::{DateTime, TzInfo};
use utopia_mongo::bson::doc;
use utopia_mongo::{Client, Options, Session};

use super::{Adapter, FindOptions, State};
use crate::change::Change;
use crate::database::Database;
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::query::Query;
use crate::value::{Array, Value};

pub(crate) mod bson;
mod casting;
mod documents;
mod filters;
mod schema;

/// The MongoDB adapter. See the [module docs](self).
///
/// PHP: `Utopia\Database\Adapter\Mongo`.
pub struct Mongo {
    state: State,
    client: Arc<Client>,
    /// The session of the transaction in progress.
    session: SyncMutex<Option<Session>>,
    support_for_attributes: AtomicBool,
}

/// A client error as PHP's adapter lets it out: `Utopia\Mongo\Exception`
/// (or `UnsentException`), message and code kept.
pub fn from_client(e: utopia_mongo::Error) -> Error {
    let kind = match &e {
        utopia_mongo::Error::Unsent(_) => Kind::MongoUnsent,
        utopia_mongo::Error::InvalidArgument(_) => Kind::Exception,
        utopia_mongo::Error::Engine { class, .. } => Kind::from_php_class(class).unwrap_or(Kind::Error),
        _ => Kind::Mongo,
    };
    Error::new(kind, e.message()).with_code(e.code())
}

impl Mongo {
    /// The adapter over `client`, which it connects.
    ///
    /// PHP: `new Mongo($client)`.
    pub async fn new(client: Arc<Client>) -> Result<Mongo> {
        client.connect().await.map_err(from_client)?;
        Ok(Mongo {
            state: State::default(),
            client,
            session: SyncMutex::new(None),
            support_for_attributes: AtomicBool::new(true),
        })
    }

    /// The client the adapter runs on.
    ///
    /// PHP: `getDriver()`.
    pub fn driver(&self) -> &Arc<Client> {
        &self.client
    }

    fn session(&self) -> Option<Session> {
        self.session.lock().unwrap_or_else(|e| e.into_inner()).clone()
    }

    fn set_session(&self, session: Option<Session>) {
        *self.session.lock().unwrap_or_else(|e| e.into_inner()) = session;
    }

    fn depth(&self) -> i64 {
        self.state.lock().in_transaction
    }

    fn set_depth(&self, depth: i64) {
        self.state.lock().in_transaction = depth;
    }

    /// `getNamespace() . '_' . filter($collection)`: a collection's name.
    fn name(&self, collection: &str) -> Result<String> {
        Ok(format!("{}_{}", self.namespace(), self.filter(collection)?))
    }

    /// `getTransactionOptions($options)`: the session of the transaction
    /// in progress, if any, with `fields`.
    fn options(&self, fields: utopia_mongo::bson::Document) -> Options {
        let session = if self.depth() > 0 { self.session() } else { None };
        Options { session, fields }
    }

    /// `endSessions([$this->session])`, errors ignored where PHP ignores them.
    async fn end_session(&self, session: &Option<Session>) -> Result<()> {
        if let Some(session) = session {
            self.client.end_sessions(std::slice::from_ref(session), doc! {}).await.map_err(from_client)?;
        }
        Ok(())
    }

    /// `processException($e)`: a client error as the database error PHP
    /// maps it to (timeouts, duplicates, missing transactions, invalid
    /// operations, an undefined power), or passed through.
    pub fn process_exception(&self, e: utopia_mongo::Error) -> Error {
        let code = e.code();
        let previous = from_client(e.clone());
        let mapped =
            |kind: Kind, message: &str| Error::new(kind, message).with_code(code).with_previous(previous.clone());
        match code {
            50 | 262 => mapped(Kind::Timeout, "Query timed out"),
            11000 | 11001 => match self.violated_index(e.message()) {
                Some(index) if index != "_uid" && index != "_id_" => mapped(Kind::Unique, "Unique index violation"),
                _ => mapped(Kind::Duplicate, "Document already exists"),
            },
            48 => mapped(Kind::Duplicate, "Collection already exists"),
            85 => mapped(Kind::Duplicate, "Index already exists"),
            251 => mapped(Kind::Transaction, "No active transaction"),
            112 => mapped(Kind::Transaction, "Transaction aborted"),
            14 => mapped(Kind::Type, "Invalid operation"),
            28764 => mapped(Kind::Limit, "Value out of range"),
            _ => previous,
        }
    }

    /// `processException($e)` of an error that is already the database's
    /// (codes of a passed-through client error still map).
    fn process(&self, e: Error) -> Error {
        if !matches!(e.kind(), Kind::Mongo | Kind::MongoUnsent) {
            return e;
        }
        let failure = utopia_mongo::Failure::new(e.message(), e.code());
        let client = if e.kind() == Kind::MongoUnsent {
            utopia_mongo::Error::Unsent(Box::new(failure))
        } else {
            utopia_mongo::Error::Exception(Box::new(failure))
        };
        self.process_exception(client)
    }

    /// `getViolatedIndex($message)`: the index a duplicate key error names
    /// (`... index: _uid dup key: ...`).
    pub fn violated_index(&self, message: &str) -> Option<String> {
        use php_std::pcre::Value as P;
        let found = php_std::pcre::preg_match(br"/index:\s*(\S+)\s+dup key/", message.as_bytes(), 0, 0).ok()?.value;
        if found.result != P::Int(1) {
            return None;
        }
        match found.matches?.entries().get(1) {
            Some((_, P::Str(s))) => Some(String::from_utf8_lossy(s).into_owned()),
            _ => None,
        }
    }

    /// `permissionStrings($type)`: `<type>("<role>")` for each role of the
    /// authorization, the strings documents' `_permissions` hold.
    pub fn permission_strings(&self, kind: &str) -> Vec<String> {
        Self::permission_strings_of(&self.authorization(), kind)
    }

    /// [`Mongo::permission_strings`] for `authorization`'s roles.
    pub fn permission_strings_of(authorization: &crate::Authorization, kind: &str) -> Vec<String> {
        authorization.roles().iter().map(|role| format!("{kind}(\"{}\")", crate::value::to_text(role))).collect()
    }

    /// `getInternalKeyForAttribute($attribute)`: the stored name of an
    /// internal attribute (`$id` is `_uid`, `$sequence` is `_id`, ...).
    pub fn internal_key(attribute: &str) -> &str {
        match attribute {
            "$id" => "_uid",
            "$sequence" => "_id",
            "$collection" => "_collection",
            "$tenant" => "_tenant",
            "$createdAt" => "_createdAt",
            "$updatedAt" => "_updatedAt",
            "$deletedAt" => "_deletedAt",
            "$permissions" => "_permissions",
            other => other,
        }
    }

    /// `getOrder($order)`: `1` for `ASC`, `-1` for `DESC` (any case).
    fn order(order: &str) -> Result<i32> {
        match order.to_ascii_uppercase().as_str() {
            Database::ORDER_ASC => Ok(1),
            Database::ORDER_DESC => Ok(-1),
            _ => Err(Error::database(format!(
                "Unknown sort order:{order}. Must be one of {}, {}",
                Database::ORDER_ASC,
                Database::ORDER_DESC
            ))),
        }
    }

    /// `getTenantFilters($collection, $tenants)`: the `_tenant` filter of a
    /// shared collection: the tenant (or `tenants`), plus `null` for the
    /// metadata collection, `$in` when more than one.
    pub fn tenant_filters(&self, collection: &str, tenants: &[Value]) -> Value {
        if !self.shared_tables() {
            return Value::Array(Array::new());
        }
        let mut values: Vec<Value> = if tenants.is_empty() { vec![self.tenant()] } else { tenants.to_vec() };
        if collection == Database::METADATA {
            values.push(Value::Null);
        }
        if values.len() == 1 {
            return values.remove(0);
        }
        let mut filter = Array::new();
        filter.set("$in".into(), Value::Array(values.into_iter().collect()));
        Value::Array(filter)
    }

    /// `getSupportForTTLIndexes()` and the other capability getters PHP's
    /// Mongo adds over the trait's: `listCollections()`, the raw reply
    /// values of `listCollections`.
    ///
    /// PHP: `listCollections()`.
    pub async fn list_collections(&self) -> Result<Vec<Value>> {
        let reply = self
            .client
            .list_collection_names(utopia_mongo::bson::Document::new(), Options::new())
            .await
            .map_err(from_client)?;
        Ok(reply.iter().map(|(_, v)| bson::decode(v)).collect())
    }

    /// `quote($string)`: `''` (MongoDB has no identifiers to quote).
    pub fn quote(&self, _string: &str) -> String {
        String::new()
    }

    /// `execute($stmt)`: `true` (MongoDB has no statements).
    pub fn execute(&self) -> bool {
        true
    }

    /// `isExtendedISODatetime($value)`: whether a string is an ISO 8601
    /// date and time with a zone (`Z` or `+hh:mm`) and an optional fraction
    /// (`YYYY-MM-DDTHH:mm:ss[.f+](Z|+HH:MM)`).
    pub fn is_extended_iso_datetime(value: &[u8]) -> bool {
        let len = value.len();
        if len < 20 {
            return false;
        }
        if value[4] != b'-' || value[7] != b'-' || value[10] != b'T' || value[13] != b':' || value[16] != b':' {
            return false;
        }
        let has_z = value[len - 1] == b'Z';
        let has_offset = len >= 25 && matches!(value[len - 6], b'+' | b'-') && value[len - 3] == b':';
        if !has_z && !has_offset {
            return false;
        }
        if has_offset && len > 31 {
            return false;
        }
        if has_z && len > 26 {
            return false;
        }
        let mut digits: Vec<usize> = vec![0, 1, 2, 3, 5, 6, 8, 9, 11, 12, 14, 15, 17, 18];
        let time_end = if has_z { len - 1 } else { len - 6 };
        if time_end > 19 {
            if value[19] != b'.' || time_end < 21 {
                return false;
            }
            digits.extend(20..time_end);
        }
        if has_offset {
            digits.extend([len - 5, len - 4, len - 2, len - 1]);
        }
        digits.iter().all(|i| value[*i].is_ascii_digit())
    }

    /// `escapeMongoFieldName($name)`: a leading `$` as `_`, dots as `__dot__`.
    pub fn escape_field_name(name: &str) -> String {
        let name = match name.strip_prefix('$') {
            Some(rest) => format!("_{rest}"),
            None => name.to_owned(),
        };
        if name.contains('.') { name.replace('.', "__dot__") } else { name }
    }

    async fn is_replica_set(&self) -> Result<bool> {
        self.client.is_replica_set().await.map_err(from_client)
    }

    /// `startTransaction()`.
    async fn start_transaction_impl(&self) -> Result<bool> {
        if !self.is_replica_set().await? {
            return Ok(true);
        }
        let started = async {
            if self.depth() == 0 && self.session().is_none() {
                let session = self.client.start_session(doc! {}).await?;
                self.set_session(Some(session.clone()));
                self.client.start_transaction(&session, doc! {}).await?;
            }
            Ok::<_, utopia_mongo::Error>(())
        }
        .await;
        if let Err(e) = started {
            self.set_session(None);
            self.set_depth(0);
            let code = e.code();
            return Err(Error::database(format!("Failed to start transaction: {}", e.message()))
                .with_code(code)
                .with_previous(from_client(e)));
        }
        self.set_depth(self.depth() + 1);
        Ok(true)
    }

    /// `commitTransaction()`.
    async fn commit_transaction_impl(&self) -> Result<bool> {
        if !self.is_replica_set().await? {
            return Ok(true);
        }
        let outcome: Result<bool> = async {
            if self.depth() == 0 {
                return Ok(false);
            }
            self.set_depth(self.depth() - 1);
            if self.depth() > 0 {
                return Ok(true);
            }
            let Some(session) = self.session() else { return Ok(false) };
            let committed = self.client.commit_transaction(&session, doc! {}).await;
            let result = match committed {
                Ok(_) => Ok(true),
                Err(e) => {
                    let e = self.process_exception(e);
                    if e.kind() == Kind::Transaction {
                        self.end_session(&Some(session.clone())).await?;
                        self.set_session(None);
                        self.set_depth(0);
                        return Ok(true);
                    }
                    Err(e)
                }
            };
            // finally
            if let Some(current) = self.session() {
                self.end_session(&Some(current)).await?;
            }
            self.set_session(None);
            result
        }
        .await;
        match outcome {
            Ok(done) => Ok(done),
            Err(e) => {
                let session = self.session();
                let _ = self.end_session(&session).await;
                self.set_session(None);
                self.set_depth(0);
                Err(Error::database(format!("Failed to commit transaction: {}", e.message()))
                    .with_code(e.code())
                    .with_previous(e))
            }
        }
    }

    /// `rollbackTransaction()`.
    async fn rollback_transaction_impl(&self) -> Result<bool> {
        if !self.is_replica_set().await? {
            return Ok(true);
        }
        let outcome: Result<bool> = async {
            if self.depth() == 0 {
                return Ok(false);
            }
            self.set_depth(self.depth() - 1);
            if self.depth() > 0 {
                return Ok(true);
            }
            let Some(session) = self.session() else { return Ok(false) };
            let aborted = self.client.abort_transaction(&session, doc! {}).await;
            let result = match aborted {
                Ok(_) => Ok(true),
                Err(e) => {
                    let e = self.process_exception(e);
                    if e.kind() == Kind::Transaction { Ok(true) } else { Err(e) }
                }
            };
            // finally
            self.end_session(&Some(session)).await?;
            self.set_session(None);
            result
        }
        .await;
        match outcome {
            Ok(done) => Ok(done),
            Err(e) => {
                let session = self.session();
                let _ = self.end_session(&session).await;
                self.set_session(None);
                self.set_depth(0);
                Err(Error::database(format!("Failed to rollback transaction: {}", e.message()))
                    .with_code(e.code())
                    .with_previous(e))
            }
        }
    }
}

/// `is_null($value)`.
fn null(value: &Value) -> bool {
    matches!(value, Value::Null)
}

/// `\count($collection->getAttribute($key) ?? [])`.
fn count_of(collection: &Document, key: &str) -> i64 {
    match collection.get(key) {
        Some(Value::Array(a)) => a.len() as i64,
        Some(Value::Ext(crate::value::Ext::Document(d))) => d.len() as i64,
        _ => 0,
    }
}

#[async_trait]
impl Adapter for Mongo {
    fn state(&self) -> &State {
        &self.state
    }

    /// `getHostname()`: the client's host.
    fn hostname(&self) -> String {
        self.client.host().to_owned()
    }

    fn set_timeout(&self, milliseconds: i64, _event: &str) -> Result<()> {
        self.state.lock().timeout = milliseconds;
        Ok(())
    }

    fn clear_timeout(&self, event: &str) {
        self.before(event, "timeout", None);
        self.state.lock().timeout = 0;
    }

    async fn bypass_transaction(&self) -> Result<bool> {
        Ok(!self.is_replica_set().await? || self.depth() > 0 || self.skip_duplicates())
    }

    fn rethrows_rollback_failure(&self) -> bool {
        false
    }

    async fn start_transaction(&self) -> Result<bool> {
        self.start_transaction_impl().await
    }

    async fn commit_transaction(&self) -> Result<bool> {
        self.commit_transaction_impl().await
    }

    async fn rollback_transaction(&self) -> Result<bool> {
        self.rollback_transaction_impl().await
    }

    /// `ping()`.
    async fn ping(&self) -> Result<bool> {
        let reply = self.client.query(doc! { "ping": 1, "skipReadConcern": true }, None).await.map_err(from_client)?;
        Ok(match reply {
            utopia_mongo::Reply::Document(d) => match d.get("ok") {
                Some(ok) => bson::decode(ok).truthy(),
                None => false,
            },
            utopia_mongo::Reply::Count(n) => bson::decode(&n).truthy(),
        })
    }

    async fn reconnect(&self) -> Result<()> {
        self.client.connect().await.map_err(from_client)?;
        Ok(())
    }

    async fn connection_id(&self) -> Result<String> {
        Ok("0".to_owned())
    }

    async fn create(&self, _name: &str) -> Result<bool> {
        Ok(true)
    }

    async fn exists(&self, database: &str, collection: Option<&str>) -> Result<bool> {
        self.exists_impl(database, collection).await
    }

    async fn list(&self) -> Result<Vec<Document>> {
        self.list_impl().await
    }

    async fn delete(&self, name: &str) -> Result<bool> {
        self.client.drop_database(doc! {}, Some(name)).await.map_err(from_client)?;
        Ok(true)
    }

    async fn create_collection(&self, name: &str, attributes: &[Document], indexes: &[Document]) -> Result<bool> {
        self.create_collection_impl(name, attributes, indexes).await
    }

    async fn delete_collection(&self, id: &str) -> Result<bool> {
        let name = self.name(id)?;
        self.client.drop_collection(&name, Options::new()).await.map_err(from_client)
    }

    async fn analyze_collection(&self, _collection: &str) -> Result<bool> {
        Ok(false)
    }

    async fn size_of_collection(&self, collection: &str) -> Result<i64> {
        self.size_of_collection_impl(collection).await
    }

    async fn size_of_collection_on_disk(&self, collection: &str) -> Result<i64> {
        self.size_of_collection_impl(collection).await
    }

    async fn schema_attributes(&self, _collection: &str) -> Result<Vec<Document>> {
        Ok(Vec::new())
    }

    async fn schema_indexes(&self, _collection: &str) -> Result<Vec<Document>> {
        Ok(Vec::new())
    }

    async fn create_attribute(
        &self,
        _collection: &str,
        _id: &str,
        _kind: &str,
        _size: i64,
        _signed: bool,
        _array: bool,
        _required: bool,
    ) -> Result<bool> {
        Ok(true)
    }

    async fn create_attributes(&self, _collection: &str, _attributes: &[Array]) -> Result<bool> {
        Ok(true)
    }

    async fn update_attribute(
        &self,
        collection: &str,
        id: &str,
        _kind: &str,
        _size: i64,
        _signed: bool,
        _array: bool,
        new_key: Option<&str>,
        _required: bool,
    ) -> Result<bool> {
        match new_key {
            Some(new) if !(new.is_empty() || new == "0") && new != id => {
                self.rename_attribute(collection, id, new).await
            }
            _ => Ok(true),
        }
    }

    async fn delete_attribute(&self, collection: &str, id: &str) -> Result<bool> {
        self.delete_attribute_impl(collection, id).await
    }

    async fn rename_attribute(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        self.rename_attribute_impl(collection, old, new).await
    }

    async fn create_relationship(
        &self,
        _collection: &str,
        _related_collection: &str,
        _kind: &str,
        _two_way: bool,
        _id: &str,
        _two_way_key: &str,
    ) -> Result<bool> {
        Ok(true)
    }

    async fn update_relationship(
        &self,
        collection: &str,
        related_collection: &str,
        kind: &str,
        two_way: bool,
        key: &str,
        two_way_key: &str,
        side: &str,
        new_key: Option<&str>,
        new_two_way_key: Option<&str>,
    ) -> Result<bool> {
        self.update_relationship_impl(
            collection,
            related_collection,
            kind,
            two_way,
            key,
            two_way_key,
            side,
            new_key,
            new_two_way_key,
        )
        .await
    }

    async fn delete_relationship(
        &self,
        collection: &str,
        related_collection: &str,
        kind: &str,
        two_way: bool,
        key: &str,
        two_way_key: &str,
        side: &str,
    ) -> Result<bool> {
        self.delete_relationship_impl(collection, related_collection, kind, two_way, key, two_way_key, side).await
    }

    async fn create_index(
        &self,
        collection: &str,
        id: &str,
        kind: &str,
        attributes: &[String],
        lengths: &[Value],
        orders: &[Value],
        attribute_types: &[(String, String)],
        collation: &[String],
        ttl: i64,
    ) -> Result<bool> {
        self.create_index_impl(collection, id, kind, attributes, lengths, orders, attribute_types, collation, ttl).await
    }

    async fn delete_index(&self, collection: &str, id: &str) -> Result<bool> {
        let name = self.name(collection)?;
        let id = self.filter(id)?;
        self.client
            .drop_indexes(&name, utopia_mongo::bson::Bson::Array(vec![id.into()]), Options::new())
            .await
            .map_err(from_client)?;
        Ok(true)
    }

    async fn rename_index(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        self.rename_index_impl(collection, old, new).await
    }

    async fn get_document(
        &self,
        collection: &Document,
        id: &str,
        queries: &[Query],
        for_update: bool,
    ) -> Result<Document> {
        self.get_document_impl(collection, id, queries, for_update).await
    }

    async fn create_document(&self, collection: &Document, document: Document) -> Result<Document> {
        self.create_document_impl(collection, document).await
    }

    async fn create_documents(&self, collection: &Document, documents: Vec<Document>) -> Result<Vec<Document>> {
        self.create_documents_impl(collection, documents).await
    }

    async fn update_document(
        &self,
        collection: &Document,
        id: &str,
        document: Document,
        _skip_permissions: bool,
    ) -> Result<Document> {
        self.update_document_impl(collection, id, document).await
    }

    async fn update_documents(&self, collection: &Document, updates: &Document, documents: &[Document]) -> Result<i64> {
        self.update_documents_impl(collection, updates, documents).await
    }

    async fn upsert_documents(
        &self,
        collection: &Document,
        attribute: &str,
        changes: Vec<Change>,
    ) -> Result<Vec<Document>> {
        self.upsert_documents_impl(collection, attribute, changes).await
    }

    async fn get_sequences(&self, collection: &str, documents: Vec<Document>) -> Result<Vec<Document>> {
        self.get_sequences_impl(collection, documents).await
    }

    async fn delete_document(&self, collection: &str, id: &str) -> Result<bool> {
        self.delete_document_impl(collection, id).await
    }

    async fn delete_documents(&self, collection: &str, sequences: &[Value], permission_ids: &[String]) -> Result<i64> {
        self.delete_documents_impl(collection, sequences, permission_ids).await
    }

    async fn increase_document_attribute(
        &self,
        collection: &str,
        id: &str,
        attribute: &str,
        value: &Value,
        updated_at: &str,
        min: Option<&Value>,
        max: Option<&Value>,
    ) -> Result<bool> {
        self.increase_document_attribute_impl(collection, id, attribute, value, updated_at, min, max).await
    }

    async fn find(&self, collection: &Document, queries: &[Query], options: FindOptions<'_>) -> Result<Vec<Document>> {
        self.find_impl(collection, queries, options).await
    }

    async fn sum(&self, collection: &Document, attribute: &str, queries: &[Query], max: Option<i64>) -> Result<Value> {
        self.sum_impl(collection, attribute, queries, max).await
    }

    async fn count(&self, collection: &Document, queries: &[Query], max: Option<i64>) -> Result<i64> {
        self.count_impl(collection, queries, max).await
    }

    fn casting_before(&self, collection: &Document, document: Document) -> Result<Document> {
        self.casting_before_impl(collection, document)
    }

    fn casting_after(&self, collection: &Document, document: Document) -> Result<Document> {
        self.casting_after_impl(collection, document)
    }

    /// `setUTCDatetime($value)`: a `MongoDB\BSON\UTCDateTime` of the date.
    fn set_utc_datetime(&self, value: &str) -> Result<Value> {
        casting::utc_datetime(value.as_bytes())
    }

    fn decode_point(&self, _wkb: &[u8]) -> Result<Value> {
        Ok(Value::Array(Array::new()))
    }

    fn decode_linestring(&self, _wkb: &[u8]) -> Result<Value> {
        Ok(Value::Array(Array::new()))
    }

    fn decode_polygon(&self, _wkb: &[u8]) -> Result<Value> {
        Ok(Value::Array(Array::new()))
    }

    fn tenant_query(&self, _collection: &str, _alias: &str) -> String {
        String::new()
    }

    fn limit_for_string(&self) -> i64 {
        2147483647
    }

    fn limit_for_int(&self) -> i64 {
        4294967295
    }

    fn limit_for_big_int(&self) -> i64 {
        Database::MAX_BIG_INT
    }

    fn limit_for_attributes(&self) -> i64 {
        0
    }

    fn limit_for_indexes(&self) -> i64 {
        64
    }

    fn max_index_length(&self) -> i64 {
        1024
    }

    fn max_varchar_length(&self) -> i64 {
        2147483647
    }

    fn max_uid_length(&self) -> i64 {
        255
    }

    /// `getMinDateTime()`: `-9999-01-01 00:00:00`.
    fn min_date_time(&self) -> DateTime {
        DateTime::new(b"-9999-01-01 00:00:00", None, &TzInfo::utc()).unwrap_or_else(|_| unreachable!("a valid date"))
    }

    fn id_attribute_type(&self) -> &'static str {
        Database::VAR_UUID7
    }

    fn count_of_attributes(&self, collection: &Document) -> i64 {
        count_of(collection, "attributes") + self.count_of_default_attributes()
    }

    fn count_of_indexes(&self, collection: &Document) -> i64 {
        count_of(collection, "indexes") + self.count_of_default_indexes()
    }

    fn count_of_default_attributes(&self) -> i64 {
        Database::internal_attributes_const().len() as i64
    }

    fn count_of_default_indexes(&self) -> i64 {
        Database::INTERNAL_INDEXES.len() as i64
    }

    fn document_size_limit(&self) -> i64 {
        0
    }

    fn attribute_width(&self, _collection: &Document) -> Result<i64> {
        Ok(0)
    }

    fn keywords(&self) -> &'static [&'static str] {
        &[]
    }

    fn internal_indexes_keys(&self) -> &'static [&'static str] {
        &[]
    }

    fn support_for_schemas(&self) -> bool {
        false
    }
    fn support_for_attributes(&self) -> bool {
        self.support_for_attributes.load(Ordering::Relaxed)
    }
    fn set_support_for_attributes(&self, support: bool) -> bool {
        self.support_for_attributes.store(support, Ordering::Relaxed);
        support
    }
    fn support_for_schema_attributes(&self) -> bool {
        false
    }
    fn support_for_schema_indexes(&self) -> bool {
        false
    }
    fn support_for_index(&self) -> bool {
        true
    }
    fn support_for_index_array(&self) -> bool {
        true
    }
    fn support_for_cast_index_array(&self) -> bool {
        false
    }
    fn support_for_unique_index(&self) -> bool {
        true
    }
    fn support_for_fulltext_index(&self) -> bool {
        true
    }
    fn support_for_fulltext_wildcard_index(&self) -> bool {
        false
    }
    fn support_for_casting(&self) -> bool {
        false
    }
    fn support_for_query_contains(&self) -> bool {
        false
    }
    fn support_for_timeouts(&self) -> bool {
        true
    }
    fn support_for_relationships(&self) -> bool {
        true
    }
    fn support_for_update_lock(&self) -> bool {
        false
    }
    fn support_for_batch_operations(&self) -> bool {
        false
    }
    fn support_for_attribute_resizing(&self) -> bool {
        false
    }
    fn support_for_get_connection_id(&self) -> bool {
        false
    }
    fn support_for_upserts(&self) -> bool {
        true
    }
    fn support_for_upsert_on_unique_index(&self) -> bool {
        false
    }
    fn support_for_vectors(&self) -> bool {
        false
    }
    fn support_for_cache_skip_on_failure(&self) -> bool {
        false
    }
    fn support_for_caching(&self) -> bool {
        true
    }
    fn support_for_reconnection(&self) -> bool {
        false
    }
    fn support_for_hostname(&self) -> bool {
        true
    }
    fn support_for_batch_create_attributes(&self) -> bool {
        true
    }
    fn support_for_spatial_attributes(&self) -> bool {
        false
    }
    fn support_for_object(&self) -> bool {
        true
    }
    fn support_for_object_indexes(&self) -> bool {
        false
    }
    fn support_for_spatial_index_null(&self) -> bool {
        false
    }
    fn support_for_operators(&self) -> bool {
        true
    }
    fn support_for_optional_spatial_attribute_with_existing_rows(&self) -> bool {
        false
    }
    fn support_for_spatial_index_order(&self) -> bool {
        false
    }
    fn support_for_spatial_axis_order(&self) -> bool {
        false
    }
    fn support_for_boundary_inclusive_contains(&self) -> bool {
        false
    }
    fn support_for_distance_between_multi_dimension_geometry_in_meters(&self) -> bool {
        false
    }
    fn support_for_multiple_fulltext_indexes(&self) -> bool {
        false
    }
    fn support_for_identical_indexes(&self) -> bool {
        false
    }
    fn support_for_order_random(&self) -> bool {
        false
    }
    fn support_for_internal_casting(&self) -> bool {
        true
    }
    fn support_for_utc_casting(&self) -> bool {
        true
    }
    fn support_for_integer_booleans(&self) -> bool {
        false
    }
    fn support_for_alter_locks(&self) -> bool {
        false
    }
    fn support_non_utf_characters(&self) -> bool {
        false
    }
    fn support_for_trigram_index(&self) -> bool {
        false
    }
    fn support_for_pcre_regex(&self) -> bool {
        true
    }
    fn support_for_posix_regex(&self) -> bool {
        false
    }
    fn support_for_ttl_indexes(&self) -> bool {
        true
    }
    fn support_for_transaction_retries(&self) -> bool {
        false
    }
    fn support_for_nested_transactions(&self) -> bool {
        false
    }
}
