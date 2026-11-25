//! [`None`]: no replay guard.

use super::Store;
use crate::Result;

/// Remembers nothing, so every non-empty nonce claims: turns the replay guard
/// off.
///
/// PHP: `Utopia\Abuse\Challenge\Nonce\None`.
#[derive(Debug, Clone, Copy, Default)]
pub struct None;

impl Store for None {
    /// Always `true`.
    async fn claim(&self, _key: &[u8], _ttl: i64) -> Result<bool> {
        Ok(true)
    }
}
