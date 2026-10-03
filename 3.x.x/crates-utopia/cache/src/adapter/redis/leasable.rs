use parking_lot::Mutex;
use sha1::{Digest, Sha1};
use std::collections::HashMap;

use super::envelope::Envelope;
use super::noscript::NoScript;
use crate::error::CacheError;
use crate::value::{is_empty_key, unix_now, CacheValue, SaveResult};

/// PHP reserved hash field `__utopia_gen__`.
pub const GENERATION_FIELD: &str = "__utopia_gen__";
/// PHP reserved hash field `__utopia_tomb__`.
pub const TOMBSTONE_FIELD: &str = "__utopia_tomb__";

/// PHP `Leasable::LUA_SAVE_WITH_LEASE` (verbatim, including indent).
pub const LUA_SAVE_WITH_LEASE: &str = "        local current = redis.call('HGET', KEYS[1], '__utopia_gen__')\n        if current == false then current = '0' end\n        if current ~= ARGV[3] then return 0 end\n        local window = tonumber(ARGV[4]) or 0\n        if window > 0 then\n            local tomb = redis.call('HGET', KEYS[1], '__utopia_tomb__')\n            if tomb ~= false then\n                local deadline = tonumber(tomb)\n                if deadline ~= nil then\n                    local t = redis.call('TIME')\n                    local now = tonumber(t[1]) * 1000000 + tonumber(t[2])\n                    -- 2nd clause ignores a deadline left by a since-rewound clock\n                    if now < deadline and (deadline - now) <= window * 1000 then\n                        return 0\n                    end\n                end\n                redis.call('HDEL', KEYS[1], '__utopia_tomb__')\n            end\n        end\n        redis.call('HSET', KEYS[1], ARGV[1], ARGV[2])\n        return 1";

/// PHP `Leasable::LUA_PURGE_BUMP`.
pub const LUA_PURGE_BUMP: &str = "        local gen = '__utopia_gen__'\n        local tomb = '__utopia_tomb__'\n        local removed = redis.call('HLEN', KEYS[1]) - redis.call('HEXISTS', KEYS[1], gen) - redis.call('HEXISTS', KEYS[1], tomb)\n        local current = redis.call('HGET', KEYS[1], gen)\n        local next = (tonumber(current) or 0) + 1\n        redis.call('DEL', KEYS[1])\n        redis.call('HSET', KEYS[1], gen, next)\n        local window = tonumber(ARGV[1]) or 0\n        if window > 0 then\n            local t = redis.call('TIME')\n            local now = tonumber(t[1]) * 1000000 + tonumber(t[2])\n            redis.call('HSET', KEYS[1], tomb, now + window * 1000)\n        end\n        return removed";

/// PHP `Leasable::LUA_PURGE_FIELD`.
pub const LUA_PURGE_FIELD: &str = "        local removed = redis.call('HDEL', KEYS[1], ARGV[1])\n        local current = redis.call('HGET', KEYS[1], '__utopia_gen__')\n        local next = (tonumber(current) or 0) + 1\n        redis.call('HSET', KEYS[1], '__utopia_gen__', next)\n        local window = tonumber(ARGV[2]) or 0\n        if window > 0 then\n            local t = redis.call('TIME')\n            local now = tonumber(t[1]) * 1000000 + tonumber(t[2])\n            redis.call('HSET', KEYS[1], '__utopia_tomb__', now + window * 1000)\n        end\n        return removed";

/// PHP `Leasable::isReserved`.
#[must_use]
pub fn is_reserved(hash: &str) -> bool {
    hash == GENERATION_FIELD || hash == TOMBSTONE_FIELD
}

/// Effective hash field: empty / `"0"` fall back to the key (PHP `empty($hash)`).
#[must_use]
pub fn effective_hash<'a>(key: &'a str, hash: &'a str) -> &'a str {
    if is_empty_key(hash) {
        key
    } else {
        hash
    }
}

/// Transport used by lease EVALSHA / EVAL / HGET.
pub trait LeaseTransport: Send + Sync {
    fn lease_eval_sha(
        &self,
        sha: &str,
        key: &str,
        args: &[String],
    ) -> Result<LeaseReply, CacheError>;
    fn lease_eval(
        &self,
        script: &str,
        key: &str,
        args: &[String],
    ) -> Result<LeaseReply, CacheError>;
    fn lease_hget(&self, key: &str, field: &str) -> Result<Option<String>, CacheError>;
}

/// Integer-ish Redis script reply (0/1 or field count).
#[derive(Debug, Clone)]
pub enum LeaseReply {
    Int(i64),
    Other,
}

impl LeaseReply {
    #[must_use]
    pub fn is_truthy(&self) -> bool {
        match self {
            Self::Int(n) => *n != 0,
            Self::Other => true,
        }
    }
}

fn sha_for(script: &str) -> String {
    static CACHE: Mutex<Option<HashMap<String, String>>> = Mutex::new(None);
    let mut guard = CACHE.lock();
    let map = guard.get_or_insert_with(HashMap::new);
    if let Some(sha) = map.get(script) {
        return sha.clone();
    }
    let mut hasher = Sha1::new();
    hasher.update(script.as_bytes());
    let sha = hex::encode(hasher.finalize());
    map.insert(script.to_owned(), sha.clone());
    sha
}

fn lease_run(
    transport: &dyn LeaseTransport,
    script: &str,
    key: &str,
    args: &[String],
) -> Result<LeaseReply, CacheError> {
    let sha = sha_for(script);
    match transport.lease_eval_sha(&sha, key, args) {
        Err(err) if NoScript::matches(&err.to_string()) => transport.lease_eval(script, key, args),
        other => other,
    }
}

/// Shared `getGeneration` / `saveWithLease` / `purge` for Redis-family adapters.
pub fn get_generation(transport: &dyn LeaseTransport, key: &str) -> Result<String, CacheError> {
    Ok(transport
        .lease_hget(key, GENERATION_FIELD)?
        .unwrap_or_else(|| "0".into()))
}

#[allow(clippy::unnecessary_wraps)]
pub fn save_with_lease(
    transport: &dyn LeaseTransport,
    key: &str,
    data: &CacheValue,
    hash: &str,
    generation: &str,
    grace_window: i32,
) -> Result<SaveResult, CacheError> {
    if is_empty_key(key) || data.is_php_empty() {
        return Ok(SaveResult::Failed);
    }
    let hash = effective_hash(key, hash);
    if is_reserved(hash) {
        return Ok(SaveResult::Failed);
    }
    let value = match Envelope::encode(data, unix_now()) {
        Ok(v) => v,
        Err(_) => return Ok(SaveResult::Failed),
    };
    let args = vec![
        hash.to_owned(),
        value,
        generation.to_owned(),
        grace_window.to_string(),
    ];
    match lease_run(transport, LUA_SAVE_WITH_LEASE, key, &args) {
        Ok(reply) if reply.is_truthy() => Ok(SaveResult::Saved(data.clone())),
        Ok(_) | Err(_) => Ok(SaveResult::Failed),
    }
}

pub fn purge(
    transport: &dyn LeaseTransport,
    key: &str,
    hash: &str,
    grace_window: i32,
) -> Result<bool, CacheError> {
    if !is_empty_key(hash) {
        if is_reserved(hash) {
            return Ok(false);
        }
        let args = vec![hash.to_owned(), grace_window.to_string()];
        return Ok(lease_run(transport, LUA_PURGE_FIELD, key, &args)?.is_truthy());
    }
    let args = vec![grace_window.to_string()];
    Ok(lease_run(transport, LUA_PURGE_BUMP, key, &args)?.is_truthy())
}
