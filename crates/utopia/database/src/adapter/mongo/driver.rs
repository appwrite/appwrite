//! [`Driver`]: the client calls the adapter makes, so a test can stand in
//! for the server as PHP's tests extend `Utopia\Mongo\Client`.

use async_trait::async_trait;
use utopia_mongo::bson::{Bson, Document as BsonDocument};
use utopia_mongo::{Client, Options, Reply, Session, Upsert};

/// What a client call fails with.
pub type DriverResult<T> = std::result::Result<T, utopia_mongo::Error>;

/// The calls [`super::Mongo`] makes on its client: each one is a method of
/// `Utopia\Mongo\Client` the PHP adapter calls, with the same arguments.
/// [`utopia_mongo::Client`] implements it; a test implements it to record
/// the commands and script the replies.
///
/// PHP: the methods of `Utopia\Mongo\Client` the adapter calls.
#[async_trait]
pub trait Driver: Send + Sync {
    /// `connect()`.
    async fn connect(&self) -> DriverResult<()>;

    /// `getHost()`.
    fn host(&self) -> String;

    /// `createUuid()`.
    fn create_uuid(&self) -> String;

    /// `query($command, $db)`, `$command['session']` being `session`.
    async fn query(&self, session: Option<&Session>, command: BsonDocument, db: Option<&str>) -> DriverResult<Reply>;

    /// `listDatabaseNames()`.
    async fn list_database_names(&self) -> DriverResult<BsonDocument>;

    /// `dropDatabase($options, $db)`.
    async fn drop_database(&self, options: BsonDocument, db: Option<&str>) -> DriverResult<bool>;

    /// `createCollection($name, $options)`.
    async fn create_collection(&self, name: &str, options: Options) -> DriverResult<bool>;

    /// `dropCollection($name, $options)`.
    async fn drop_collection(&self, name: &str, options: Options) -> DriverResult<bool>;

    /// `createIndexes($collection, $indexes, $options)`.
    async fn create_indexes(
        &self,
        collection: &str,
        indexes: Vec<BsonDocument>,
        options: Options,
    ) -> DriverResult<bool>;

    /// `dropIndexes($collection, $indexes, $options)`.
    async fn drop_indexes(&self, collection: &str, indexes: Vec<String>, options: Options) -> DriverResult<()>;

    /// `insert($collection, $document, $options)`: the document as inserted.
    async fn insert(&self, collection: &str, document: BsonDocument, options: Options) -> DriverResult<BsonDocument>;

    /// `insertMany($collection, $documents, $options)`: the documents as
    /// inserted.
    async fn insert_many(
        &self,
        collection: &str,
        documents: Vec<BsonDocument>,
        options: Options,
    ) -> DriverResult<Vec<BsonDocument>>;

    /// `update($collection, $where, $updates, $options, $multi)`.
    async fn update(
        &self,
        collection: &str,
        filter: BsonDocument,
        updates: BsonDocument,
        options: Options,
        multi: bool,
    ) -> DriverResult<i64>;

    /// `upsert($collection, $operations, $options)`.
    async fn upsert(&self, collection: &str, operations: Vec<Upsert>, options: Options) -> DriverResult<i64>;

    /// `find($collection, $filters, $options)`: the reply.
    async fn find(&self, collection: &str, filter: BsonDocument, options: Options) -> DriverResult<BsonDocument>;

    /// `aggregate($collection, $pipeline, $options)`: the reply.
    async fn aggregate(&self, collection: &str, pipeline: Vec<Bson>, options: Options) -> DriverResult<BsonDocument>;

    /// `getMore($cursorId, $collection, $batchSize)`: the reply.
    async fn get_more(&self, cursor_id: i64, collection: &str, batch_size: i64) -> DriverResult<BsonDocument>;

    /// `delete($collection, $filters, $limit, $deleteOptions, $options)`.
    async fn delete(
        &self,
        collection: &str,
        filter: BsonDocument,
        limit: i64,
        delete_options: BsonDocument,
        options: Options,
    ) -> DriverResult<i64>;

    /// `startSession()`.
    async fn start_session(&self) -> DriverResult<Session>;

    /// `startTransaction($session)`.
    async fn start_transaction(&self, session: &Session) -> DriverResult<bool>;

    /// `commitTransaction($session, $options)` (`writeConcern`).
    async fn commit_transaction(&self, session: &Session, options: BsonDocument) -> DriverResult<()>;

    /// `abortTransaction($session)`.
    async fn abort_transaction(&self, session: &Session) -> DriverResult<()>;

    /// `endSessions($sessions)`.
    async fn end_sessions(&self, sessions: &[Session]) -> DriverResult<()>;

    /// `isReplicaSet()`.
    async fn is_replica_set(&self) -> DriverResult<bool>;
}

#[async_trait]
impl Driver for Client {
    async fn connect(&self) -> DriverResult<()> {
        Client::connect(self).await.map(|_| ())
    }

    fn host(&self) -> String {
        Client::host(self).to_owned()
    }

    fn create_uuid(&self) -> String {
        Client::create_uuid(self)
    }

    async fn query(&self, session: Option<&Session>, command: BsonDocument, db: Option<&str>) -> DriverResult<Reply> {
        self.query_in(session, command, db).await
    }

    async fn list_database_names(&self) -> DriverResult<BsonDocument> {
        Client::list_database_names(self).await
    }

    async fn drop_database(&self, options: BsonDocument, db: Option<&str>) -> DriverResult<bool> {
        Client::drop_database(self, options, db).await
    }

    async fn create_collection(&self, name: &str, options: Options) -> DriverResult<bool> {
        Client::create_collection(self, name, options).await
    }

    async fn drop_collection(&self, name: &str, options: Options) -> DriverResult<bool> {
        Client::drop_collection(self, name, options).await
    }

    async fn create_indexes(
        &self,
        collection: &str,
        indexes: Vec<BsonDocument>,
        options: Options,
    ) -> DriverResult<bool> {
        Client::create_indexes(self, collection, indexes, options).await
    }

    async fn drop_indexes(&self, collection: &str, indexes: Vec<String>, options: Options) -> DriverResult<()> {
        let names = Bson::Array(indexes.into_iter().map(Bson::String).collect());
        Client::drop_indexes(self, collection, names, options).await.map(|_| ())
    }

    async fn insert(&self, collection: &str, document: BsonDocument, options: Options) -> DriverResult<BsonDocument> {
        Client::insert(self, collection, document, options).await
    }

    async fn insert_many(
        &self,
        collection: &str,
        documents: Vec<BsonDocument>,
        options: Options,
    ) -> DriverResult<Vec<BsonDocument>> {
        Client::insert_many(self, collection, documents, options).await
    }

    async fn update(
        &self,
        collection: &str,
        filter: BsonDocument,
        updates: BsonDocument,
        options: Options,
        multi: bool,
    ) -> DriverResult<i64> {
        Client::update(self, collection, filter, updates, options, multi).await
    }

    async fn upsert(&self, collection: &str, operations: Vec<Upsert>, options: Options) -> DriverResult<i64> {
        Client::upsert(self, collection, operations, options).await
    }

    async fn find(&self, collection: &str, filter: BsonDocument, options: Options) -> DriverResult<BsonDocument> {
        Client::find(self, collection, filter, options).await
    }

    async fn aggregate(&self, collection: &str, pipeline: Vec<Bson>, options: Options) -> DriverResult<BsonDocument> {
        Client::aggregate(self, collection, pipeline, options).await
    }

    async fn get_more(&self, cursor_id: i64, collection: &str, batch_size: i64) -> DriverResult<BsonDocument> {
        Client::get_more(self, cursor_id, collection, batch_size).await
    }

    async fn delete(
        &self,
        collection: &str,
        filter: BsonDocument,
        limit: i64,
        delete_options: BsonDocument,
        options: Options,
    ) -> DriverResult<i64> {
        Client::delete(self, collection, filter, limit, delete_options, options).await
    }

    async fn start_session(&self) -> DriverResult<Session> {
        Client::start_session(self, BsonDocument::new()).await
    }

    async fn start_transaction(&self, session: &Session) -> DriverResult<bool> {
        Client::start_transaction(self, session, BsonDocument::new()).await
    }

    async fn commit_transaction(&self, session: &Session, options: BsonDocument) -> DriverResult<()> {
        Client::commit_transaction(self, session, options).await.map(|_| ())
    }

    async fn abort_transaction(&self, session: &Session) -> DriverResult<()> {
        Client::abort_transaction(self, session, BsonDocument::new()).await.map(|_| ())
    }

    async fn end_sessions(&self, sessions: &[Session]) -> DriverResult<()> {
        Client::end_sessions(self, sessions, BsonDocument::new()).await.map(|_| ())
    }

    async fn is_replica_set(&self) -> DriverResult<bool> {
        Client::is_replica_set(self).await
    }
}
