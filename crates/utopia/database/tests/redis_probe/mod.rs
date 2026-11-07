//! A real Redis connection the Redis adapter tests run over, instrumented
//! as the PHP tests' fake clients are: it counts `SMEMBERS` reads, records
//! `HSET` writes, can refuse `MEMORY USAGE`, and can fail or nil a command
//! on a key (a lost connection, or phpredis's `false`).
//!
//! The server is `UTOPIA_DATABASE_REDIS_URL`, by default the compat stack's
//! `redis://127.0.0.1:16479/7`; when it is unreachable a test prints why and
//! passes without running.

#![allow(dead_code)]

use std::collections::BTreeMap;
use std::sync::atomic::{AtomicBool, AtomicUsize, Ordering};
use std::sync::{Arc, Mutex};
use std::time::Duration;

use redis::aio::{ConnectionLike, MultiplexedConnection};
use redis::{Cmd, Pipeline, RedisError, RedisFuture, Value as Reply};
use utopia_database::adapter::redis::Redis;
use utopia_database::value::{ArrayKey, Str, Value};
use utopia_database::{Adapter, Authorization, Collection, Document};

/// What a faulted command does.
#[derive(Clone, Debug)]
pub enum Fault {
    /// The connection is lost: the command fails with this message.
    Lost(&'static str),
    /// The server answers `nil` (phpredis's `false`).
    Nil,
}

/// The instrumentation shared by every clone of a [`Probe`].
#[derive(Default)]
pub struct Recorder {
    /// `SMEMBERS` calls.
    pub members: AtomicUsize,
    /// `HSET key field` writes, in order.
    pub hash_writes: Mutex<Vec<String>>,
    /// Whether `MEMORY USAGE` fails.
    pub no_memory: AtomicBool,
    /// `(command, key, fault)` triples.
    pub faults: Mutex<Vec<(String, String, Fault)>>,
}

/// A connection with a [`Recorder`].
#[derive(Clone)]
pub struct Probe {
    inner: MultiplexedConnection,
    pub recorder: Arc<Recorder>,
}

impl Probe {
    /// Fails `command` (upper case) on `key` with `fault`.
    pub fn fault(&self, command: &str, key: &str, fault: Fault) {
        self.recorder.faults.lock().unwrap().push((command.to_owned(), key.to_owned(), fault));
    }

    /// The `HSET` writes recorded, then forgets them.
    pub fn take_hash_writes(&self) -> Vec<String> {
        std::mem::take(&mut *self.recorder.hash_writes.lock().unwrap())
    }

    /// The `SMEMBERS` reads so far.
    pub fn member_reads(&self) -> usize {
        self.recorder.members.load(Ordering::SeqCst)
    }

    /// Records a command and answers its fault, if any.
    fn observe(&self, cmd: &Cmd) -> Option<Fault> {
        let args: Vec<Vec<u8>> = cmd
            .args_iter()
            .map(|a| match a {
                redis::Arg::Simple(b) => b.to_vec(),
                redis::Arg::Cursor => Vec::new(),
            })
            .collect();
        let name = String::from_utf8_lossy(args.first().map(Vec::as_slice).unwrap_or_default()).to_uppercase();
        let key = String::from_utf8_lossy(args.get(1).map(Vec::as_slice).unwrap_or_default()).into_owned();
        match name.as_str() {
            "SMEMBERS" => {
                self.recorder.members.fetch_add(1, Ordering::SeqCst);
            }
            "HSET" => {
                let field = String::from_utf8_lossy(args.get(2).map(Vec::as_slice).unwrap_or_default()).into_owned();
                self.recorder.hash_writes.lock().unwrap().push(format!("{key} {field}"));
            }
            "MEMORY" if self.recorder.no_memory.load(Ordering::SeqCst) => {
                return Some(Fault::Lost("MEMORY USAGE is not available"));
            }
            _ => {}
        }
        self.recorder
            .faults
            .lock()
            .unwrap()
            .iter()
            .find(|(c, k, _)| *c == name && (k.is_empty() || *k == key))
            .map(|(_, _, f)| f.clone())
    }
}

fn lost(message: &'static str) -> RedisError {
    RedisError::from(std::io::Error::new(std::io::ErrorKind::ConnectionReset, message))
}

impl ConnectionLike for Probe {
    fn req_packed_command<'a>(&'a mut self, cmd: &'a Cmd) -> RedisFuture<'a, Reply> {
        match self.observe(cmd) {
            Some(Fault::Lost(message)) => Box::pin(async move { Err(lost(message)) }),
            Some(Fault::Nil) => Box::pin(async { Ok(Reply::Nil) }),
            None => self.inner.req_packed_command(cmd),
        }
    }

    fn req_packed_commands<'a>(
        &'a mut self,
        pipeline: &'a Pipeline,
        offset: usize,
        count: usize,
    ) -> RedisFuture<'a, Vec<Reply>> {
        let mut failure = None;
        for cmd in pipeline.cmd_iter() {
            if let Some(Fault::Lost(message)) = self.observe(cmd) {
                failure = Some(message);
            }
        }
        match failure {
            Some(message) => Box::pin(async move { Err(lost(message)) }),
            None => self.inner.req_packed_commands(pipeline, offset, count),
        }
    }

    fn get_db(&self) -> i64 {
        self.inner.get_db()
    }
}

/// The server's URL.
pub fn url() -> String {
    std::env::var("UTOPIA_DATABASE_REDIS_URL").unwrap_or_else(|_| "redis://127.0.0.1:16479/7".to_owned())
}

/// A connection to the test server, or `None` (with a message) when it is
/// unreachable.
pub async fn probe() -> Option<Probe> {
    let url = url();
    let connect = async {
        let client = redis::Client::open(url.as_str())?;
        client.get_multiplexed_async_connection().await
    };
    match utopia_async::timeout(Duration::from_secs(3), connect).await {
        Ok(Ok(inner)) => Some(Probe { inner, recorder: Arc::new(Recorder::default()) }),
        Ok(Err(e)) => {
            eprintln!("skipped: Redis at {url} is unreachable ({e})");
            None
        }
        Err(_) => {
            eprintln!("skipped: Redis at {url} did not answer");
            None
        }
    }
}

/// Connects or skips the test.
#[macro_export]
macro_rules! probe_or_skip {
    () => {
        match $crate::redis_probe::probe().await {
            Some(probe) => probe,
            None => return,
        }
    };
}

/// A namespace no other test (or run) uses.
pub fn namespace(name: &str) -> String {
    static NEXT: AtomicUsize = AtomicUsize::new(0);
    let nanos = std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).unwrap().as_nanos();
    let n = NEXT.fetch_add(1, Ordering::SeqCst);
    format!("{name}_{}_{}_{n}", std::process::id(), nanos % 1_000_000_000_000)
}

/// An adapter over `probe` with authorization disabled, on database `db`
/// and namespace `ns`, the database created (the PHP tests' `adapter()`).
pub async fn adapter(probe: &Probe, db: &str, ns: &str) -> Redis<Probe> {
    let authorization = Authorization::new(true);
    authorization.disable();
    let adapter = Redis::new(probe.clone());
    adapter.set_authorization(Arc::new(authorization));
    adapter.set_database(db);
    adapter.set_namespace(ns);
    adapter.create(db).await.expect("the database is created");
    adapter
}

/// `new Document(['$id' => $id])` as a collection.
pub fn collection(id: &str) -> Collection {
    Collection::from_array([(ArrayKey::from("$id"), Value::Str(Str::from(id)))].into_iter().collect()).unwrap()
}

/// A document of JSON (PHP's `new Document($array)`).
pub fn doc(json: serde_json::Value) -> Document {
    match utopia_database::value::from_plain(php_std::Value::from_json(&json)) {
        Value::Array(array) => Document::new(array).expect("a valid document"),
        _ => Document::empty(),
    }
}

/// A string value.
pub fn s(text: &str) -> Value {
    Value::Str(Str::from(text))
}

/// A key's content, for comparing the keyspace before and after.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Content {
    String(Vec<u8>),
    Set(Vec<String>),
    Hash(Vec<(String, String)>),
    Other(String),
}

/// Every key under `prefix` with its content, sorted.
pub async fn snapshot(probe: &Probe, prefix: &str) -> BTreeMap<String, Content> {
    let mut connection = probe.clone();
    let keys: Vec<String> = redis::cmd("KEYS").arg(format!("{prefix}*")).query_async(&mut connection).await.unwrap();
    let mut out = BTreeMap::new();
    for key in keys {
        let kind: String = redis::cmd("TYPE").arg(&key).query_async(&mut connection).await.unwrap();
        let content = match kind.as_str() {
            "string" => Content::String(redis::cmd("GET").arg(&key).query_async(&mut connection).await.unwrap()),
            "set" => {
                let mut members: Vec<String> =
                    redis::cmd("SMEMBERS").arg(&key).query_async(&mut connection).await.unwrap();
                members.sort();
                Content::Set(members)
            }
            "hash" => {
                let mut pairs: Vec<(String, String)> =
                    redis::cmd("HGETALL").arg(&key).query_async(&mut connection).await.unwrap();
                pairs.sort();
                Content::Hash(pairs)
            }
            other => Content::Other(other.to_owned()),
        };
        out.insert(key, content);
    }
    out
}

/// Runs a raw command on the server (setting up stored state).
pub async fn raw<T: redis::FromRedisValue>(probe: &Probe, cmd: &mut Cmd) -> T {
    let mut connection = probe.clone();
    cmd.query_async(&mut connection).await.unwrap()
}
