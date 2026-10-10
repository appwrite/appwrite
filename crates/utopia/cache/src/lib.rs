//! Redis cache that interoperates with `utopia-php/cache`.
//!
//! PHP stores each cached document as a Redis HASH: one field per projection
//! (igbinary payload) plus a `__utopia_gen__` generation counter. Writers bump
//! the generation when purging so concurrent readers cannot re-cache stale data
//! (the "lease" protocol).
//!
//! Rust keeps its own entries in the **same hashes** under Rust-specific field
//! names. That gives cross-runtime coherence for free:
//!
//! * a PHP purge deletes the whole hash, including Rust fields;
//! * a Rust purge runs the very same Lua scripts, invalidating PHP fields;
//! * both sides honour the generation counter when saving.
//!
//! Keys and fields are lower-cased like the PHP `Cache` facade does, and keys
//! are sharded with `crc32(key) % shards` exactly like `Adapter\Sharding`.

use std::time::{SystemTime, UNIX_EPOCH};

use redis::aio::ConnectionManager;
use redis::{AsyncCommands, Script};

pub use redis;

/// Reserved field holding the generation counter.
pub const GENERATION_FIELD: &str = "__utopia_gen__";
/// Reserved field holding the purge tombstone (grace window).
pub const TOMBSTONE_FIELD: &str = "__utopia_tomb__";

const LUA_SAVE_WITH_LEASE: &str = r#"local current = redis.call('HGET', KEYS[1], '__utopia_gen__')
if current == false then current = '0' end
if current ~= ARGV[3] then return 0 end
local window = tonumber(ARGV[4]) or 0
if window > 0 then
    local tomb = redis.call('HGET', KEYS[1], '__utopia_tomb__')
    if tomb then
        local t = redis.call('TIME')
        local now = tonumber(t[1]) * 1000000 + tonumber(t[2])
        if now < tonumber(tomb) then return 0 end
        redis.call('HDEL', KEYS[1], '__utopia_tomb__')
    end
end
redis.call('HSET', KEYS[1], ARGV[1], ARGV[2])
return 1"#;

const LUA_PURGE_BUMP: &str = r#"local gen = '__utopia_gen__'
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
return removed"#;

const LUA_PURGE_FIELD: &str = r#"local removed = redis.call('HDEL', KEYS[1], ARGV[1])
local current = redis.call('HGET', KEYS[1], '__utopia_gen__')
local next = (tonumber(current) or 0) + 1
redis.call('HSET', KEYS[1], '__utopia_gen__', next)
local window = tonumber(ARGV[2]) or 0
if window > 0 then
    local t = redis.call('TIME')
    local now = tonumber(t[1]) * 1000000 + tonumber(t[2])
    redis.call('HSET', KEYS[1], '__utopia_tomb__', now + window * 1000)
end
return removed"#;

/// Cache operations return Redis errors; callers treat them as misses.
pub type Result<T> = redis::RedisResult<T>;

struct Scripts {
    save_with_lease: Script,
    purge_bump: Script,
    purge_field: Script,
}

/// A sharded Redis hash cache.
pub struct Cache {
    shards: Vec<ConnectionManager>,
    scripts: Scripts,
    /// Grace window in milliseconds (Appwrite uses 0).
    grace_window_ms: u64,
}

/// A loaded entry together with the generation observed at read time.
pub struct Lookup {
    pub value: Option<Vec<u8>>,
    pub generation: String,
}

impl Cache {
    pub fn new(shards: Vec<ConnectionManager>) -> Self {
        assert!(!shards.is_empty(), "cache requires at least one shard");
        Self {
            shards,
            scripts: Scripts {
                save_with_lease: Script::new(LUA_SAVE_WITH_LEASE),
                purge_bump: Script::new(LUA_PURGE_BUMP),
                purge_field: Script::new(LUA_PURGE_FIELD),
            },
            grace_window_ms: 0,
        }
    }

    fn shard(&self, key: &str) -> ConnectionManager {
        let index =
            if self.shards.len() == 1 { 0 } else { (crc32fast::hash(key.as_bytes()) as usize) % self.shards.len() };
        self.shards[index].clone()
    }

    /// Reads `field` of `key` and the current generation in one round trip.
    /// Entries older than `ttl_secs` are reported as misses.
    pub async fn lookup(&self, key: &str, field: &str, ttl_secs: u64) -> Result<Lookup> {
        let key = key.to_lowercase();
        let field = field.to_lowercase();
        let mut conn = self.shard(&key);
        let (value, generation): (Option<Vec<u8>>, Option<String>) =
            redis::cmd("HMGET").arg(&key).arg(&field).arg(GENERATION_FIELD).query_async(&mut conn).await?;
        let value = value.and_then(|raw| unwrap_envelope(raw, ttl_secs));
        Ok(Lookup { value, generation: generation.unwrap_or_else(|| "0".to_owned()) })
    }

    /// Stores `value` only if the generation is still `generation`.
    pub async fn save_with_lease(&self, key: &str, field: &str, value: &[u8], generation: &str) -> Result<bool> {
        let key = key.to_lowercase();
        let field = field.to_lowercase();
        let mut conn = self.shard(&key);
        let stored: i64 = self
            .scripts
            .save_with_lease
            .key(&key)
            .arg(&field)
            .arg(wrap_envelope(value))
            .arg(generation)
            .arg(self.grace_window_ms.to_string())
            .invoke_async(&mut conn)
            .await?;
        Ok(stored == 1)
    }

    /// Unconditionally stores a field (used for index hashes).
    pub async fn save(&self, key: &str, field: &str, value: &[u8]) -> Result<()> {
        let key = key.to_lowercase();
        let field = field.to_lowercase();
        let mut conn = self.shard(&key);
        let _: i64 = conn.hset(&key, &field, wrap_envelope(value)).await?;
        Ok(())
    }

    /// Deletes a whole hash and bumps its generation (`LUA_PURGE_BUMP`).
    pub async fn purge(&self, key: &str) -> Result<()> {
        let key = key.to_lowercase();
        let mut conn = self.shard(&key);
        let _: i64 =
            self.scripts.purge_bump.key(&key).arg(self.grace_window_ms.to_string()).invoke_async(&mut conn).await?;
        Ok(())
    }

    /// Deletes one field and bumps the generation (`LUA_PURGE_FIELD`).
    pub async fn purge_field(&self, key: &str, field: &str) -> Result<()> {
        let key = key.to_lowercase();
        let field = field.to_lowercase();
        let mut conn = self.shard(&key);
        let _: i64 = self
            .scripts
            .purge_field
            .key(&key)
            .arg(&field)
            .arg(self.grace_window_ms.to_string())
            .invoke_async(&mut conn)
            .await?;
        Ok(())
    }

    /// Purges a document entry: removes it from the collection index
    /// (`LUA_PURGE_FIELD`) and deletes the document hash (`LUA_PURGE_BUMP`).
    /// Both scripts are pipelined when the keys live on the same shard.
    pub async fn purge_document(&self, collection_key: &str, document_key: &str) -> Result<()> {
        let collection_key = collection_key.to_lowercase();
        let document_key = document_key.to_lowercase();
        let window = self.grace_window_ms.to_string();
        let same_shard = self.shards.len() == 1
            || crc32fast::hash(collection_key.as_bytes()) as usize % self.shards.len()
                == crc32fast::hash(document_key.as_bytes()) as usize % self.shards.len();
        if same_shard {
            let mut conn = self.shard(&document_key);
            let mut pipe = redis::pipe();
            pipe.cmd("EVAL")
                .arg(LUA_PURGE_FIELD)
                .arg(1)
                .arg(&collection_key)
                .arg(&document_key)
                .arg(&window)
                .ignore()
                .cmd("EVAL")
                .arg(LUA_PURGE_BUMP)
                .arg(1)
                .arg(&document_key)
                .arg(&window)
                .ignore();
            let _: () = pipe.query_async(&mut conn).await?;
            return Ok(());
        }
        self.purge_field(&collection_key, &document_key).await?;
        self.purge(&document_key).await
    }

    /// Lists the non-reserved fields of a hash (`HKEYS`).
    pub async fn list(&self, key: &str) -> Result<Vec<String>> {
        let key = key.to_lowercase();
        let mut conn = self.shard(&key);
        let keys: Vec<String> = conn.hkeys(&key).await?;
        Ok(keys.into_iter().filter(|k| k != GENERATION_FIELD && k != TOMBSTONE_FIELD).collect())
    }
}

fn now_secs() -> u64 {
    SystemTime::now().duration_since(UNIX_EPOCH).map(|d| d.as_secs()).unwrap_or(0)
}

/// Rust entries are prefixed with an 8-byte big-endian timestamp.
fn wrap_envelope(value: &[u8]) -> Vec<u8> {
    let mut out = Vec::with_capacity(8 + value.len());
    out.extend_from_slice(&now_secs().to_be_bytes());
    out.extend_from_slice(value);
    out
}

fn unwrap_envelope(mut raw: Vec<u8>, ttl_secs: u64) -> Option<Vec<u8>> {
    if raw.len() < 8 {
        return None;
    }
    let mut ts = [0u8; 8];
    ts.copy_from_slice(&raw[..8]);
    let saved = u64::from_be_bytes(ts);
    if ttl_secs > 0 && saved + ttl_secs <= now_secs() {
        return None;
    }
    raw.drain(..8);
    Some(raw)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn envelope_round_trip() {
        let wrapped = wrap_envelope(b"hello");
        assert_eq!(unwrap_envelope(wrapped, 60).as_deref(), Some(&b"hello"[..]));
        let mut old = wrap_envelope(b"x");
        old[..8].copy_from_slice(&0u64.to_be_bytes());
        assert!(unwrap_envelope(old, 60).is_none());
    }

    #[test]
    fn crc32_is_zlib_compatible() {
        // PHP crc32() is the zlib CRC-32.
        assert_eq!(crc32fast::hash(b"123456789"), 0xCBF43926);
    }
}
