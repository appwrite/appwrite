//! [`Store`]: where used nonces are remembered.

use std::future::Future;

use crate::Result;

/// Remembers claimed nonce keys. Implement it to keep nonces elsewhere.
///
/// PHP: the abstract `claim()` of `Utopia\Abuse\Challenge\Nonce`.
pub trait Store: Send + Sync {
    /// Atomically marks `key` claimed for `ttl` seconds; `true` when it was
    /// not claimed already.
    ///
    /// PHP: `claim($key, $ttl)`.
    fn claim(&self, key: &[u8], ttl: i64) -> impl Future<Output = Result<bool>> + Send;
}
