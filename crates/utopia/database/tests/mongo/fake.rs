//! A driver that needs no server: it records every call and answers with
//! the reply a test scripts for the method, or a default one, as PHP's
//! tests extend `Utopia\Mongo\Client`.

use std::collections::HashMap;
use std::sync::atomic::{AtomicBool, AtomicI64, Ordering};
use std::sync::{Arc, Mutex};

use async_trait::async_trait;
use utopia_database::Adapter;
use utopia_database::adapter::mongo::{Driver, DriverResult, Mongo};
use utopia_database::validator::authorization::Authorization;
use utopia_mongo::bson::{Bson, Document as BsonDocument, doc};
use utopia_mongo::{Options, Reply, Session, Upsert};

/// A scripted reply: the call's arguments to its result.
pub type Handler = Arc<dyn Fn(&[Bson]) -> DriverResult<Bson> + Send + Sync>;

/// The driver. See the [module docs](self).
#[derive(Default)]
pub struct Fake {
    calls: Mutex<Vec<(String, Vec<Bson>)>>,
    handlers: Mutex<HashMap<String, Handler>>,
    /// Whether `isReplicaSet()` answers `true`.
    pub replica_set: AtomicBool,
    /// Sessions started.
    pub sessions: AtomicI64,
    /// Commits sent.
    pub commits: AtomicI64,
    /// Aborts sent.
    pub aborts: AtomicI64,
}

/// An empty cursor reply.
pub fn empty_batch() -> Bson {
    Bson::Document(doc! { "cursor": { "firstBatch": [], "id": 0 } })
}

/// A cursor reply holding `rows`, closed.
pub fn batch(rows: Vec<BsonDocument>) -> Bson {
    Bson::Document(doc! { "cursor": { "firstBatch": rows, "id": 0 } })
}

/// A server error of `code`.
pub fn error(message: &str, code: i64) -> utopia_mongo::Error {
    utopia_mongo::Error::exception(message, code)
}

/// A server error of `code` with error labels.
pub fn labelled(message: &str, code: i64, labels: &[&str]) -> utopia_mongo::Error {
    let mut failure = utopia_mongo::Failure::new(message, code);
    failure.error_labels = labels.iter().map(|l| Bson::String((*l).to_owned())).collect();
    utopia_mongo::Error::Exception(Box::new(failure))
}

fn document(b: Bson) -> BsonDocument {
    match b {
        Bson::Document(d) => d,
        _ => BsonDocument::new(),
    }
}

fn integer(b: &Bson) -> i64 {
    match b {
        Bson::Int32(i) => i64::from(*i),
        Bson::Int64(i) => *i,
        Bson::Double(f) => *f as i64,
        Bson::Boolean(b) => i64::from(*b),
        _ => 0,
    }
}

fn session_flag(session: Option<&Session>) -> Bson {
    Bson::Boolean(session.is_some())
}

fn session_of(options: &Options) -> Bson {
    session_flag(options.session.as_ref())
}

impl Fake {
    /// A driver for a standalone server.
    pub fn new() -> Arc<Fake> {
        Arc::new(Fake::default())
    }

    /// A driver for a replica set.
    pub fn replica_set() -> Arc<Fake> {
        let fake = Fake::default();
        fake.replica_set.store(true, Ordering::Relaxed);
        Arc::new(fake)
    }

    /// Scripts `method`.
    pub fn on(&self, method: &str, handler: impl Fn(&[Bson]) -> DriverResult<Bson> + Send + Sync + 'static) {
        self.handlers.lock().unwrap().insert(method.to_owned(), Arc::new(handler));
    }

    /// The arguments of every call of `method`, in order.
    pub fn args(&self, method: &str) -> Vec<Vec<Bson>> {
        self.calls.lock().unwrap().iter().filter(|(m, _)| m == method).map(|(_, a)| a.clone()).collect()
    }

    /// Forgets the calls.
    pub fn clear(&self) {
        self.calls.lock().unwrap().clear();
    }

    fn call(&self, method: &str, args: Vec<Bson>) -> Option<DriverResult<Bson>> {
        self.calls.lock().unwrap().push((method.to_owned(), args.clone()));
        let handler = self.handlers.lock().unwrap().get(method).cloned();
        handler.map(|h| h(&args))
    }
}

#[async_trait]
impl Driver for Fake {
    async fn connect(&self) -> DriverResult<()> {
        self.call("connect", vec![]).unwrap_or(Ok(Bson::Null)).map(|_| ())
    }

    fn host(&self) -> String {
        "fake".to_owned()
    }

    fn create_uuid(&self) -> String {
        static NEXT: AtomicI64 = AtomicI64::new(1);
        format!("0190f2a4-0000-7000-8000-{:012}", NEXT.fetch_add(1, Ordering::Relaxed))
    }

    async fn query(&self, session: Option<&Session>, command: BsonDocument, db: Option<&str>) -> DriverResult<Reply> {
        let db = db.map_or(Bson::Null, |d| Bson::String(d.to_owned()));
        let reply = self
            .call("query", vec![Bson::Document(command), db, session_flag(session)])
            .unwrap_or(Ok(Bson::Int32(1)))?;
        Ok(match reply {
            Bson::Document(d) => Reply::Document(d),
            other => Reply::Count(other),
        })
    }

    async fn list_database_names(&self) -> DriverResult<BsonDocument> {
        self.call("listDatabaseNames", vec![])
            .unwrap_or_else(|| Ok(Bson::Document(doc! { "databases": [] })))
            .map(document)
    }

    async fn drop_database(&self, options: BsonDocument, db: Option<&str>) -> DriverResult<bool> {
        let db = db.map_or(Bson::Null, |d| Bson::String(d.to_owned()));
        self.call("dropDatabase", vec![Bson::Document(options), db])
            .unwrap_or(Ok(Bson::Boolean(true)))
            .map(|b| b.as_bool().unwrap_or(true))
    }

    async fn create_collection(&self, name: &str, options: Options) -> DriverResult<bool> {
        self.call("createCollection", vec![Bson::String(name.to_owned()), session_of(&options)])
            .unwrap_or(Ok(Bson::Boolean(true)))
            .map(|b| b.as_bool().unwrap_or(true))
    }

    async fn drop_collection(&self, name: &str, _options: Options) -> DriverResult<bool> {
        self.call("dropCollection", vec![Bson::String(name.to_owned())])
            .unwrap_or(Ok(Bson::Boolean(true)))
            .map(|b| b.as_bool().unwrap_or(true))
    }

    async fn create_indexes(
        &self,
        collection: &str,
        indexes: Vec<BsonDocument>,
        options: Options,
    ) -> DriverResult<bool> {
        let indexes = Bson::Array(indexes.into_iter().map(Bson::Document).collect());
        self.call(
            "createIndexes",
            vec![
                Bson::String(collection.to_owned()),
                indexes,
                Bson::Document(options.fields.clone()),
                session_of(&options),
            ],
        )
        .unwrap_or(Ok(Bson::Boolean(true)))
        .map(|b| b.as_bool().unwrap_or(true))
    }

    async fn drop_indexes(&self, collection: &str, indexes: Vec<String>, _options: Options) -> DriverResult<()> {
        let indexes = Bson::Array(indexes.into_iter().map(Bson::String).collect());
        self.call("dropIndexes", vec![Bson::String(collection.to_owned()), indexes])
            .unwrap_or(Ok(Bson::Null))
            .map(|_| ())
    }

    async fn insert(&self, collection: &str, document: BsonDocument, options: Options) -> DriverResult<BsonDocument> {
        let reply = self.call(
            "insert",
            vec![Bson::String(collection.to_owned()), Bson::Document(document.clone()), session_of(&options)],
        );
        match reply {
            Some(Ok(Bson::Document(d))) => Ok(d),
            Some(Err(e)) => Err(e),
            _ => Ok(document),
        }
    }

    async fn insert_many(
        &self,
        collection: &str,
        documents: Vec<BsonDocument>,
        options: Options,
    ) -> DriverResult<Vec<BsonDocument>> {
        let list = Bson::Array(documents.iter().cloned().map(Bson::Document).collect());
        match self.call("insertMany", vec![Bson::String(collection.to_owned()), list, session_of(&options)]) {
            Some(Ok(Bson::Array(items))) => Ok(items.into_iter().map(document).collect()),
            Some(Err(e)) => Err(e),
            _ => Ok(documents),
        }
    }

    async fn update(
        &self,
        collection: &str,
        filter: BsonDocument,
        updates: BsonDocument,
        options: Options,
        multi: bool,
    ) -> DriverResult<i64> {
        let args = vec![
            Bson::String(collection.to_owned()),
            Bson::Document(filter),
            Bson::Document(updates),
            Bson::Document(options.fields.clone()),
            Bson::Boolean(multi),
            session_of(&options),
        ];
        self.call("update", args).unwrap_or(Ok(Bson::Int32(1))).map(|b| integer(&b))
    }

    async fn upsert(&self, collection: &str, operations: Vec<Upsert>, options: Options) -> DriverResult<i64> {
        let count = operations.len() as i64;
        let operations = Bson::Array(
            operations
                .into_iter()
                .map(|o| Bson::Document(doc! { "filter": o.filter, "update": o.update, "multi": o.multi }))
                .collect(),
        );
        self.call("upsert", vec![Bson::String(collection.to_owned()), operations, session_of(&options)])
            .unwrap_or(Ok(Bson::Int64(count)))
            .map(|b| integer(&b))
    }

    async fn find(&self, collection: &str, filter: BsonDocument, options: Options) -> DriverResult<BsonDocument> {
        let args = vec![
            Bson::String(collection.to_owned()),
            Bson::Document(filter),
            Bson::Document(options.fields.clone()),
            session_of(&options),
        ];
        self.call("find", args).unwrap_or_else(|| Ok(empty_batch())).map(document)
    }

    async fn aggregate(&self, collection: &str, pipeline: Vec<Bson>, options: Options) -> DriverResult<BsonDocument> {
        let args = vec![
            Bson::String(collection.to_owned()),
            Bson::Array(pipeline),
            Bson::Document(options.fields.clone()),
            session_of(&options),
        ];
        self.call("aggregate", args)
            .unwrap_or_else(|| Ok(Bson::Document(doc! { "cursor": { "firstBatch": [] } })))
            .map(document)
    }

    async fn get_more(&self, cursor_id: i64, collection: &str, batch_size: i64) -> DriverResult<BsonDocument> {
        self.call("getMore", vec![Bson::Int64(cursor_id), Bson::String(collection.to_owned()), Bson::Int64(batch_size)])
            .unwrap_or_else(|| Ok(Bson::Document(doc! { "cursor": { "nextBatch": [], "id": 0 } })))
            .map(document)
    }

    async fn delete(
        &self,
        collection: &str,
        filter: BsonDocument,
        limit: i64,
        delete_options: BsonDocument,
        options: Options,
    ) -> DriverResult<i64> {
        let args = vec![
            Bson::String(collection.to_owned()),
            Bson::Document(filter),
            Bson::Int64(limit),
            Bson::Document(delete_options),
            session_of(&options),
        ];
        self.call("delete", args).unwrap_or(Ok(Bson::Int32(1))).map(|b| integer(&b))
    }

    async fn start_session(&self) -> DriverResult<Session> {
        if let Some(Err(e)) = self.call("startSession", vec![]) {
            return Err(e);
        }
        let n = self.sessions.fetch_add(1, Ordering::Relaxed) + 1;
        Ok(Session { id: doc! { "id": n }, session_id: n.to_string() })
    }

    async fn start_transaction(&self, _session: &Session) -> DriverResult<bool> {
        self.call("startTransaction", vec![]).unwrap_or(Ok(Bson::Boolean(true))).map(|_| true)
    }

    async fn commit_transaction(&self, _session: &Session, options: BsonDocument) -> DriverResult<()> {
        self.commits.fetch_add(1, Ordering::Relaxed);
        self.call("commitTransaction", vec![Bson::Document(options)]).unwrap_or(Ok(Bson::Null)).map(|_| ())
    }

    async fn abort_transaction(&self, _session: &Session) -> DriverResult<()> {
        self.aborts.fetch_add(1, Ordering::Relaxed);
        self.call("abortTransaction", vec![]).unwrap_or(Ok(Bson::Null)).map(|_| ())
    }

    async fn end_sessions(&self, _sessions: &[Session]) -> DriverResult<()> {
        self.call("endSessions", vec![]).unwrap_or(Ok(Bson::Null)).map(|_| ())
    }

    async fn is_replica_set(&self) -> DriverResult<bool> {
        let known = self.replica_set.load(Ordering::Relaxed);
        self.call("isReplicaSet", vec![]).unwrap_or(Ok(Bson::Boolean(known))).map(|b| b.as_bool().unwrap_or(known))
    }
}

/// An adapter over `fake`, its authorization disabled when `authorize` is
/// false, under `namespace`.
pub async fn adapter(fake: &Arc<Fake>, namespace: &str, authorize: bool) -> Mongo {
    let adapter = Mongo::with_driver(fake.clone()).await.expect("connects");
    let authorization = Authorization::new(true);
    if !authorize {
        authorization.disable();
    }
    adapter.set_authorization(Arc::new(authorization));
    adapter.set_namespace(namespace);
    adapter
}

/// `$value` at the dotted `path` of a BSON value (`0`, `1`, ... index arrays).
pub fn at<'a>(value: &'a Bson, path: &str) -> Option<&'a Bson> {
    let mut current = value;
    for part in path.split('.').filter(|p| !p.is_empty()) {
        current = match current {
            Bson::Document(d) => d.get(part)?,
            Bson::Array(items) => items.get(part.parse::<usize>().ok()?)?,
            _ => return None,
        };
    }
    Some(current)
}

/// A regular expression as the adapter sends one.
pub fn regex(pattern: &str, flags: &str) -> Bson {
    use utopia_mongo::bson::raw::CString;
    Bson::RegularExpression(utopia_mongo::bson::Regex {
        pattern: CString::try_from(pattern).unwrap(),
        options: CString::try_from(flags).unwrap(),
    })
}
