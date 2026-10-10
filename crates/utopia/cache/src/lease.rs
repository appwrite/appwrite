//! Generation leases and purge tombstones, shared by the Redis-protocol
//! adapters ([`crate::Redis`], [`crate::Multiplexing`]).
//!
//! Each key carries a generation in a reserved field of its own hash,
//! advanced atomically by every purge. A reader captures it before reading
//! the source of truth and presents it when saving; the save lands only if
//! no purge happened in between. An optional grace window also refuses
//! saves for a while after a purge (the tombstone). Every script touches one
//! key, so they stay correct on Redis Cluster.

use std::future::Future;

use php_std::types::Value;

use crate::resp::Reply;
use crate::{Codec, Envelope, Error, Result};

/// Reserved field holding a key's generation. Not usable as a cache field.
pub const GENERATION_FIELD: &str = "__utopia_gen__";

/// Reserved field holding the post-purge deadline (epoch microseconds)
/// before which leased saves are refused. Not usable as a cache field.
pub const TOMBSTONE_FIELD: &str = "__utopia_tomb__";

/// Saves `ARGV[1] = ARGV[2]` only when the generation equals `ARGV[3]` and
/// the key is past its tombstone (`ARGV[4]` = grace window, ms).
pub const LUA_SAVE_WITH_LEASE: &str = "local current = redis.call('HGET', KEYS[1], '__utopia_gen__')
if current == false then current = '0' end
if current ~= ARGV[3] then return 0 end
local window = tonumber(ARGV[4]) or 0
if window > 0 then
    local tomb = redis.call('HGET', KEYS[1], '__utopia_tomb__')
    if tomb ~= false then
        local deadline = tonumber(tomb)
        if deadline ~= nil then
            local t = redis.call('TIME')
            local now = tonumber(t[1]) * 1000000 + tonumber(t[2])
            -- 2nd clause ignores a deadline left by a since-rewound clock
            if now < deadline and (deadline - now) <= window * 1000 then
                return 0
            end
        end
        redis.call('HDEL', KEYS[1], '__utopia_tomb__')
    end
end
redis.call('HSET', KEYS[1], ARGV[1], ARGV[2])
return 1";

/// Drops every value field, advances the generation and stamps the
/// tombstone (`ARGV[1]` = grace window, ms). Returns the value fields removed.
pub const LUA_PURGE_BUMP: &str = "local gen = '__utopia_gen__'
local tomb = '__utopia_tomb__'
local removed = redis.call('HLEN', KEYS[1]) - redis.call('HEXISTS', KEYS[1], gen) - redis.call('HEXISTS', KEYS[1], tomb)
local current = redis.call('HGET', KEYS[1], gen)
local next = (tonumber(current) or 0) + 1
redis.call('DEL', KEYS[1])
redis.call('HSET', KEYS[1], gen, next)
local window = tonumber(ARGV[1]) or 0
if window > 0 then
    local t = redis.call('TIME')
    local now = tonumber(t[1]) * 1000000 + tonumber(t[2])
    redis.call('HSET', KEYS[1], tomb, now + window * 1000)
end
return removed";

/// Deletes field `ARGV[1]`, advances the generation and stamps the
/// tombstone (`ARGV[2]` = grace window, ms). Returns the fields removed.
pub const LUA_PURGE_FIELD: &str = "local removed = redis.call('HDEL', KEYS[1], ARGV[1])
local current = redis.call('HGET', KEYS[1], '__utopia_gen__')
local next = (tonumber(current) or 0) + 1
redis.call('HSET', KEYS[1], '__utopia_gen__', next)
local window = tonumber(ARGV[2]) or 0
if window > 0 then
    local t = redis.call('TIME')
    local now = tonumber(t[1]) * 1000000 + tonumber(t[2])
    redis.call('HSET', KEYS[1], '__utopia_tomb__', now + window * 1000)
end
return removed";

/// Whether `field` is one of the reserved fields.
pub fn is_reserved(field: &str) -> bool {
    field == GENERATION_FIELD || field == TOMBSTONE_FIELD
}

/// The SHA-1 digest Redis identifies a script by (memoized for the lease scripts).
pub fn script_sha(script: &str) -> std::borrow::Cow<'static, str> {
    use std::sync::OnceLock;
    static SHAS: OnceLock<[String; 3]> = OnceLock::new();
    let scripts = [LUA_SAVE_WITH_LEASE, LUA_PURGE_BUMP, LUA_PURGE_FIELD];
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

/// How a Redis-protocol adapter runs the lease scripts.
#[cfg_attr(not(feature = "redis"), allow(dead_code))]
pub(crate) trait Transport: Send + Sync {
    /// `EVALSHA sha 1 key args...`; [`Error::NoScript`] when the server has
    /// no script for `sha`. A server error reply is `Ok(Reply::Error)`.
    fn eval_sha(&self, sha: &str, key: &str, args: &[&[u8]]) -> impl Future<Output = Result<Reply>> + Send;
    /// `EVAL script 1 key args...`.
    fn eval(&self, script: &str, key: &str, args: &[&[u8]]) -> impl Future<Output = Result<Reply>> + Send;
    /// `HGET key field`.
    fn hget(&self, key: &str, field: &str) -> impl Future<Output = Result<Option<Vec<u8>>>> + Send;
}

/// Runs a script by digest, resending the body once on `NOSCRIPT`.
#[cfg_attr(not(feature = "redis"), allow(dead_code))]
pub(crate) async fn run<T: Transport>(transport: &T, script: &str, key: &str, args: &[&[u8]]) -> Result<Reply> {
    match transport.eval_sha(&script_sha(script), key, args).await {
        Err(Error::NoScript(_)) => transport.eval(script, key, args).await,
        other => other,
    }
}

#[cfg_attr(not(feature = "redis"), allow(dead_code))]
pub(crate) async fn generation<T: Transport>(transport: &T, key: &str) -> Result<String> {
    Ok(match transport.hget(key, GENERATION_FIELD).await? {
        Some(generation) => String::from_utf8_lossy(&generation).into_owned(),
        None => "0".to_owned(),
    })
}

#[cfg_attr(not(feature = "redis"), allow(dead_code))]
pub(crate) async fn save_with_lease<T: Transport, C: Codec>(
    transport: &T,
    envelope: &Envelope<C>,
    grace_ms: i64,
    key: &str,
    data: &Value,
    hash: &str,
    generation: &str,
) -> Result<bool> {
    let data =
        crate::adapter::coerce(data, "Utopia\\Cache\\Adapter\\Redis\\Leasable::saveWithLease(): Argument #2 ($data)")?;
    if crate::adapter::blank(key) || crate::adapter::empty(&data) {
        return Ok(false);
    }
    let hash = crate::adapter::field(key, hash);
    if is_reserved(hash) {
        return Ok(false);
    }
    let Ok(value) = envelope.encode(&data, crate::adapter::now()) else { return Ok(false) };
    let window = grace_ms.to_string();
    let args: [&[u8]; 4] = [hash.as_bytes(), &value, generation.as_bytes(), window.as_bytes()];
    Ok(matches!(run(transport, LUA_SAVE_WITH_LEASE, key, &args).await, Ok(reply) if reply.truthy()))
}

#[cfg_attr(not(feature = "redis"), allow(dead_code))]
pub(crate) async fn purge<T: Transport>(transport: &T, grace_ms: i64, key: &str, hash: &str) -> Result<bool> {
    let window = grace_ms.to_string();
    if !crate::adapter::blank(hash) {
        if is_reserved(hash) {
            return Ok(false);
        }
        let args: [&[u8]; 2] = [hash.as_bytes(), window.as_bytes()];
        return Ok(run(transport, LUA_PURGE_FIELD, key, &args).await?.truthy());
    }
    Ok(run(transport, LUA_PURGE_BUMP, key, &[window.as_bytes()]).await?.truthy())
}
