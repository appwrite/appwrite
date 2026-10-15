//! [`Owners`]: where an invalidation registers the token that owns a
//! collection's cache while its write is in flight.

use std::sync::Arc;

use utopia_cache::Cache;

use super::Registration;
use crate::error::Result;

/// Where an invalidation registers its owner token: a field of one hash per
/// collection, or a key per token on a cache that keeps no fields.
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

impl Owners {
    /// The owners of `cache`.
    pub fn new(cache: Arc<Cache>) -> Self {
        Owners { cache }
    }

    /// `register()`.
    pub async fn register(&self, key: &str, token: &str) -> Result<bool> {
        let _ = (&self.cache, key, token);
        todo!("cache area: Owners::register")
    }

    /// `find()`.
    pub async fn find(&self, key: &str, token: &str) -> Result<Registration> {
        let _ = (key, token);
        todo!("cache area: Owners::find")
    }
}
