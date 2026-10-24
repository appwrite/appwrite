//! Where the scheduler's [`Claim`] lives: in process ([`MemoryStore`]) or
//! shared through Redis ([`RedisStore`]).

use std::future::Future;
use std::sync::{Arc, Mutex};

use php_std::format::str_to_float;
use redis::aio::{ConnectionLike, ConnectionManager};

use crate::Error;
use crate::moment::fixed;

/// The scheduler's shared record: who leads, until when, how far coverage
/// has been committed, and how much of the source had been read when it got
/// there.
///
/// Leadership and coverage share one lifecycle (the leader advances both on
/// every commit), so they share one record, and one compare-and-swap makes
/// the commit fenced: a deposed leader's late write no longer matches the
/// stored token and cannot rewind coverage.
///
/// Every moment is Unix seconds with microsecond precision, the unit
/// [`Clock`](crate::Clock) works in.
///
/// PHP: `Utopia\Schedule\Claim`.
#[derive(Debug, Clone, PartialEq)]
pub struct Claim {
    /// The holder's identity; empty when the claim was released.
    pub token: String,
    /// At or past this moment, any instance may take over.
    pub expires_at: f64,
    /// How far committed coverage reaches; `None` until the first commit.
    pub covered_until: Option<f64>,
    /// When the source was last read successfully before that coverage was
    /// committed: every schedule that existed then was known, so the
    /// coverage accounts for it; anything created later is owed its own.
    pub synced_until: Option<f64>,
}

impl Claim {
    /// A claim held by `token` until `expires_at`.
    ///
    /// PHP: `new Claim($token, $expiresAt, $coveredUntil, $syncedUntil)`.
    pub fn new(
        token: impl Into<String>,
        expires_at: f64,
        covered_until: Option<f64>,
        synced_until: Option<f64>,
    ) -> Self {
        Self { token: token.into(), expires_at, covered_until, synced_until }
    }
}

/// Where the [`Claim`] lives. Back it with shared storage ([`RedisStore`],
/// or a database row) and replicas elect one dispatcher, a replacement
/// resumes coverage where its predecessor stopped, and a deposed leader's
/// late commit is rejected instead of rewinding the watermark.
///
/// Implementations must make [`Store::swap`] atomic: on Redis a Lua script,
/// on a database `UPDATE … WHERE token = ?`.
///
/// PHP: `Utopia\Schedule\Store`.
pub trait Store: Send + Sync {
    /// The current claim, if any.
    ///
    /// PHP: `load()`.
    fn load(&self) -> impl Future<Output = Result<Option<Claim>, Error>> + Send;

    /// Stores `next` only if the current record's token equals `expected`
    /// (`None`: no record exists yet). Returns whether the swap happened.
    ///
    /// PHP: `swap()`.
    fn swap(&self, expected: Option<&str>, next: &Claim) -> impl Future<Output = Result<bool, Error>> + Send;
}

impl<T: Store + ?Sized> Store for Arc<T> {
    /// The shared store's claim.
    fn load(&self) -> impl Future<Output = Result<Option<Claim>, Error>> + Send {
        (**self).load()
    }

    /// Swaps on the shared store.
    fn swap(&self, expected: Option<&str>, next: &Claim) -> impl Future<Output = Result<bool, Error>> + Send {
        (**self).swap(expected, next)
    }
}

/// In-process claim storage. Coverage and leadership survive ticks but not
/// restarts, and only schedulers sharing the store (through an `Arc`)
/// contend; use shared storage in production.
///
/// ```
/// use utopia_schedule::{Claim, MemoryStore, Store};
///
/// # utopia_async::Builder::current_thread().build()?.block_on(async {
/// let store = MemoryStore::new();
/// assert!(store.swap(None, &Claim::new("leader", 100.5, None, None)).await?);
/// assert!(!store.swap(None, &Claim::new("standby", 200.0, None, None)).await?);
/// assert_eq!(store.load().await?.unwrap().token, "leader");
/// # Ok::<(), utopia_schedule::Error>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Schedule\Claim;
/// use Utopia\Schedule\Store\Memory;
///
/// $store = new Memory();
/// var_dump($store->swap(null, new Claim('leader', 100.5, null))); // bool(true)
/// var_dump($store->swap(null, new Claim('standby', 200.0, null))); // bool(false)
/// echo $store->load()->token, "\n"; // leader
/// ```
///
/// PHP: `Utopia\Schedule\Store\Memory`.
#[derive(Debug, Default)]
pub struct MemoryStore {
    claim: Mutex<Option<Claim>>,
}

impl MemoryStore {
    /// An empty store.
    pub fn new() -> Self {
        Self::default()
    }
}

impl Store for MemoryStore {
    /// The claim in memory.
    fn load(&self) -> impl Future<Output = Result<Option<Claim>, Error>> + Send {
        std::future::ready(Ok(self.claim.lock().unwrap_or_else(|e| e.into_inner()).clone()))
    }

    /// Replaces the claim when the held token is `expected`.
    fn swap(&self, expected: Option<&str>, next: &Claim) -> impl Future<Output = Result<bool, Error>> + Send {
        let mut claim = self.claim.lock().unwrap_or_else(|e| e.into_inner());
        let swapped = if claim.as_ref().map(|c| c.token.as_str()) == expected {
            *claim = Some(next.clone());
            true
        } else {
            false
        };
        std::future::ready(Ok(swapped))
    }
}

/// The compare-and-swap, verbatim from PHP: the comparison has to happen
/// inside the script, or two instances could both pass it.
const SWAP: &str = "local held = redis.call('hget', KEYS[1], 'token')

if ARGV[1] == '1' then
    if held then return 0 end
elseif held ~= ARGV[2] then
    return 0
end

redis.call('hset', KEYS[1], 'token', ARGV[3], 'expiresAt', ARGV[4], 'coveredUntil', ARGV[5], 'syncedUntil', ARGV[6])

return 1";

/// Claim storage in Redis: one hash per scheduler, replaced through a Lua
/// compare-and-swap so leadership and coverage move together or not at all.
///
/// The key carries no expiry, deliberately: a claim expires by its own
/// `expiresAt` field, which a successor reads to decide whether it may take
/// over; a TTL would delete the watermark along with the lease. Moments are
/// written as fixed six-decimal text and read back as PHP reads them, so
/// both runtimes share one record.
///
/// The connection is a [`ConnectionManager`] by default, which reconnects
/// on the next command when the connection drops.
///
/// PHP: `Utopia\Schedule\Store\Redis`.
#[derive(Clone)]
pub struct RedisStore<C = ConnectionManager> {
    connection: C,
    key: String,
}

impl<C> RedisStore<C> {
    /// The claim kept in the hash at `key` (pass the full name: it is used
    /// verbatim inside the script).
    ///
    /// PHP: `new Redis($redis, $key)`; PHP's default key is
    /// `utopia-schedule-claim` ([`RedisStore::DEFAULT_KEY`]).
    pub fn new(connection: C, key: impl Into<String>) -> Self {
        Self { connection, key: key.into() }
    }

    /// The key PHP uses when none is given.
    pub const DEFAULT_KEY: &'static str = "utopia-schedule-claim";

    /// The hash's key.
    pub fn key(&self) -> &str {
        &self.key
    }
}

impl<C> std::fmt::Debug for RedisStore<C> {
    /// Writes the key.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("RedisStore").field("key", &self.key).finish()
    }
}

fn dropped(e: &redis::RedisError) -> bool {
    e.is_io_error() || e.is_connection_dropped()
}

/// Runs `command`, once more when the connection had dropped: the
/// connection reopens on the next command (a Redis restart, a proxy timeout
/// or an idle reaper), as phpredis reconnects, so the store needs no
/// reconnect logic of its own.
async fn query<T: redis::FromRedisValue, C: ConnectionLike>(
    command: &redis::Cmd,
    connection: &mut C,
) -> redis::RedisResult<T> {
    match command.query_async(connection).await {
        Err(e) if dropped(&e) => command.query_async(connection).await,
        result => result,
    }
}

/// `(float)` of a hash field.
fn float(text: &[u8]) -> f64 {
    str_to_float(text)
}

impl<C: ConnectionLike + Clone + Send + Sync + 'static> Store for RedisStore<C> {
    /// `HGETALL`: `None` without a token and an expiry.
    fn load(&self) -> impl Future<Output = Result<Option<Claim>, Error>> + Send {
        let mut connection = self.connection.clone();
        let key = self.key.clone();
        async move {
            let mut command = redis::cmd("HGETALL");
            command.arg(&key);
            let record: Vec<(Vec<u8>, Vec<u8>)> = match query(&command, &mut connection).await {
                Ok(record) => record,
                // phpredis returns false for a key of another type.
                Err(e) if e.kind() == redis::ErrorKind::TypeError || e.code() == Some("WRONGTYPE") => {
                    return Ok(None);
                }
                Err(e) => return Err(e.into()),
            };
            let field = |name: &[u8]| record.iter().rev().find(|(k, _)| k == name).map(|(_, v)| v.as_slice());
            let (Some(token), Some(expires_at)) = (field(b"token"), field(b"expiresAt")) else {
                return Ok(None);
            };
            let moment = |name: &[u8]| field(name).filter(|v| !v.is_empty()).map(float);
            Ok(Some(Claim {
                token: String::from_utf8_lossy(token).into_owned(),
                expires_at: float(expires_at),
                covered_until: moment(b"coveredUntil"),
                synced_until: moment(b"syncedUntil"),
            }))
        }
    }

    /// The Lua compare-and-swap. A script error (a key of another type)
    /// is `false`, as phpredis reports it.
    fn swap(&self, expected: Option<&str>, next: &Claim) -> impl Future<Output = Result<bool, Error>> + Send {
        let mut connection = self.connection.clone();
        let mut command = redis::cmd("EVAL");
        command
            .arg(SWAP)
            .arg(1)
            .arg(&self.key)
            .arg(if expected.is_none() { "1" } else { "0" })
            .arg(expected.unwrap_or(""))
            .arg(&next.token)
            .arg(fixed(next.expires_at))
            .arg(next.covered_until.map(fixed).unwrap_or_default())
            .arg(next.synced_until.map(fixed).unwrap_or_default());
        async move {
            match query::<redis::Value, C>(&command, &mut connection).await {
                Ok(redis::Value::Int(1)) => Ok(true),
                Ok(redis::Value::BulkString(s)) => Ok(s == b"1"),
                Ok(_) => Ok(false),
                Err(e) if dropped(&e) || e.is_connection_refusal() => Err(e.into()),
                Err(_) => Ok(false),
            }
        }
    }
}
