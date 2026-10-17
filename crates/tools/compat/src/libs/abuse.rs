//! Compat adapter for `abuse`: maps `tests/compat/abuse/spec.json` operations onto `utopia-abuse`.

use std::sync::{Arc, Mutex};

use redis::aio::ConnectionManager;
use redis::cluster_async::ClusterConnection;
use serde_json::{Map, Value, json};
use utopia_abuse::recaptcha::{BoxFuture, Client, Request};
use utopia_abuse::{
    Adapter, Connection, Error, ReCaptcha, SlidingWindow, Stack, TimeLimit, TokenBucket, Verdict, sliding_window,
    time_limit, token_bucket,
};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes_value};
use crate::libs::database::codec::encode_array;

pub const OPS: &[&str] = &[
    "limit.new",
    "limit.check",
    "limit.peek",
    "limit.reset",
    "limit.key",
    "limit.with_params",
    "limit.with_param",
    "limit.logs",
    "limit.cleanup",
    "limit.run",
    "database.new",
    "database.setup",
    "database.attributes",
    "database.indexes",
    "database.store",
    "database.windows",
    "tables.new",
    "tables.reject",
    "tables.setup",
    "tables.calls",
    "memory.new",
    "memory.advance",
    "memory.state",
    "recaptcha.verify",
    "recaptcha.url",
    "clock.wait_window",
    "clock.fresh_second",
    "clock.window_end",
    "clock.between",
    "compare.greater",
    "redis.set",
    "redis.get",
    "redis.ttl",
    "redis.clear",
    "cluster.set",
    "cluster.clear",
];

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("key", "Derive a limit for a request: two parameters into the key pattern, then the key"),
    ("time_limit_redis", "Check a fixed-window limit in Redis: the hit script"),
    ("sliding_window_redis", "Check a sliding-window limit in Redis: the estimate script on two buckets"),
    ("token_bucket_redis", "Check a token bucket in Redis: the refill-and-take script"),
    ("time_limit_pool", "Check a fixed-window limit through a pool of Redis connections"),
];

pub async fn bench(name: &str, session: &mut Session) -> Result<crate::bench::Bench, Fault> {
    use std::hint::black_box;
    use std::rc::Rc;

    use crate::bench::Bench;

    let key = format!("{}:bench:{{ip}}", session.ns);
    Ok(match name {
        "key" => {
            let limit = TimeLimit::new("url:{url},ip:{ip}", 10, 60, time_limit::None).map_err(fault)?;
            Bench::sync(move || {
                let derived = limit.with_params([("{url}", "/v1/account/sessions/email"), ("{ip}", "203.0.113.7")]);
                black_box(derived.key().len());
            })
        }
        "time_limit_redis" => {
            let conn = connection(session, "abuse-redis").await?;
            let limit = Rc::new(
                TimeLimit::new(key, i64::MAX, 3600, time_limit::Redis::new(conn))
                    .map_err(fault)?
                    .with_param("{ip}", "203.0.113.7"),
            );
            Bench::future(move || {
                let limit = limit.clone();
                async move {
                    black_box(limit.check().await.ok());
                }
            })
        }
        "sliding_window_redis" => {
            let conn = connection(session, "abuse-redis").await?;
            let limit = Rc::new(
                SlidingWindow::new(key, i64::MAX, 60, sliding_window::Redis::new(conn, 120))
                    .map_err(fault)?
                    .with_param("{ip}", "203.0.113.7"),
            );
            Bench::future(move || {
                let limit = limit.clone();
                async move {
                    black_box(limit.check().await.ok());
                }
            })
        }
        "token_bucket_redis" => {
            let conn = connection(session, "abuse-redis").await?;
            let limit = Rc::new(
                TokenBucket::new(key, 1_000_000, 1000.0, token_bucket::Redis::new(conn))
                    .map_err(fault)?
                    .with_param("{ip}", "203.0.113.7"),
            );
            Bench::future(move || {
                let limit = limit.clone();
                async move {
                    black_box(limit.check().await.ok());
                }
            })
        }
        "time_limit_pool" => {
            let pool = pool(session, "abuse-redis", false)?;
            let limit = Rc::new(
                TimeLimit::new(key, i64::MAX, 3600, time_limit::RedisPool::new(pool))
                    .map_err(fault)?
                    .with_param("{ip}", "203.0.113.7"),
            );
            Bench::future(move || {
                let limit = limit.clone();
                async move {
                    black_box(limit.check().await.ok());
                }
            })
        }
        _ => return Err(Fault::new(format!("abuse: no benchmark `{name}`"))),
    })
}

fn fault(e: Error) -> Fault {
    Fault::new(e.to_string())
}

fn err(e: Error) -> Outcome {
    Outcome::err(e.php_class(), e.to_string())
}

async fn connection(session: &Session, service: &str) -> Result<ConnectionManager, Fault> {
    let url = session.service(service)?.to_owned();
    redis::Client::open(url.as_str())
        .map_err(|e| Fault::new(e.to_string()))?
        .get_connection_manager()
        .await
        .map_err(|e| Fault::new(e.to_string()))
}

fn seeds(session: &Session) -> Result<Vec<String>, Fault> {
    let url = session.service("abuse-cluster")?;
    let list = url.strip_prefix("redis-cluster://").unwrap_or(url);
    Ok(list.split(',').map(|s| format!("redis://{s}")).collect())
}

async fn cluster(session: &Session) -> Result<ClusterConnection, Fault> {
    redis::cluster::ClusterClient::new(seeds(session)?)
        .map_err(|e| Fault::new(e.to_string()))?
        .get_async_connection()
        .await
        .map_err(|e| Fault::new(e.to_string()))
}

/// A pool of two connections to `service`, or to the cluster.
fn pool(session: &Session, service: &str, to_cluster: bool) -> Result<Stack, Fault> {
    if to_cluster {
        let client = redis::cluster::ClusterClient::new(seeds(session)?).map_err(|e| Fault::new(e.to_string()))?;
        return Ok(Stack::new(2, move || {
            let client = client.clone();
            async move { Ok(Connection::Cluster(client.get_async_connection().await.map_err(Error::Cluster)?)) }
        }));
    }
    let client = redis::Client::open(session.service(service)?).map_err(|e| Fault::new(e.to_string()))?;
    Ok(Stack::new(2, move || {
        let client = client.clone();
        async move { Ok(Connection::Redis(client.get_connection_manager().await?)) }
    }))
}

/// The PHP test double `Utopia\Abuse\Tests\TimeLimit\Store`: a clock and
/// counts shared by the `Memory` limits that use it.
#[derive(Default)]
struct MemoryState {
    now: i64,
    counts: Vec<(String, i64)>,
    calls: i64,
}

/// The PHP test double `Utopia\Abuse\Tests\TimeLimit\Memory`.
#[derive(Clone)]
struct MemoryStore(Arc<Mutex<MemoryState>>);

impl MemoryStore {
    fn state(&self) -> std::sync::MutexGuard<'_, MemoryState> {
        self.0.lock().unwrap_or_else(|e| e.into_inner())
    }
}

impl MemoryState {
    fn get(&self, key: &str) -> i64 {
        self.counts.iter().find(|(k, _)| k == key).map_or(0, |(_, v)| *v)
    }

    fn put(&mut self, key: String, value: i64) {
        match self.counts.iter_mut().find(|(k, _)| *k == key) {
            Some(slot) => slot.1 = value,
            None => self.counts.push((key, value)),
        }
    }
}

impl time_limit::Store for MemoryStore {
    type Log = (String, i64);

    async fn hit(&self, key: &str, window: i64, rule: time_limit::Rule) -> utopia_abuse::Result<i64> {
        let mut s = self.state();
        s.calls += 1;
        let k = format!("{key}__{window}");
        let used = s.get(&k);
        if used < rule.limit {
            s.put(k, used + 1);
        }
        Ok(used)
    }

    async fn count(&self, key: &str, window: i64) -> utopia_abuse::Result<i64> {
        let mut s = self.state();
        s.calls += 1;
        Ok(s.get(&format!("{key}__{window}")))
    }

    async fn set(&self, key: &str, window: i64, value: i64, _rule: time_limit::Rule) -> utopia_abuse::Result<()> {
        let mut s = self.state();
        s.calls += 1;
        s.put(format!("{key}__{window}"), value);
        Ok(())
    }

    async fn logs(&self, offset: Option<i64>, limit: Option<i64>) -> utopia_abuse::Result<Vec<(String, i64)>> {
        let counts = self.state().counts.clone();
        Ok(php_slice(counts, offset.unwrap_or(0), limit))
    }

    async fn cleanup(&self, _timestamp: i64) -> utopia_abuse::Result<bool> {
        Ok(true)
    }
}

/// `array_slice`, for the test double.
fn php_slice<T>(list: Vec<T>, offset: i64, length: Option<i64>) -> Vec<T> {
    let n = list.len() as i64;
    let start = if offset < 0 { (n + offset).max(0) } else { offset.min(n) };
    let end = match length {
        None => n,
        Some(l) if l < 0 => (n + l).max(start),
        Some(l) => start.saturating_add(l).min(n),
    };
    list.into_iter().skip(start as usize).take((end - start).max(0) as usize).collect()
}

/// The PHP test double `Utopia\Abuse\Tests\TokenBucket\Fixed`: a bucket that
/// always holds `available` tokens.
#[derive(Clone)]
struct FixedStore {
    available: f64,
}

impl token_bucket::Store for FixedStore {
    type Log = std::convert::Infallible;

    async fn hit(&self, _key: &str, _now: f64, _rule: token_bucket::Rule) -> utopia_abuse::Result<f64> {
        Ok(self.available)
    }

    async fn count(&self, _key: &str, _now: f64, _rule: token_bucket::Rule) -> utopia_abuse::Result<f64> {
        Ok(self.available)
    }

    async fn clear(&self, _key: &str) -> utopia_abuse::Result<()> {
        Ok(())
    }

    async fn logs(&self, _offset: Option<i64>, _limit: Option<i64>) -> utopia_abuse::Result<Vec<Self::Log>> {
        Ok(Vec::new())
    }

    async fn cleanup(&self, _timestamp: i64) -> utopia_abuse::Result<bool> {
        Ok(true)
    }
}

/// A fake Appwrite TablesDB API in memory: the same fake as `FakeTables` in
/// tests/compat/abuse/Adapter.php. Every column and index is available at
/// once; row ids are sequential.
#[derive(Default)]
struct FakeState {
    calls: Vec<String>,
    databases: Vec<String>,
    /// `db/table` with its column and index keys.
    tables: Vec<(String, Vec<String>, Vec<String>)>,
    rows: Vec<time_limit::Row>,
    next: u64,
    /// The status `create` (the database) answers with, `0` to accept.
    reject_create: u16,
    /// The status `createTable` answers with, `0` to accept.
    reject_table: u16,
}

#[derive(Clone, Default)]
struct FakeTables(Arc<Mutex<FakeState>>);

fn appwrite(message: &str, code: u16, kind: &str) -> Error {
    Error::Appwrite { message: message.into(), code, kind: kind.into() }
}

impl FakeTables {
    fn new(state: &str) -> Self {
        let mut s = FakeState::default();
        if state == "bare" || state == "complete" {
            s.databases.push("db".into());
            s.tables.push(("db/Abuse".into(), Vec::new(), Vec::new()));
        }
        if state == "complete" {
            s.tables[0].1 = vec!["key".into(), "time".into(), "count".into()];
            s.tables[0].2 = vec!["unique1".into(), "index2".into()];
            s.tables.push(("db/lock".into(), Vec::new(), Vec::new()));
        }
        FakeTables(Arc::new(Mutex::new(s)))
    }

    fn state(&self) -> std::sync::MutexGuard<'_, FakeState> {
        self.0.lock().unwrap_or_else(|e| e.into_inner())
    }

    fn call(&self, name: &str) -> std::sync::MutexGuard<'_, FakeState> {
        let mut s = self.state();
        s.calls.push(name.into());
        s
    }

    /// The rejection of the PHP tests' fixture endpoint: `status`, typed
    /// `database_already_exists` when it is 409.
    fn rejected(status: u16) -> Error {
        appwrite("setup rejected", status, if status == 409 { "database_already_exists" } else { "fixture_rejected" })
    }

    fn schema(&self, table: &str) -> Vec<String> {
        let s = self.state();
        s.tables
            .iter()
            .find(|t| t.0 == format!("db/{table}"))
            .map(|t| t.1.iter().chain(&t.2).cloned().collect())
            .unwrap_or_default()
    }
}

impl time_limit::Tables for FakeTables {
    async fn get_table(&self, database: &str, table: &str) -> utopia_abuse::Result<()> {
        let s = self.call("getTable");
        let id = format!("{database}/{table}");
        if s.tables.iter().any(|t| t.0 == id) {
            Ok(())
        } else {
            Err(appwrite("Table not found", 404, "table_not_found"))
        }
    }

    async fn create_database(&self, database: &str, _name: &str) -> utopia_abuse::Result<()> {
        let mut s = self.call("create");
        if s.reject_create != 0 {
            return Err(Self::rejected(s.reject_create));
        }
        if s.databases.iter().any(|d| d == database) {
            return Err(appwrite("Database already exists", 409, "database_already_exists"));
        }
        s.databases.push(database.into());
        Ok(())
    }

    async fn create_table(
        &self,
        database: &str,
        table: &str,
        _name: &str,
        columns: &[time_limit::Column],
        indexes: &[time_limit::Index],
    ) -> utopia_abuse::Result<()> {
        let mut s = self.call("createTable");
        if s.reject_table != 0 {
            return Err(Self::rejected(s.reject_table));
        }
        let id = format!("{database}/{table}");
        if s.tables.iter().any(|t| t.0 == id) {
            return Err(appwrite("Table already exists", 409, "table_already_exists"));
        }
        let columns = columns.iter().map(|c| c.key.to_owned()).collect();
        let indexes = indexes.iter().map(|i| i.key.to_owned()).collect();
        s.tables.push((id, columns, indexes));
        Ok(())
    }

    async fn create_column(
        &self,
        database: &str,
        table: &str,
        column: &time_limit::Column,
    ) -> utopia_abuse::Result<()> {
        let mut kind = column.kind.to_owned();
        if let Some(first) = kind.get_mut(0..1) {
            first.make_ascii_uppercase();
        }
        let mut s = self.call(&format!("create{kind}Column"));
        let id = format!("{database}/{table}");
        let t = s
            .tables
            .iter_mut()
            .find(|t| t.0 == id)
            .ok_or_else(|| appwrite("Table not found", 404, "table_not_found"))?;
        if t.1.iter().any(|c| c == column.key) {
            return Err(appwrite("Column already exists", 409, "column_already_exists"));
        }
        t.1.push(column.key.into());
        Ok(())
    }

    async fn create_index(&self, database: &str, table: &str, index: &time_limit::Index) -> utopia_abuse::Result<()> {
        let mut s = self.call("createIndex");
        let id = format!("{database}/{table}");
        let t = s
            .tables
            .iter_mut()
            .find(|t| t.0 == id)
            .ok_or_else(|| appwrite("Table not found", 404, "table_not_found"))?;
        if t.2.iter().any(|i| i == index.key) {
            return Err(appwrite("Index already exists", 409, "index_already_exists"));
        }
        t.2.push(index.key.into());
        Ok(())
    }

    async fn pending(&self, _database: &str, _table: &str, indexes: bool) -> utopia_abuse::Result<usize> {
        let _calls = self.call(if indexes { "listIndexes" } else { "listColumns" });
        Ok(0)
    }

    async fn find_row(&self, _database: &str, key: &str, time: &str) -> utopia_abuse::Result<Option<time_limit::Row>> {
        let s = self.call("listRows");
        Ok(s.rows.iter().find(|r| r.key == key && r.time == time).cloned())
    }

    async fn create_row(&self, _database: &str, key: &str, time: &str, count: i64) -> utopia_abuse::Result<()> {
        let mut s = self.call("createRow");
        if s.rows.iter().any(|r| r.key == key && r.time == time) {
            return Err(appwrite("Row already exists", 409, "row_already_exists"));
        }
        s.next += 1;
        let id = s.next.to_string();
        s.rows.push(time_limit::Row { id, key: key.into(), time: time.into(), count });
        Ok(())
    }

    async fn increment(&self, _database: &str, id: &str) -> utopia_abuse::Result<()> {
        let mut s = self.call("incrementRowColumn");
        let row =
            s.rows.iter_mut().find(|r| r.id == id).ok_or_else(|| appwrite("Row not found", 404, "row_not_found"))?;
        row.count += 1;
        Ok(())
    }

    async fn update_row(&self, _database: &str, id: &str, count: i64) -> utopia_abuse::Result<()> {
        let mut s = self.call("updateRow");
        let row =
            s.rows.iter_mut().find(|r| r.id == id).ok_or_else(|| appwrite("Row not found", 404, "row_not_found"))?;
        row.count = count;
        Ok(())
    }

    async fn list_rows(
        &self,
        _database: &str,
        offset: Option<i64>,
        limit: Option<i64>,
    ) -> utopia_abuse::Result<Vec<time_limit::Row>> {
        let s = self.call("listRows");
        let rows: Vec<time_limit::Row> = s.rows.iter().rev().cloned().collect();
        Ok(php_slice(rows, offset.unwrap_or(0), Some(limit.unwrap_or(25))))
    }

    async fn delete_rows(&self, _database: &str, time: &str) -> utopia_abuse::Result<i64> {
        let mut s = self.call("deleteRows");
        let doomed: Vec<String> =
            s.rows.iter().filter(|r| r.time.as_str() < time).take(25).map(|r| r.id.clone()).collect();
        s.rows.retain(|r| !doomed.contains(&r.id));
        Ok(doomed.len() as i64)
    }
}

type Db = Arc<time_limit::Memory>;

/// Every limit a case can build.
#[derive(Clone)]
enum AnyLimit {
    TlNone(TimeLimit<time_limit::None>),
    TlRedis(TimeLimit<time_limit::Redis>),
    TlCluster(TimeLimit<time_limit::RedisCluster>),
    TlPool(TimeLimit<time_limit::RedisPool<Stack>>),
    TlDatabase(TimeLimit<time_limit::Database<Db>>),
    TlMemory(TimeLimit<MemoryStore>),
    TlTables(TimeLimit<time_limit::TablesDb<FakeTables>>),
    SwNone(SlidingWindow<sliding_window::None>),
    SwRedis(SlidingWindow<sliding_window::Redis>),
    SwCluster(SlidingWindow<sliding_window::RedisCluster>),
    SwPool(SlidingWindow<sliding_window::RedisPool<Stack>>),
    TbNone(TokenBucket<token_bucket::None>),
    TbRedis(TokenBucket<token_bucket::Redis>),
    TbCluster(TokenBucket<token_bucket::RedisCluster>),
    TbPool(TokenBucket<token_bucket::RedisPool<Stack>>),
    TbFixed(TokenBucket<FixedStore>, f64),
}

/// Runs `$body` with `$l` bound to the limit, whatever its type.
macro_rules! each {
    ($limit:expr, $l:ident => $body:expr) => {
        match $limit {
            AnyLimit::TlNone($l) => $body,
            AnyLimit::TlRedis($l) => $body,
            AnyLimit::TlCluster($l) => $body,
            AnyLimit::TlPool($l) => $body,
            AnyLimit::TlDatabase($l) => $body,
            AnyLimit::TlMemory($l) => $body,
            AnyLimit::TlTables($l) => $body,
            AnyLimit::SwNone($l) => $body,
            AnyLimit::SwRedis($l) => $body,
            AnyLimit::SwCluster($l) => $body,
            AnyLimit::SwPool($l) => $body,
            AnyLimit::TbNone($l) => $body,
            AnyLimit::TbRedis($l) => $body,
            AnyLimit::TbCluster($l) => $body,
            AnyLimit::TbPool($l) => $body,
            AnyLimit::TbFixed($l, _) => $body,
        }
    };
}

/// Rebuilds the same variant around `$l`'s result.
macro_rules! map {
    ($limit:expr, $l:ident => $body:expr) => {
        match $limit {
            AnyLimit::TlNone($l) => AnyLimit::TlNone($body),
            AnyLimit::TlRedis($l) => AnyLimit::TlRedis($body),
            AnyLimit::TlCluster($l) => AnyLimit::TlCluster($body),
            AnyLimit::TlPool($l) => AnyLimit::TlPool($body),
            AnyLimit::TlDatabase($l) => AnyLimit::TlDatabase($body),
            AnyLimit::TlMemory($l) => AnyLimit::TlMemory($body),
            AnyLimit::TlTables($l) => AnyLimit::TlTables($body),
            AnyLimit::SwNone($l) => AnyLimit::SwNone($body),
            AnyLimit::SwRedis($l) => AnyLimit::SwRedis($body),
            AnyLimit::SwCluster($l) => AnyLimit::SwCluster($body),
            AnyLimit::SwPool($l) => AnyLimit::SwPool($body),
            AnyLimit::TbNone($l) => AnyLimit::TbNone($body),
            AnyLimit::TbRedis($l) => AnyLimit::TbRedis($body),
            AnyLimit::TbCluster($l) => AnyLimit::TbCluster($body),
            AnyLimit::TbPool($l) => AnyLimit::TbPool($body),
            AnyLimit::TbFixed($l, time) => AnyLimit::TbFixed($body, *time),
        }
    };
}

fn verdict(v: Verdict) -> Value {
    json!({ "limited": v.limited, "limit": v.limit, "remaining": v.remaining, "reset": v.reset })
}

/// The time a limit is asked at: `now` when given (only limits on a
/// fixed clock take one), the test doubles' own clocks, or the real clock.
#[derive(Clone, Copy)]
enum At {
    Real,
    Secs(i64),
    Micros(f64),
}

fn at(limit: &AnyLimit, now: Option<&Value>) -> Result<At, Fault> {
    let clocked = matches!(limit, AnyLimit::TlPool(_) | AnyLimit::SwPool(_) | AnyLimit::TbPool(_));
    if let Some(now) = now {
        if !clocked {
            return Err(Fault::new("this limit reads the real clock"));
        }
        return Ok(match limit {
            AnyLimit::TbPool(_) => At::Micros(crate::adapter::float(now).ok_or_else(|| Fault::new("now"))?),
            _ => At::Secs(now.as_i64().ok_or_else(|| Fault::new("now must be an integer"))?),
        });
    }
    Ok(match limit {
        AnyLimit::TlMemory(l) => At::Secs(l.store().state().now),
        AnyLimit::TbFixed(_, time) => At::Micros(*time),
        _ => At::Real,
    })
}

/// Runs `check`, `peek` or `reset` at `at`.
macro_rules! timed {
    ($limit:expr, $at:expr, $real:ident, $fixed:ident) => {
        match ($limit, $at) {
            (AnyLimit::TlPool(l), At::Secs(t)) => l.$fixed(t).await,
            (AnyLimit::TlMemory(l), At::Secs(t)) => l.$fixed(t).await,
            (AnyLimit::SwPool(l), At::Secs(t)) => l.$fixed(t).await,
            (AnyLimit::TbPool(l), At::Micros(t)) => l.$fixed(t).await,
            (AnyLimit::TbFixed(l, _), At::Micros(t)) => l.$fixed(t).await,
            (limit, _) => each!(limit, l => l.$real().await),
        }
    };
}

fn int(a: &Args, key: &str) -> Result<i64, Fault> {
    a.i64(key)
}

async fn create(a: &Args<'_>, session: &Session, key: String) -> Result<Result<AnyLimit, Error>, Fault> {
    let service = a.opt_str("service")?.unwrap_or("abuse-redis");
    let store = a.str("store")?;
    Ok(match a.str("algo")? {
        "time_limit" => {
            let (limit, seconds) = (int(a, "limit")?, int(a, "seconds")?);
            match store {
                "none" => TimeLimit::new(key, limit, seconds, time_limit::None).map(AnyLimit::TlNone),
                "redis" => {
                    let s = time_limit::Redis::new(connection(session, service).await?);
                    TimeLimit::new(key, limit, seconds, s).map(AnyLimit::TlRedis)
                }
                "cluster" => {
                    let s = time_limit::RedisCluster::new(cluster(session).await?);
                    TimeLimit::new(key, limit, seconds, s).map(AnyLimit::TlCluster)
                }
                "pool" | "pool-cluster" => {
                    let s = time_limit::RedisPool::new(pool(session, service, store == "pool-cluster")?);
                    TimeLimit::new(key, limit, seconds, s).map(AnyLimit::TlPool)
                }
                "database" => {
                    let db: Db = session.get::<Db>(a.value("database")?)?.clone();
                    TimeLimit::new(key, limit, seconds, time_limit::Database::new(db)).map(AnyLimit::TlDatabase)
                }
                "tables" => {
                    let fake: FakeTables = session.get::<FakeTables>(a.value("tables")?)?.clone();
                    TimeLimit::new(key, limit, seconds, time_limit::TablesDb::new(fake, "db")).map(AnyLimit::TlTables)
                }
                "memory" => {
                    let s: MemoryStore = session.get::<MemoryStore>(a.value("memory")?)?.clone();
                    TimeLimit::new(key, limit, seconds, s).map(AnyLimit::TlMemory)
                }
                other => return Err(Fault::new(format!("unknown store {other}"))),
            }
        }
        "sliding_window" => {
            let (limit, window) = (int(a, "limit")?, int(a, "window")?);
            let ttl = || a.i64("ttl");
            match store {
                "none" => SlidingWindow::new(key, limit, window, sliding_window::None).map(AnyLimit::SwNone),
                "redis" => {
                    let s = sliding_window::Redis::new(connection(session, service).await?, ttl()?);
                    SlidingWindow::new(key, limit, window, s).map(AnyLimit::SwRedis)
                }
                "cluster" => {
                    let s = sliding_window::RedisCluster::new(cluster(session).await?, ttl()?);
                    SlidingWindow::new(key, limit, window, s).map(AnyLimit::SwCluster)
                }
                "pool" | "pool-cluster" => {
                    let s = sliding_window::RedisPool::new(pool(session, service, store == "pool-cluster")?, ttl()?);
                    SlidingWindow::new(key, limit, window, s).map(AnyLimit::SwPool)
                }
                other => return Err(Fault::new(format!("unknown store {other}"))),
            }
        }
        "token_bucket" => {
            let (tokens, rate) = (int(a, "tokens")?, a.f64("refill_rate")?);
            match store {
                "none" => TokenBucket::new(key, tokens, rate, token_bucket::None).map(AnyLimit::TbNone),
                "redis" => {
                    let s = token_bucket::Redis::new(connection(session, service).await?);
                    TokenBucket::new(key, tokens, rate, s).map(AnyLimit::TbRedis)
                }
                "cluster" => {
                    let s = token_bucket::RedisCluster::new(cluster(session).await?);
                    TokenBucket::new(key, tokens, rate, s).map(AnyLimit::TbCluster)
                }
                "pool" | "pool-cluster" => {
                    let s = token_bucket::RedisPool::new(pool(session, service, store == "pool-cluster")?);
                    TokenBucket::new(key, tokens, rate, s).map(AnyLimit::TbPool)
                }
                "fixed" => {
                    let s = FixedStore { available: a.f64("available")? };
                    // The double's key is always "fixed".
                    TokenBucket::new("fixed", tokens, rate, s)
                        .map(|l| AnyLimit::TbFixed(l, a.f64("time").unwrap_or(0.0)))
                }
                other => return Err(Fault::new(format!("unknown store {other}"))),
            }
        }
        other => return Err(Fault::new(format!("unknown algorithm {other}"))),
    })
}

/// A PHP array as JSON: `[]` when empty, an object otherwise.
fn assoc(entries: Vec<(String, Value)>) -> Value {
    if entries.is_empty() {
        return Value::Array(Vec::new());
    }
    Value::Object(entries.into_iter().collect::<Map<String, Value>>())
}

fn counters(logs: Vec<time_limit::Counter>) -> Value {
    assoc(logs.into_iter().map(|c| (c.key, c.value.map_or(Value::Bool(false), |v| bytes_value(&v)))).collect())
}

fn buckets(logs: Vec<token_bucket::Bucket>) -> Value {
    assoc(
        logs.into_iter()
            .map(|b| (b.key, assoc(b.fields.into_iter().map(|(f, v)| (f, bytes_value(&v))).collect())))
            .collect(),
    )
}

async fn logs(limit: &AnyLimit, offset: Option<i64>, count: Option<i64>) -> Result<Value, Error> {
    Ok(match limit {
        AnyLimit::TlNone(_) | AnyLimit::SwNone(_) | AnyLimit::TbNone(_) | AnyLimit::TbFixed(..) => json!([]),
        AnyLimit::TlRedis(l) => counters(l.logs(offset, count).await?),
        AnyLimit::TlCluster(l) => counters(l.logs(offset, count).await?),
        AnyLimit::TlPool(l) => counters(l.logs(offset, count).await?),
        AnyLimit::SwRedis(l) => counters(l.logs(offset, count).await?),
        AnyLimit::SwCluster(l) => counters(l.logs(offset, count).await?),
        AnyLimit::SwPool(l) => counters(l.logs(offset, count).await?),
        AnyLimit::TbRedis(l) => buckets(l.logs(offset, count).await?),
        AnyLimit::TbCluster(l) => buckets(l.logs(offset, count).await?),
        AnyLimit::TbPool(l) => buckets(l.logs(offset, count).await?),
        AnyLimit::TlDatabase(l) => Value::Array(
            l.logs(offset, count).await?.into_iter().map(|e| json!({ "key": e.key, "count": e.count })).collect(),
        ),
        AnyLimit::TlTables(l) => Value::Array(
            l.logs(offset, count).await?.into_iter().map(|e| json!({ "key": e.key, "count": e.count })).collect(),
        ),
        AnyLimit::TlMemory(l) => assoc(l.logs(offset, count).await?.into_iter().map(|(k, v)| (k, json!(v))).collect()),
    })
}

/// A JSON object of parameters, in order.
fn params(value: Option<&Value>) -> Vec<(String, String)> {
    match value {
        Some(Value::Object(o)) => {
            o.iter().map(|(k, v)| (k.clone(), v.as_str().map(str::to_owned).unwrap_or_else(|| v.to_string()))).collect()
        }
        _ => Vec::new(),
    }
}

/// The reCAPTCHA client of the PHP test: answers `body`, keeps the request.
struct Canned {
    body: Vec<u8>,
    requests: Mutex<Vec<Request>>,
}

impl Client for Canned {
    fn send<'a>(&'a self, request: Request) -> BoxFuture<'a, utopia_abuse::Result<Vec<u8>>> {
        self.requests.lock().unwrap_or_else(|e| e.into_inner()).push(request);
        Box::pin(async move { Ok(self.body.clone()) })
    }
}

async fn wait_until(timestamp: i64) {
    while php_std::datetime::time() < timestamp {
        utopia_async::sleep(std::time::Duration::from_millis(1)).await;
    }
}

pub async fn call(op: &str, args: &Value, session: &mut Session) -> OpResult {
    let a = Args(args);
    let limit = |s: &Session| -> Result<AnyLimit, Fault> { s.get::<AnyLimit>(a.value("handle")?).cloned() };
    let result = match op {
        "limit.new" => match create(&a, session, a.str("key")?.to_owned()).await? {
            Ok(l) => Outcome::Ok(session.handle(l)),
            Err(e) => err(e),
        },
        "limit.check" | "limit.peek" | "limit.reset" => {
            let l = limit(session)?;
            let when = at(&l, a.0.get("now"))?;
            let r = match op {
                "limit.check" => timed!(&l, when, check, check_at).map(verdict),
                "limit.peek" => timed!(&l, when, peek, peek_at).map(verdict),
                _ => timed!(&l, when, reset, reset_at).map(|()| Value::Null),
            };
            match r {
                Ok(v) => Outcome::Ok(v),
                Err(e) => err(e),
            }
        }
        "limit.key" => Outcome::ok(each!(&limit(session)?, l => l.key().to_owned())),
        "limit.with_params" => {
            let p = params(a.0.get("params"));
            let derived = map!(&limit(session)?, l => l.with_params(p.iter().cloned()));
            Outcome::Ok(session.handle(derived))
        }
        "limit.with_param" => {
            let (name, value) = (a.str("name")?, a.str("value")?);
            let derived = map!(&limit(session)?, l => l.with_param(name, value));
            Outcome::Ok(session.handle(derived))
        }
        "limit.logs" => {
            let offset = a.opt_i64("offset")?;
            // An absent count is getLogs()'s default, 25; null is no limit.
            let count = match a.0.get("count") {
                None => Some(25),
                Some(v) => v.as_i64(),
            };
            match logs(&limit(session)?, offset, count).await {
                Ok(v) => Outcome::Ok(v),
                Err(e) => err(e),
            }
        }
        "limit.cleanup" => {
            let timestamp = a.i64("timestamp")?;
            match each!(&limit(session)?, l => l.cleanup(timestamp).await) {
                Ok(b) => Outcome::ok(b),
                Err(e) => err(e),
            }
        }
        "limit.run" => {
            let mut fuzz = args.clone();
            if let Some(o) = fuzz.as_object_mut() {
                o.insert("service".into(), json!("abuse-redis-fuzz"));
            }
            let fa = Args(&fuzz);
            let key = format!("{}:{}", session.ns, a.str("key")?);
            let base = match create(&fa, session, key).await? {
                Ok(l) => l,
                Err(e) => return Ok(err(e)),
            };
            let p = params(a.0.get("params"));
            let l = map!(&base, l => l.with_params(p.iter().cloned()));
            let when = at(&l, a.0.get("now"))?;
            if let Err(e) = timed!(&l, when, reset, reset_at) {
                return Ok(err(e));
            }
            let mut verdicts = Vec::new();
            for step in a.array("steps")? {
                let r = match step.as_str() {
                    Some("check") => timed!(&l, when, check, check_at),
                    Some("peek") => timed!(&l, when, peek, peek_at),
                    _ => return Err(Fault::new("unknown step")),
                };
                match r {
                    Ok(v) => verdicts.push(verdict(v)),
                    Err(e) => return Ok(err(e)),
                }
            }
            Outcome::Ok(json!({ "key": each!(&l, x => x.key().to_owned()), "verdicts": verdicts }))
        }
        "database.new" => {
            let db = if a.opt_bool("exists")?.unwrap_or(true) {
                time_limit::Memory::new()
            } else {
                time_limit::Memory::without_database()
            };
            Outcome::Ok(session.handle::<Db>(Arc::new(db)))
        }
        "database.setup" => match limit(session)? {
            AnyLimit::TlDatabase(l) => match l.store().setup().await {
                Ok(()) => Outcome::Ok(Value::Null),
                Err(e) => err(e),
            },
            _ => return Err(Fault::new("not a database limit")),
        },
        "database.attributes" => {
            let documents = time_limit::database::attributes()
                .iter()
                .map(|a| a.to_document().map(|d| encode_array(&d.array_copy())))
                .collect::<Result<Vec<_>, _>>()
                .map_err(|e| Fault::new(e.message()))?;
            Outcome::Ok(Value::Array(documents))
        }
        "database.indexes" => Outcome::Ok(Value::Array(
            time_limit::database::indexes().iter().map(|i| encode_array(&i.to_document().array_copy())).collect(),
        )),
        "database.store" => {
            use time_limit::Documents as _;
            let db = session.get::<Db>(a.value("database")?)?.clone();
            let time = String::from_utf8(php_std::datetime::gmdate(b"Y-m-d H:i:s.v", a.i64("time")?))
                .map_err(|e| Fault::new(e.to_string()))?;
            let entry =
                time_limit::Entry { id: String::new(), key: a.str("key")?.to_owned(), time, count: a.i64("count")? };
            match db.create(&entry).await {
                Ok(()) => Outcome::Ok(Value::Null),
                Err(e) => err(e),
            }
        }
        "database.windows" => match limit(session)? {
            AnyLimit::TlDatabase(l) => {
                let key = a.str("key")?;
                let utc = php_std::datetime::TzInfo::utc();
                let now = php_std::datetime::time();
                let mut windows: Vec<i64> = match l.logs(None, Some(25)).await {
                    Ok(logs) => logs
                        .into_iter()
                        .filter(|e| e.key == key)
                        .filter_map(|e| php_std::datetime::strtotime(e.time.as_bytes(), now, &utc))
                        .collect(),
                    Err(e) => return Ok(err(e)),
                };
                windows.sort_unstable();
                Outcome::Ok(json!(windows))
            }
            _ => return Err(Fault::new("not a database limit")),
        },
        "tables.new" => Outcome::Ok(session.handle(FakeTables::new(a.opt_str("state")?.unwrap_or("none")))),
        "tables.reject" => {
            let fake = session.get::<FakeTables>(a.value("tables")?)?.clone();
            let status = |k: &str| -> Result<u16, Fault> {
                a.opt_i64(k)?.unwrap_or(0).try_into().map_err(|_| Fault::new(format!("bad status {k}")))
            };
            let (create, table) = (status("create")?, status("createTable")?);
            let mut s = fake.state();
            (s.reject_create, s.reject_table) = (create, table);
            Outcome::Ok(Value::Null)
        }
        "tables.setup" => match limit(session)? {
            AnyLimit::TlTables(l) => match l.store().setup().await {
                Ok(()) => Outcome::Ok(Value::Null),
                Err(e) => err(e),
            },
            _ => return Err(Fault::new("not a tables limit")),
        },
        "tables.calls" => {
            let fake = session.get::<FakeTables>(a.value("tables")?)?.clone();
            let calls = std::mem::take(&mut fake.state().calls);
            Outcome::Ok(json!({ "calls": calls, "abuse": fake.schema("Abuse"), "lock": fake.schema("lock") }))
        }
        "memory.new" => {
            let state = MemoryState { now: a.i64("now")?, ..MemoryState::default() };
            Outcome::Ok(session.handle(MemoryStore(Arc::new(Mutex::new(state)))))
        }
        "memory.advance" => {
            let store = session.get::<MemoryStore>(a.value("memory")?)?.clone();
            store.state().now += a.i64("seconds")?;
            Outcome::Ok(Value::Null)
        }
        "memory.state" => {
            let store = session.get::<MemoryStore>(a.value("memory")?)?.clone();
            let s = store.state();
            let counts: Map<String, Value> = s.counts.iter().map(|(k, v)| (k.clone(), json!(v))).collect();
            Outcome::Ok(json!({ "counts": counts, "calls": s.calls }))
        }
        "recaptcha.verify" => {
            let client = Canned { body: a.bytes("body")?, requests: Mutex::new(Vec::new()) };
            let recaptcha = ReCaptcha::with_client(a.str("secret")?, client);
            let score = a.opt_f64("score")?.unwrap_or(0.5);
            let verdict = match recaptcha.verify(a.str("response")?, a.str("ip")?, score).await {
                Ok(v) => v,
                Err(e) => return Ok(err(e)),
            };
            let requests = std::mem::take(&mut *recaptcha.client().requests.lock().unwrap_or_else(|e| e.into_inner()));
            let request = requests.into_iter().next().ok_or_else(|| Fault::new("no request sent"))?;
            Outcome::Ok(json!({
                "verdict": verdict,
                "method": request.method,
                "url": request.url,
                "content_type": request.content_type,
                "body": request.body,
            }))
        }
        "recaptcha.url" => Outcome::ok(ReCaptcha::<Canned>::URL),
        "clock.wait_window" => {
            let seconds = a.i64("seconds")?;
            let now = php_std::datetime::time();
            wait_until(now - now % seconds + seconds).await;
            Outcome::Ok(Value::Null)
        }
        "clock.fresh_second" => {
            wait_until(php_std::datetime::time() + 1).await;
            Outcome::Ok(Value::Null)
        }
        "clock.window_end" => {
            let seconds = a.i64("seconds")?;
            let now = php_std::datetime::time();
            Outcome::ok(now - now % seconds + seconds)
        }
        "clock.between" => {
            let now = php_std::datetime::time();
            let value = a.i64("value")?;
            Outcome::ok(value >= now + a.i64("low")? && value <= now + a.i64("high")?)
        }
        "compare.greater" => Outcome::ok(a.f64("a")? > a.f64("b")?),
        "redis.set" | "redis.get" | "redis.ttl" | "redis.clear" => {
            let mut c = connection(session, a.opt_str("service")?.unwrap_or("abuse-redis")).await?;
            let q = |e: redis::RedisError| Fault::new(e.to_string());
            match op {
                "redis.set" => {
                    let r: redis::RedisResult<String> =
                        redis::cmd("SET").arg(a.str("key")?).arg(a.str("value")?).query_async(&mut c).await;
                    Outcome::ok(r.is_ok())
                }
                "redis.get" => {
                    let v: Option<Vec<u8>> =
                        redis::cmd("GET").arg(a.str("key")?).query_async(&mut c).await.map_err(q)?;
                    Outcome::Ok(v.map_or(Value::Bool(false), |v| bytes_value(&v)))
                }
                "redis.ttl" => {
                    let n: i64 = redis::cmd("TTL").arg(a.str("key")?).query_async(&mut c).await.map_err(q)?;
                    let max = a.i64("max")?;
                    Outcome::ok(n > max - 3 && n <= max)
                }
                _ => {
                    let keys: Vec<String> = redis::cmd("KEYS").arg("abuse__*").query_async(&mut c).await.map_err(q)?;
                    for key in keys {
                        let _: i64 = redis::cmd("DEL").arg(key).query_async(&mut c).await.map_err(q)?;
                    }
                    Outcome::Ok(Value::Null)
                }
            }
        }
        "cluster.set" => {
            let mut c = cluster(session).await?;
            let r: redis::RedisResult<String> =
                redis::cmd("SET").arg(a.str("key")?).arg(a.str("value")?).query_async(&mut c).await;
            Outcome::ok(r.is_ok())
        }
        "cluster.clear" => {
            let mut c = cluster(session).await?;
            let routing = redis::cluster_routing::RoutingInfo::MultiNode((
                redis::cluster_routing::MultipleNodeRoutingInfo::AllMasters,
                None,
            ));
            let keys = c
                .route_command(redis::cmd("KEYS").arg("abuse__*"), routing)
                .await
                .map_err(|e| Fault::new(e.to_string()))?;
            let mut found = Vec::new();
            collect(keys, &mut found);
            for key in found {
                let _: i64 =
                    redis::cmd("DEL").arg(key).query_async(&mut c).await.map_err(|e| Fault::new(e.to_string()))?;
            }
            Outcome::Ok(Value::Null)
        }
        _ => return Err(Fault::new(format!("abuse: unknown operation `{op}`"))),
    };
    Ok(result)
}

/// The keys of a `KEYS` reply from every primary.
fn collect(value: redis::Value, out: &mut Vec<Vec<u8>>) {
    match value {
        redis::Value::BulkString(b) => out.push(b),
        redis::Value::Array(items) | redis::Value::Set(items) => items.into_iter().for_each(|v| collect(v, out)),
        redis::Value::Map(pairs) => pairs.into_iter().for_each(|(_, v)| collect(v, out)),
        _ => {}
    }
}
