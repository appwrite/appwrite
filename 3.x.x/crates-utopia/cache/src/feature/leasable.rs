use crate::error::CacheError;
use crate::value::{CacheValue, SaveResult};

/// PHP `Utopia\Cache\Feature\Leasable`.
pub trait Leasable: Send + Sync {
    fn get_generation(&self, key: &str) -> Result<String, CacheError>;
    fn save_with_lease(
        &self,
        key: &str,
        data: &CacheValue,
        hash: &str,
        generation: &str,
    ) -> Result<SaveResult, CacheError>;
}
