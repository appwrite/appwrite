use crate::adapter::Adapter;
use crate::error::CacheError;
use crate::value::{CacheValue, LoadResult, SaveResult};

/// PHP `Utopia\Cache\Adapter\None`.
#[derive(Debug, Clone, Copy, Default)]
pub struct None;

impl None {
    #[must_use]
    pub fn new() -> Self {
        Self
    }
}

impl Adapter for None {
    fn load(&self, _key: &str, _ttl: i64, _hash: &str) -> Result<LoadResult, CacheError> {
        Ok(LoadResult::Miss)
    }

    fn save(&self, _key: &str, _data: &CacheValue, _hash: &str) -> Result<SaveResult, CacheError> {
        Ok(SaveResult::Failed)
    }

    fn touch(&self, _key: &str, _hash: &str) -> Result<bool, CacheError> {
        Ok(false)
    }

    fn list(&self, _key: &str) -> Result<Vec<String>, CacheError> {
        Ok(Vec::new())
    }

    fn purge(&self, _key: &str, _hash: &str) -> Result<bool, CacheError> {
        Ok(true)
    }

    fn flush(&self) -> Result<bool, CacheError> {
        Ok(true)
    }

    fn ping(&self) -> bool {
        true
    }

    fn get_size(&self) -> Result<i64, CacheError> {
        Ok(0)
    }

    fn get_name(&self, _key: Option<&str>) -> String {
        "none".into()
    }
}
