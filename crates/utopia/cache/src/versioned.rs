//! Opaque versions and purge tombstones, shared by the Redis-protocol
//! adapters ([`crate::Redis`], [`crate::Multiplexing`]).
//!
//! Each key carries a version in a reserved field of its own hash: a random
//! identity replaced by every write and dropped by every purge, so a version
//! is never reused, even after the key is evicted. A reader captures it
//! before reading the source of truth ([`crate::Cache::lease`]) and presents
//! it when saving; the save lands only if nothing wrote or purged the key in
//! between. An optional grace window also refuses saves for a while after a
//! purge (the tombstone). Every script touches one key, so they stay correct
//! on Redis Cluster.
//!
//! PHP: `Utopia\Cache\Adapter\Redis\Versioned`. The scripts are PHP's, byte
//! for byte, so both runtimes share Redis' script cache and each other's keys.

use std::future::Future;

use crate::resp::Reply;
use crate::{Codec, Envelope, Error, Result, Snapshot};

/// Reserved field holding a key's version. Not usable as a cache field.
pub const VERSION_FIELD: &str = "__utopia_version__";

/// Reserved field holding the post-purge deadline (epoch microseconds)
/// before which conditional saves are refused. Not usable as a cache field.
pub const TOMBSTONE_FIELD: &str = "__utopia_tomb__";

/// Drops the whole key (`ARGV[1]` empty) or field `ARGV[1]` and the version,
/// and stamps the tombstone (`ARGV[2]` = grace window, ms). Returns the value
/// fields removed.
pub const LUA_PURGE: &str = "local removed = 0
if ARGV[1] == '' then
    removed = redis.call('HLEN', KEYS[1])
        - redis.call('HEXISTS', KEYS[1], '__utopia_version__')
        - redis.call('HEXISTS', KEYS[1], '__utopia_tomb__')
    redis.call('DEL', KEYS[1])
else
    removed = redis.call('HDEL', KEYS[1], ARGV[1])
    redis.call('HDEL', KEYS[1], '__utopia_version__')
end
local window = tonumber(ARGV[2])
if window > 0 then
    local t = redis.call('TIME')
    redis.call('HSET', KEYS[1], '__utopia_tomb__', tonumber(t[1]) * 1000000 + tonumber(t[2]) + window * 1000)
end
return removed";

/// The key's version, created from `ARGV[1]` when it has none, and the value
/// of field `ARGV[2]` (none when empty).
pub const LUA_VERSION: &str = "local version = redis.call('HGET', KEYS[1], '__utopia_version__')
if version == false then
    version = ARGV[1]
    redis.call('HSET', KEYS[1], '__utopia_version__', version)
end
local value = false
if ARGV[2] ~= '' then value = redis.call('HGET', KEYS[1], ARGV[2]) end
return {version, value}";

/// Writes field `ARGV[2]` = `ARGV[3]` and version `ARGV[4]` only when the
/// version is `ARGV[1]` and the key is past its tombstone (`ARGV[5]` = grace
/// window, ms).
pub const LUA_COMPARE_AND_SWAP: &str =
    "if redis.call('HGET', KEYS[1], '__utopia_version__') ~= ARGV[1] then return 0 end
local deadline = tonumber(redis.call('HGET', KEYS[1], '__utopia_tomb__'))
local window = tonumber(ARGV[5])
if deadline ~= nil and window > 0 then
    local t = redis.call('TIME')
    local now = tonumber(t[1]) * 1000000 + tonumber(t[2])
    if now < deadline and deadline - now <= window * 1000 then return 0 end
    redis.call('HDEL', KEYS[1], '__utopia_tomb__')
end
redis.call('HSET', KEYS[1], ARGV[2], ARGV[3], '__utopia_version__', ARGV[4])
return 1";

/// Whether `field` is one of the reserved fields.
pub fn is_reserved(field: &str) -> bool {
    field == TOMBSTONE_FIELD || field == VERSION_FIELD
}

/// A fresh version: 16 random bytes in hex.
///
/// PHP: `bin2hex(random_bytes(16))`.
pub fn fresh() -> String {
    let mut bytes = [0u8; 16];
    getrandom::fill(&mut bytes).expect("random bytes");
    hex::encode(bytes)
}

/// The SHA-1 digest Redis identifies a script by (memoized for the scripts above).
pub fn script_sha(script: &str) -> std::borrow::Cow<'static, str> {
    use std::sync::OnceLock;
    static SHAS: OnceLock<[String; 3]> = OnceLock::new();
    let scripts = [LUA_PURGE, LUA_VERSION, LUA_COMPARE_AND_SWAP];
    let shas = SHAS.get_or_init(|| scripts.map(sha1_hex));
    match scripts.iter().position(|s| std::ptr::eq(*s, script) || *s == script) {
        Some(i) => std::borrow::Cow::Borrowed(shas[i].as_str()),
        None => std::borrow::Cow::Owned(sha1_hex(script)),
    }
}

fn sha1_hex(script: &str) -> String {
    use sha1::{Digest, Sha1};
    hex::encode(Sha1::digest(script.as_bytes()))
}

/// How a Redis-protocol adapter runs the scripts.
#[cfg_attr(not(feature = "redis"), allow(dead_code))]
pub(crate) trait Transport: Send + Sync {
    /// `EVALSHA sha 1 key args...`; [`Error::NoScript`] when the server has
    /// no script for `sha`. A server error reply is `Ok(Reply::Error)`.
    fn eval_sha(&self, sha: &str, key: &str, args: &[&[u8]]) -> impl Future<Output = Result<Reply>> + Send;
    /// `EVAL script 1 key args...`.
    fn eval(&self, script: &str, key: &str, args: &[&[u8]]) -> impl Future<Output = Result<Reply>> + Send;
}

/// Runs a script by digest, resending the body once on `NOSCRIPT`.
#[cfg_attr(not(feature = "redis"), allow(dead_code))]
pub(crate) async fn run<T: Transport>(transport: &T, script: &str, key: &str, args: &[&[u8]]) -> Result<Reply> {
    match transport.eval_sha(&script_sha(script), key, args).await {
        Err(Error::NoScript(_)) => transport.eval(script, key, args).await,
        other => other,
    }
}

/// The version and the raw value of field `field` (none when empty).
#[cfg_attr(not(feature = "redis"), allow(dead_code))]
async fn snapshot<T: Transport>(
    transport: &T,
    key: &str,
    field: &str,
    failure: &str,
) -> Result<(String, Option<Vec<u8>>)> {
    let fresh = fresh();
    let reply = run(transport, LUA_VERSION, key, &[fresh.as_bytes(), field.as_bytes()]).await?;
    let unavailable = || Error::Unavailable { message: failure.to_owned(), previous: None };
    let Reply::Array(items) = reply else { return Err(unavailable()) };
    let mut items = items.into_iter();
    let version = match items.next() {
        Some(Reply::Str(version)) => String::from_utf8_lossy(&version).into_owned(),
        _ => return Err(unavailable()),
    };
    let value = match items.next() {
        Some(Reply::Str(value)) => Some(value),
        _ => None,
    };
    Ok((version, value))
}

/// PHP: `getVersion`.
#[cfg_attr(not(feature = "redis"), allow(dead_code))]
pub(crate) async fn version<T: Transport>(transport: &T, key: &str) -> Result<String> {
    Ok(snapshot(transport, key, "", "Unable to obtain cache version").await?.0)
}

/// PHP: `loadVersioned`.
#[cfg_attr(not(feature = "redis"), allow(dead_code))]
pub(crate) async fn load_versioned<T: Transport, C: Codec>(
    transport: &T,
    envelope: &Envelope<C>,
    key: &str,
) -> Result<Snapshot> {
    let (version, stored) = snapshot(transport, key, key, "Unable to read cache snapshot").await?;
    let value = stored.and_then(|stored| envelope.decode(&stored, i64::MAX, crate::adapter::now()));
    Ok(Snapshot { version, value })
}

/// PHP: `compareAndSwap`, over a value already encoded (an envelope, or
/// bytes as they are). `Some(version)` committed, `None` on a conflict.
#[cfg_attr(not(feature = "redis"), allow(dead_code))]
pub(crate) async fn compare_and_swap<T: Transport>(
    transport: &T,
    grace_ms: i64,
    key: &str,
    value: &[u8],
    hash: &str,
    version: &str,
) -> Result<Option<String>> {
    let next = fresh();
    let window = grace_ms.to_string();
    let args: [&[u8]; 5] = [version.as_bytes(), hash.as_bytes(), value, next.as_bytes(), window.as_bytes()];
    Ok(run(transport, LUA_COMPARE_AND_SWAP, key, &args).await?.truthy().then_some(next))
}

/// The field a conditional write targets: `hash`, or the key when `hash` is
/// `""`. Unlike a save, `"0"` is a field of its own.
///
/// PHP: `$hash === '' ? $key : $hash`.
#[cfg_attr(not(feature = "redis"), allow(dead_code))]
pub(crate) fn cas_field<'a>(key: &'a str, hash: &'a str) -> &'a str {
    if hash.is_empty() { key } else { hash }
}

/// PHP: `purge`.
#[cfg_attr(not(feature = "redis"), allow(dead_code))]
pub(crate) async fn purge<T: Transport>(transport: &T, grace_ms: i64, key: &str, hash: &str) -> Result<bool> {
    if is_reserved(hash) {
        return Ok(false);
    }
    let window = grace_ms.to_string();
    Ok(run(transport, LUA_PURGE, key, &[hash.as_bytes(), window.as_bytes()]).await?.truthy())
}

#[cfg(test)]
mod tests {
    use super::*;

    /// The digests of PHP's script bodies: a changed byte would miss the
    /// script cache PHP fills and diverge from PHP's behaviour.
    #[test]
    fn scripts_are_php_byte_for_byte() {
        assert_eq!(script_sha(LUA_PURGE), "e1c6e9f3fb92dc5ea642b0c11358961ca0585653");
        assert_eq!(script_sha(LUA_VERSION), "ebdcc36fc2ddd9b5deccb4001a45ccd9147ffe47");
        assert_eq!(script_sha(LUA_COMPARE_AND_SWAP), "03ff979ecad7a9dd6182bc86255115ca108075d3");
    }

    #[test]
    fn versions_are_fresh_hex() {
        let (a, b) = (fresh(), fresh());
        assert_eq!(a.len(), 32);
        assert_ne!(a, b);
    }
}
