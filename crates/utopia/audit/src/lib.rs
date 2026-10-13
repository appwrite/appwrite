//! Audit trails: who did what to which resource, from where and when.
//!
//! [`Audit`] records [`Log`]s through an [`Adapter`] and reads them back by
//! user, by resource, by event and time window, or with any [`Query`]. Two
//! adapters ship: [`adapter::Database`] keeps logs as documents of a
//! `utopia-database` collection (feature `database`), and
//! [`adapter::ClickHouse`] as rows of a ClickHouse MergeTree table over its
//! HTTP interface (feature `clickhouse`), with the actor, resource, project,
//! geo, SDK and user-agent columns analytics need.
//!
//! ```
//! # #[cfg(feature = "database")]
//! # fn main() -> Result<(), Box<dyn std::error::Error>> {
//! use std::sync::Arc;
//!
//! use utopia_audit::{Audit, Page, Range, adapter};
//! use utopia_cache::{Cache, None as NoCache};
//! use utopia_database::adapter::memory::Memory;
//! use utopia_database::{Authorization, Database};
//!
//! tokio::runtime::Builder::new_current_thread().build()?.block_on(async {
//!     let db = Database::new(Arc::new(Memory::new()), Arc::new(Cache::from_dyn(Arc::new(NoCache))), Vec::new());
//!     db.set_authorization(Arc::new(Authorization::new()));
//!     db.set_database("audit")?;
//!     db.create(None).await?;
//!
//!     let audit = Audit::new(adapter::Database::new(db));
//!     audit.setup().await?;
//!     audit.log(Some("u1"), "users.update", "user/u1", "Mozilla/5.0", "203.0.113.7", Default::default()).await?;
//!
//!     let logs = audit.logs_by_user("u1", Range::default(), Page::default()).await?;
//!     assert_eq!(logs[0].event(), "users.update");
//!     assert_eq!(audit.count_logs_by_user("u1", Range::default(), None).await?, 1);
//!     Ok::<_, Box<dyn std::error::Error>>(())
//! })?;
//! # Ok(())
//! # }
//! # #[cfg(not(feature = "database"))]
//! # fn main() {}
//! ```
//!
//! ```php
//! use Utopia\Audit\Adapter\Database as Adapter;
//! use Utopia\Audit\Audit;
//! use Utopia\Cache\Adapter\None as NoCache;
//! use Utopia\Cache\Cache;
//! use Utopia\Database\Adapter\Memory;
//! use Utopia\Database\Database;
//!
//! $db = new Database(new Memory(), new Cache(new NoCache()));
//! $db->setDatabase('audit');
//! $db->create();
//!
//! $audit = new Audit(new Adapter($db));
//! $audit->setup();
//! $audit->log('u1', 'users.update', 'user/u1', 'Mozilla/5.0', '203.0.113.7');
//!
//! echo $audit->getLogsByUser('u1')[0]->getEvent(), "\n"; // users.update
//! echo $audit->countLogsByUser('u1'), "\n"; // 1
//! ```
//!
//! PHP: ports `utopia-php/audit`. Both adapters read and write the same
//! collections and tables as PHP, so logs written by one runtime are read by
//! the other.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Audit\Audit` | [`Audit`] |
//! | `Utopia\Audit\Adapter` | [`Adapter`] |
//! | `Utopia\Audit\Adapter\SQL` | [`schema::Sql`], [`schema`] (attributes, indexes, `parseResource()`) |
//! | `Utopia\Audit\Adapter\Database` | [`adapter::Database`] (feature `database`) |
//! | `Utopia\Audit\Adapter\ClickHouse` | [`adapter::ClickHouse`] (feature `clickhouse`) |
//! | `Utopia\Audit\Log` | [`Log`] |
//! | `Utopia\Audit\Query` | [`Query`] (`utopia_query::Query`) and [`query`] (`equal`, `lessThan`, `greaterThan`, `between`, `TYPE_*`) |
//! | the `$after`, `$before` arguments | [`Range`] |
//! | the `$limit`, `$offset`, `$ascending` arguments | [`Page`] |
//! | `\Exception`, `\InvalidArgumentException`, ... | [`Error`] |

#![warn(missing_docs)]

pub mod adapter;
mod error;
mod log;
pub mod query;
pub mod schema;

pub use adapter::Adapter;
pub use error::{Error, Result};
pub use log::Log;
pub use php_std::datetime::DateTime;
pub use query::Query;

use php_std::{Array, ArrayKey, Str, Value};

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;

/// A time window: logs after `after` and before `before` (both exclusive
/// on ClickHouse and on single bounds; `between` is inclusive when both are
/// given).
///
/// PHP: the `?\DateTime $after = null, ?\DateTime $before = null` arguments.
#[derive(Debug, Clone, Copy, Default)]
pub struct Range<'a> {
    /// Only logs after this time.
    pub after: Option<&'a DateTime>,
    /// Only logs before this time.
    pub before: Option<&'a DateTime>,
}

/// A page of logs: how many, from where, oldest or newest first.
///
/// PHP: the `int $limit = 25, int $offset = 0, bool $ascending = false`
/// arguments.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Page {
    /// The most logs returned (25).
    pub limit: i64,
    /// How many to skip (0).
    pub offset: i64,
    /// Oldest first instead of newest first (`false`).
    pub ascending: bool,
}

impl Default for Page {
    fn default() -> Page {
        Page { limit: 25, offset: 0, ascending: false }
    }
}

/// The audit log manager: records logs and reads them back through an
/// [`Adapter`].
///
/// `A` is the adapter type, so calls dispatch statically; `Audit<Box<dyn
/// Adapter>>` (or `Arc`) takes any adapter chosen at run time, and `&A`
/// borrows one.
///
/// PHP: `Utopia\Audit\Audit`.
#[derive(Debug, Clone)]
pub struct Audit<A: Adapter> {
    adapter: A,
}

impl<A: Adapter> Audit<A> {
    /// An audit trail stored by `adapter`.
    ///
    /// PHP: `new Audit($adapter)`.
    pub fn new(adapter: A) -> Audit<A> {
        Audit { adapter }
    }

    /// The adapter.
    ///
    /// PHP: `getAdapter()`.
    pub fn adapter(&self) -> &A {
        &self.adapter
    }

    /// Creates what the adapter stores logs in (a collection, a table).
    ///
    /// PHP: `setup()`.
    pub async fn setup(&self) -> Result<()> {
        self.adapter.setup().await
    }

    /// Records that `user_id` (or no one) did `event` to `resource`, from
    /// `user_agent` at `ip`, with `data`; the log as stored.
    ///
    /// PHP: `log($userId, $event, $resource, $userAgent, $ip, $data = [])`.
    pub async fn log(
        &self,
        user_id: Option<&str>,
        event: &str,
        resource: &str,
        user_agent: &str,
        ip: &str,
        data: Array,
    ) -> Result<Log> {
        let mut log = Array::with_capacity(6);
        let mut put = |key: &str, value: Value| {
            log.set(ArrayKey::normalize(Str::from(key)), value);
        };
        put("userId", user_id.map_or(Value::Null, Value::from));
        put("event", Value::from(event));
        put("resource", Value::from(resource));
        put("userAgent", Value::from(user_agent));
        put("ip", Value::from(ip));
        put("data", Value::Array(data));
        self.adapter.create(log).await
    }

    /// Records several logs at once (each with its own `time`); `true`.
    ///
    /// PHP: `logBatch($events)`.
    pub async fn log_batch(&self, events: Vec<Array>) -> Result<bool> {
        self.adapter.create_batch(events).await
    }

    /// The log `id`, if any.
    ///
    /// PHP: `getLogById($id)`.
    pub async fn log_by_id(&self, id: &str) -> Result<Option<Log>> {
        self.adapter.get_by_id(id).await
    }

    /// The logs of `user_id` in `range`, a `page` of them.
    ///
    /// PHP: `getLogsByUser($userId, $after, $before, $limit, $offset, $ascending)`.
    pub async fn logs_by_user(&self, user_id: &str, range: Range<'_>, page: Page) -> Result<Vec<Log>> {
        self.adapter.get_by_user(user_id, range, page).await
    }

    /// How many logs `user_id` has in `range`, counting up to `max`.
    ///
    /// PHP: `countLogsByUser($userId, $after, $before, $max)`.
    pub async fn count_logs_by_user(&self, user_id: &str, range: Range<'_>, max: Option<i64>) -> Result<i64> {
        self.adapter.count_by_user(user_id, range, max).await
    }

    /// The logs of `resource` in `range`, a `page` of them.
    ///
    /// PHP: `getLogsByResource($resource, $after, $before, $limit, $offset, $ascending)`.
    pub async fn logs_by_resource(&self, resource: &str, range: Range<'_>, page: Page) -> Result<Vec<Log>> {
        self.adapter.get_by_resource(resource, range, page).await
    }

    /// How many logs `resource` has in `range`, counting up to `max`.
    ///
    /// PHP: `countLogsByResource($resource, $after, $before, $max)`.
    pub async fn count_logs_by_resource(&self, resource: &str, range: Range<'_>, max: Option<i64>) -> Result<i64> {
        self.adapter.count_by_resource(resource, range, max).await
    }

    /// The logs of `user_id` with one of `events` in `range`, a `page` of them.
    ///
    /// PHP: `getLogsByUserAndEvents($userId, $events, $after, $before, $limit, $offset, $ascending)`.
    pub async fn logs_by_user_and_events(
        &self,
        user_id: &str,
        events: &[&str],
        range: Range<'_>,
        page: Page,
    ) -> Result<Vec<Log>> {
        self.adapter.get_by_user_and_events(user_id, events, range, page).await
    }

    /// How many logs `user_id` has with one of `events` in `range`, counting
    /// up to `max`.
    ///
    /// PHP: `countLogsByUserAndEvents($userId, $events, $after, $before, $max)`.
    pub async fn count_logs_by_user_and_events(
        &self,
        user_id: &str,
        events: &[&str],
        range: Range<'_>,
        max: Option<i64>,
    ) -> Result<i64> {
        self.adapter.count_by_user_and_events(user_id, events, range, max).await
    }

    /// The logs of `resource` with one of `events` in `range`, a `page` of them.
    ///
    /// PHP: `getLogsByResourceAndEvents($resource, $events, $after, $before, $limit, $offset, $ascending)`.
    pub async fn logs_by_resource_and_events(
        &self,
        resource: &str,
        events: &[&str],
        range: Range<'_>,
        page: Page,
    ) -> Result<Vec<Log>> {
        self.adapter.get_by_resource_and_events(resource, events, range, page).await
    }

    /// How many logs `resource` has with one of `events` in `range`,
    /// counting up to `max`.
    ///
    /// PHP: `countLogsByResourceAndEvents($resource, $events, $after, $before, $max)`.
    pub async fn count_logs_by_resource_and_events(
        &self,
        resource: &str,
        events: &[&str],
        range: Range<'_>,
        max: Option<i64>,
    ) -> Result<i64> {
        self.adapter.count_by_resource_and_events(resource, events, range, max).await
    }

    /// Deletes every log older than `datetime`; `true`.
    ///
    /// PHP: `cleanup($datetime)`.
    pub async fn cleanup(&self, datetime: &DateTime) -> Result<bool> {
        self.adapter.cleanup(datetime).await
    }

    /// The logs `queries` select.
    ///
    /// PHP: `find($queries)`.
    pub async fn find(&self, queries: &[Query]) -> Result<Vec<Log>> {
        self.adapter.find(queries).await
    }

    /// How many logs `queries` select (their filters), counting up to `max`.
    ///
    /// PHP: `count($queries, $max)`.
    pub async fn count(&self, queries: &[Query], max: Option<i64>) -> Result<i64> {
        self.adapter.count(queries, max).await
    }

    /// Whether the backing store answers; never an error.
    ///
    /// PHP: `ping()`.
    pub async fn ping(&self) -> bool {
        self.adapter.ping().await
    }
}
