//! [`Nonce`]: single-use nonces, and the [`Store`]s that remember them.

mod none;
#[cfg(feature = "redis")]
mod redis;
mod store;

use std::sync::Arc;

use php_std::Array;

pub use none::None;
#[cfg(feature = "redis-cluster")]
pub use redis::RedisCluster;
#[cfg(feature = "redis")]
pub use redis::{Redis, RedisPool};
pub use store::Store;

use crate::Result;

/// Single-use nonces: the first claim of a nonce succeeds, every later claim
/// fails until it expires. Keys follow the rate limits' pattern, a pattern
/// such as `pow:{project}` filled by [`Nonce::with_params`], and the nonce is
/// appended.
///
/// PHP: `Utopia\Abuse\Challenge\Nonce`, whose subclasses are the
/// [`Store`]s here.
///
/// ```
/// use utopia_abuse::challenge::{Nonce, nonce, params};
///
/// # utopia_async::Builder::current_thread().build()?.block_on(async {
/// let nonces = Nonce::new("pow:{project}", nonce::None, &params([("{project}", "p1")]));
/// assert_eq!(nonces.key(b"abc"), b"pow:p1:abc");
/// assert!(nonces.consume(b"abc", 60).await?);
/// assert!(!nonces.consume(b"", 60).await?);
/// # Ok::<(), utopia_abuse::Error>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Abuse\Challenge\Nonce\None;
///
/// $nonces = new None('pow:{project}', ['{project}' => 'p1']);
/// echo $nonces->key('abc'), "\n";          // pow:p1:abc
/// var_dump($nonces->consume('abc', 60));    // bool(true)
/// var_dump($nonces->consume('', 60));       // bool(false)
/// ```
#[derive(Debug)]
pub struct Nonce<S> {
    key: Vec<u8>,
    params: Array,
    store: Arc<S>,
}

impl<S> Clone for Nonce<S> {
    /// Shares the store.
    fn clone(&self) -> Self {
        Nonce { key: self.key.clone(), params: self.params.clone(), store: self.store.clone() }
    }
}

impl<S: Store> Nonce<S> {
    /// Nonces under the key pattern `key` (placeholders filled by
    /// `params`), remembered by `store`.
    ///
    /// PHP: `new Redis($key, $redis, $params)` and the other stores'
    /// constructors.
    pub fn new(key: &str, store: S, params: &Array) -> Self {
        Nonce { key: key.as_bytes().to_vec(), params: params.clone(), store: Arc::new(store) }
    }

    /// The store.
    pub fn store(&self) -> &S {
        &self.store
    }

    /// A copy with `params` added; the copy shares the store.
    ///
    /// PHP: `withParams($params)`.
    pub fn with_params(&self, params: &Array) -> Self {
        Nonce { key: self.key.clone(), params: super::issuer::merge(&self.params, params), store: self.store.clone() }
    }

    /// A copy with one param added.
    ///
    /// PHP: `withParam($name, $value)`.
    pub fn with_param(&self, name: &str, value: &str) -> Self {
        self.with_params(&super::params([(name, value)]))
    }

    /// The key a nonce is stored under: the pattern with its params
    /// replaced (`strtr()`), then `:` and the nonce.
    ///
    /// PHP: `key($nonce)`.
    pub fn key(&self, nonce: &[u8]) -> Vec<u8> {
        let pairs: Vec<(Vec<u8>, Vec<u8>)> = self
            .params
            .iter()
            .map(|(k, v)| {
                let k = match k {
                    php_std::types::KeyRef::Int(i) => i.to_string().into_bytes(),
                    php_std::types::KeyRef::Str(s) => s.to_vec(),
                };
                (k, v.to_php_string().map(|s| s.as_bytes().to_vec()).unwrap_or_default())
            })
            .collect();
        let pairs: Vec<(&[u8], &[u8])> = pairs.iter().map(|(k, v)| (k.as_slice(), v.as_slice())).collect();
        let mut key = php_std::string::strtr_array(&self.key, &pairs).into_owned();
        key.push(b':');
        key.extend_from_slice(nonce);
        key
    }

    /// Claims `nonce` for `ttl` seconds (at least 1): `true` the first time,
    /// `false` on any later claim. An empty nonce never claims. Store errors
    /// are returned, so the caller decides what an outage means.
    ///
    /// PHP: `consume($nonce, $ttl)`.
    pub async fn consume(&self, nonce: &[u8], ttl: i64) -> Result<bool> {
        if nonce.is_empty() {
            return Ok(false);
        }
        self.store.claim(&self.key(nonce), ttl.max(1)).await
    }
}
