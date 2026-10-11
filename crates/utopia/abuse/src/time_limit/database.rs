//! Fixed-window counters as documents of an `abuse` collection: one per key
//! and window, unique on both.

use std::sync::Mutex;

use super::{Rule, Store};
use crate::{Error, Result};

/// One counter document of the `abuse` collection.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Entry {
    /// The document's id (`$id`); empty for one not created yet.
    pub id: String,
    /// The limit's key.
    pub key: String,
    /// The window's start, as the database formats dates
    /// (`Y-m-d H:i:s.v`, UTC).
    pub time: String,
    /// The count.
    pub count: i64,
}

/// The document operations [`Database`] needs, all on the `abuse`
/// collection and bypassing authorization.
///
/// PHP: the calls `TimeLimit\Database` makes on `Utopia\Database\Database`.
/// Implement it over a database client; [`Memory`] keeps the documents in
/// memory.
pub trait Documents: Send + Sync {
    /// Whether the database exists.
    ///
    /// PHP: `$db->exists($db->getDatabase())`.
    fn exists(&self) -> impl Future<Output = Result<bool>> + Send;

    /// Creates the collection with its attributes and indexes, failing with
    /// [`Error::Duplicate`] when it exists.
    ///
    /// PHP: `createCollection(Database::COLLECTION, ATTRIBUTES, INDEXES)`.
    fn create_collection(&self) -> impl Future<Output = Result<()>> + Send;

    /// The document of `key` and `time`, if any.
    ///
    /// PHP: `findOne()` with `Query::equal('key', ...)` and
    /// `Query::equal('time', ...)`.
    fn find_one(&self, key: &str, time: &str) -> impl Future<Output = Result<Option<Entry>>> + Send;

    /// Creates a document (its `id` is chosen by the store), failing with
    /// [`Error::Duplicate`] when one exists for its key and time.
    fn create(&self, entry: &Entry) -> impl Future<Output = Result<()>> + Send;

    /// Adds one to the count of document `id`.
    ///
    /// PHP: `increaseDocumentAttribute($collection, $id, 'count')`.
    fn increase(&self, id: &str) -> impl Future<Output = Result<()>> + Send;

    /// Sets the count of document `id`.
    fn update(&self, id: &str, count: i64) -> impl Future<Output = Result<()>> + Send;

    /// Documents newest first, `limit` from `offset` (each `None` for no
    /// bound).
    ///
    /// PHP: `find()` with `Query::orderDesc('')` and the optional
    /// `Query::offset()`, `Query::limit()`.
    fn list(&self, offset: Option<i64>, limit: Option<i64>) -> impl Future<Output = Result<Vec<Entry>>> + Send;

    /// Some documents (the database's default page, 25) whose time is before
    /// `time`.
    ///
    /// PHP: `find()` with `Query::lessThan('time', $time)`.
    fn older(&self, time: &str) -> impl Future<Output = Result<Vec<Entry>>> + Send;

    /// Deletes document `id`.
    fn delete(&self, id: &str) -> impl Future<Output = Result<()>> + Send;
}

use std::future::Future;

impl<D: Documents + ?Sized> Documents for std::sync::Arc<D> {
    fn exists(&self) -> impl Future<Output = Result<bool>> + Send {
        (**self).exists()
    }

    fn create_collection(&self) -> impl Future<Output = Result<()>> + Send {
        (**self).create_collection()
    }

    fn find_one(&self, key: &str, time: &str) -> impl Future<Output = Result<Option<Entry>>> + Send {
        (**self).find_one(key, time)
    }

    fn create(&self, entry: &Entry) -> impl Future<Output = Result<()>> + Send {
        (**self).create(entry)
    }

    fn increase(&self, id: &str) -> impl Future<Output = Result<()>> + Send {
        (**self).increase(id)
    }

    fn update(&self, id: &str, count: i64) -> impl Future<Output = Result<()>> + Send {
        (**self).update(id, count)
    }

    fn list(&self, offset: Option<i64>, limit: Option<i64>) -> impl Future<Output = Result<Vec<Entry>>> + Send {
        (**self).list(offset, limit)
    }

    fn older(&self, time: &str) -> impl Future<Output = Result<Vec<Entry>>> + Send {
        (**self).older(time)
    }

    fn delete(&self, id: &str) -> impl Future<Output = Result<()>> + Send {
        (**self).delete(id)
    }
}

/// The collection [`Database`] counts in.
///
/// PHP: `Database::COLLECTION`.
pub const COLLECTION: &str = "abuse";

/// Counters as documents of the `abuse` collection: one per key and window,
/// with its count.
///
/// ```
/// use utopia_abuse::{TimeLimit, time_limit};
///
/// # tokio::runtime::Builder::new_current_thread().build()?.block_on(async {
/// let store = time_limit::Database::new(std::sync::Arc::new(time_limit::Memory::new()));
/// store.setup().await?;
/// let limit = TimeLimit::new("ip:{ip}", 1, 60, store)?.with_param("{ip}", "203.0.113.7");
/// assert!(!limit.check_at(1_700_000_000).await?.limited);
/// assert!(limit.check_at(1_700_000_001).await?.limited);
/// # Ok::<(), utopia_abuse::Error>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Abuse\Adapter\TimeLimit\Database as Store;
/// use Utopia\Cache\Adapter\None as NoCache;
/// use Utopia\Cache\Cache;
/// use Utopia\Database\Adapter\Memory;
/// use Utopia\Database\Database;
///
/// $db = new Database(new Memory(), new Cache(new NoCache()));
/// $db->setDatabase('abuse');
/// $db->create();
/// $limit = new Store('ip:{ip}', 1, 60, $db);
/// $limit->setup();
/// $limit = $limit->withParam('{ip}', '203.0.113.7');
/// var_dump($limit->check()->limited); // bool(false)
/// var_dump($limit->check()->limited); // bool(true)
/// ```
///
/// PHP: `Utopia\Abuse\Adapter\TimeLimit\Database`, over a
/// `Utopia\Database\Database`; here over any [`Documents`] store.
#[derive(Debug, Clone)]
pub struct Database<D> {
    db: D,
}

impl<D: Documents> Database<D> {
    /// Counters in `db`.
    pub fn new(db: D) -> Self {
        Database { db }
    }

    /// The document store.
    pub fn documents(&self) -> &D {
        &self.db
    }

    /// Creates the `abuse` collection, unless it exists. Fails with
    /// [`Error::Exception`] when the database does not exist.
    pub async fn setup(&self) -> Result<()> {
        if !self.db.exists().await? {
            return Err(Error::Exception("You need to create database before running timelimit setup".into()));
        }
        match self.db.create_collection().await {
            Ok(()) | Err(Error::Duplicate(_)) => Ok(()),
            Err(e) => Err(e),
        }
    }

    /// The document of `key` and `time`, created with `count` when there is
    /// none. `None` when it was created. `lost` is the message when a
    /// document that collided on creation is then not found.
    async fn find_or_create(&self, key: &str, time: &str, count: i64, lost: &str) -> Result<Option<Entry>> {
        if let Some(entry) = self.db.find_one(key, time).await? {
            return Ok(Some(entry));
        }
        let entry = Entry { id: String::new(), key: key.to_owned(), time: time.to_owned(), count };
        match self.db.create(&entry).await {
            Ok(()) => Ok(None),
            // Another request created it first.
            Err(Error::Duplicate(_)) => match self.db.find_one(key, time).await? {
                Some(entry) => Ok(Some(entry)),
                None => Err(Error::Exception(lost.into())),
            },
            Err(e) => Err(e),
        }
    }
}

/// A Unix time as the database stores dates: `Y-m-d H:i:s.v` in UTC.
pub(crate) fn datetime(timestamp: i64) -> String {
    String::from_utf8(php_std::datetime::gmdate(b"Y-m-d H:i:s.v", timestamp)).unwrap_or_default()
}

impl<D: Documents> Store for Database<D> {
    type Log = Entry;

    /// Creates the window's document with a count of 1, or adds one to it
    /// unless it has reached the limit.
    async fn hit(&self, key: &str, window: i64, rule: Rule) -> Result<i64> {
        let time = datetime(window);
        let Some(entry) = self.find_or_create(key, &time, 1, "Document Not Found").await? else {
            return Ok(0);
        };
        if entry.count >= rule.limit {
            return Ok(entry.count);
        }
        self.db.increase(&entry.id).await?;
        Ok(entry.count)
    }

    /// The window's count, `0` without a document.
    async fn count(&self, key: &str, window: i64) -> Result<i64> {
        Ok(self.db.find_one(key, &datetime(window)).await?.map_or(0, |e| e.count))
    }

    /// Creates the window's document with `value`, or updates its count.
    async fn set(&self, key: &str, window: i64, value: i64, _rule: Rule) -> Result<()> {
        let time = datetime(window);
        let lost = "Unable to find abuse tracking document after race condition handling";
        if let Some(entry) = self.find_or_create(key, &time, value, lost).await? {
            self.db.update(&entry.id, value).await?;
        }
        Ok(())
    }

    /// Documents newest first.
    async fn logs(&self, offset: Option<i64>, limit: Option<i64>) -> Result<Vec<Entry>> {
        self.db.list(offset, limit).await
    }

    /// Deletes the documents of windows before `timestamp`, a page at a time.
    async fn cleanup(&self, timestamp: i64) -> Result<bool> {
        let time = datetime(timestamp);
        loop {
            let documents = self.db.older(&time).await?;
            if documents.is_empty() {
                return Ok(true);
            }
            for document in documents {
                self.db.delete(&document.id).await?;
            }
        }
    }
}

/// A [`Documents`] store in memory, for tests and single-process use. Ids
/// are sequential (`"1"`, `"2"`, ...).
///
/// PHP: a `Utopia\Database\Database` over the `Memory` adapter.
#[derive(Debug, Default)]
pub struct Memory {
    state: Mutex<MemoryState>,
}

#[derive(Debug, Default)]
struct MemoryState {
    database: bool,
    collection: bool,
    next: u64,
    entries: Vec<Entry>,
}

impl Memory {
    /// An empty store whose database exists.
    pub fn new() -> Self {
        Memory { state: Mutex::new(MemoryState { database: true, ..MemoryState::default() }) }
    }

    /// An empty store whose database does not exist.
    pub fn without_database() -> Self {
        Memory::default()
    }

    fn state(&self) -> std::sync::MutexGuard<'_, MemoryState> {
        self.state.lock().unwrap_or_else(|e| e.into_inner())
    }

    fn collection(state: &MemoryState) -> Result<()> {
        if state.collection { Ok(()) } else { Err(Error::Exception("Collection not found".into())) }
    }
}

impl Documents for Memory {
    async fn exists(&self) -> Result<bool> {
        Ok(self.state().database)
    }

    async fn create_collection(&self) -> Result<()> {
        let mut state = self.state();
        if state.collection {
            return Err(Error::Duplicate("Collection already exists".into()));
        }
        state.collection = true;
        Ok(())
    }

    async fn find_one(&self, key: &str, time: &str) -> Result<Option<Entry>> {
        let state = self.state();
        Self::collection(&state)?;
        Ok(state.entries.iter().find(|e| e.key == key && e.time == time).cloned())
    }

    async fn create(&self, entry: &Entry) -> Result<()> {
        let mut state = self.state();
        Self::collection(&state)?;
        if state.entries.iter().any(|e| e.key == entry.key && e.time == entry.time) {
            return Err(Error::Duplicate("Document already exists".into()));
        }
        state.next += 1;
        let id = state.next.to_string();
        state.entries.push(Entry { id, ..entry.clone() });
        Ok(())
    }

    async fn increase(&self, id: &str) -> Result<()> {
        let mut state = self.state();
        if let Some(e) = state.entries.iter_mut().find(|e| e.id == id) {
            e.count += 1;
        }
        Ok(())
    }

    async fn update(&self, id: &str, count: i64) -> Result<()> {
        let mut state = self.state();
        if let Some(e) = state.entries.iter_mut().find(|e| e.id == id) {
            e.count = count;
        }
        Ok(())
    }

    async fn list(&self, offset: Option<i64>, limit: Option<i64>) -> Result<Vec<Entry>> {
        let state = self.state();
        Self::collection(&state)?;
        let skip = offset.unwrap_or(0).max(0) as usize;
        let take = limit.map_or(usize::MAX, |l| l.max(0) as usize);
        Ok(state.entries.iter().rev().skip(skip).take(take).cloned().collect())
    }

    async fn older(&self, time: &str) -> Result<Vec<Entry>> {
        let state = self.state();
        Self::collection(&state)?;
        Ok(state.entries.iter().filter(|e| e.time.as_str() < time).take(25).cloned().collect())
    }

    async fn delete(&self, id: &str) -> Result<()> {
        self.state().entries.retain(|e| e.id != id);
        Ok(())
    }
}
