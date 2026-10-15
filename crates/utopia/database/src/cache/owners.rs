//! [`Owners`]: where an invalidation registers the token that owns a
//! collection's cache while its write is in flight.

use std::sync::{Arc, LazyLock, Mutex, Weak};

use php_std::Str;
use utopia_cache::Cache;

use super::Registration;
use crate::error::Result;

/// Where an invalidation registers its owner token: a field of one hash per
/// collection, or a key per token on a cache that keeps no fields.
///
/// A Redis purge keeps the purged key, holding its generation, with no
/// expiry, so a key per token would outlive every write: tokens are fields of
/// one hash per collection instead. An adapter that keeps no fields lists
/// none, and gives each token a key of its own, which its purge deletes. Once
/// a cache lists a field it is not listed again.
///
/// PHP: `Utopia\Database\Cache\Owners` (and `Cache\Fields`, the caches seen
/// keeping hash fields).
#[derive(Clone)]
pub struct Owners {
    cache: Arc<Cache>,
}

impl std::fmt::Debug for Owners {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Owners").finish_non_exhaustive()
    }
}

/// The caches seen keeping hash fields (PHP's `Cache\Fields`, a `WeakMap`
/// keyed by the cache object). Only that answer is remembered: a cache that
/// once lists no field, as after a flush between a save and its list, is
/// asked again. A cache is known by its `Arc`, held weakly so a dropped
/// cache is forgotten as PHP's `WeakMap` forgets it.
static FIELDS: LazyLock<Mutex<Vec<Weak<Cache>>>> = LazyLock::new(Default::default);

/// `Fields::kept()`.
pub(crate) fn kept(cache: &Arc<Cache>) -> bool {
    let mut caches = FIELDS.lock().unwrap_or_else(|e| e.into_inner());
    caches.retain(|c| c.strong_count() > 0);
    caches.iter().any(|c| std::ptr::eq(c.as_ptr(), Arc::as_ptr(cache)))
}

/// `Fields::remember()`.
pub(crate) fn remember(cache: &Arc<Cache>) {
    if kept(cache) {
        return;
    }
    FIELDS.lock().unwrap_or_else(|e| e.into_inner()).push(Arc::downgrade(cache));
}

impl Owners {
    /// The owners of `cache`.
    pub fn new(cache: Arc<Cache>) -> Self {
        Owners { cache }
    }

    /// Registers `token` as an owner of the collection key `key`
    /// (`register()`): whether it was stored.
    pub async fn register(&self, key: &str, token: &str) -> Result<bool> {
        let owners = owners_key(key);
        let value = php_std::Value::Str(Str::from(token));
        if !self.cache.save(&owners, &value, token, 0).await? {
            return Ok(false);
        }
        if self.is_field(&owners, token).await? {
            return Ok(true);
        }
        Ok(self.cache.save(&owner_key(key, token), &value, "", 0).await?)
    }

    /// Where `token` is registered for `key` (`find()`).
    pub async fn find(&self, key: &str, token: &str) -> Result<Registration> {
        let owners = owners_key(key);
        Ok(if self.is_field(&owners, token).await? {
            Registration { key: owners, field: token.to_owned() }
        } else {
            Registration { key: owner_key(key, token), field: String::new() }
        })
    }

    /// `isField()`.
    async fn is_field(&self, owners: &str, token: &str) -> Result<bool> {
        if kept(&self.cache) {
            return Ok(true);
        }
        if !self.cache.list(owners).await?.iter().any(|t| t == token) {
            return Ok(false);
        }
        remember(&self.cache);
        Ok(true)
    }
}

fn owners_key(key: &str) -> String {
    format!("{key}#owners")
}

fn owner_key(key: &str, token: &str) -> String {
    format!("{key}#owner:{token}")
}
