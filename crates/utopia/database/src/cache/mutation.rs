//! [`Mutation`]: the record of the writes in flight on a collection's cache,
//! whose CAS version is the epoch its entries are cached under.

use std::sync::Arc;

use php_std::{Array, ArrayKey, Str};
use utopia_cache::Cache;

use super::Epoch;
use crate::error::{Error, Kind, Result};

/// How many times a change of the record is tried before it is taken as
/// contended.
const ATTEMPTS: usize = 16;

/// A `Utopia\Cache\Exception\Unavailable`.
fn unavailable(message: String) -> Error {
    Error::new(Kind::CacheUnavailable, message)
}

/// One evictable record holds every writer of a collection; its CAS version
/// is the epoch. A cold record gets a fresh identity; even a writer whose
/// registration was evicted rotates that identity after commit, retiring
/// anything filled while it was in flight.
///
/// The record lives at `<key>#state` as `['owners' => [<token> => <time
/// registered>]]`, byte for byte what PHP stores.
///
/// PHP: `Utopia\Database\Cache\Mutation`.
#[derive(Clone)]
pub struct Mutation {
    cache: Arc<Cache>,
}

impl std::fmt::Debug for Mutation {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Mutation").finish_non_exhaustive()
    }
}

impl Mutation {
    /// The record of `cache` (`new Mutation($cache)`).
    pub fn new(cache: Arc<Cache>) -> Self {
        Mutation { cache }
    }

    /// The epoch entries of `key` are cached under, or the time the oldest
    /// live writer blocked it (`read()`). Writers older than `timeout`
    /// seconds no longer count.
    pub async fn read(&self, key: &str, timeout: i64) -> Result<Epoch> {
        self.change(key, timeout, None, false).await
    }

    /// Registers `token` as a writer of `key` (`begin()`).
    pub async fn begin(&self, key: &str, token: &str, timeout: i64) -> Result<()> {
        self.change(key, timeout, Some(token), true).await.map(|_| ())
    }

    /// Releases `token`, rotating the record's identity (`finish()`).
    pub async fn finish(&self, key: &str, token: &str, timeout: i64) -> Result<()> {
        self.change(key, timeout, Some(token), false).await.map(|_| ())
    }

    /// `change()`.
    async fn change(&self, key: &str, timeout: i64, token: Option<&str>, begin: bool) -> Result<Epoch> {
        let key = format!("{key}#state");
        for _ in 0..ATTEMPTS {
            let snapshot = self.cache.load_versioned(&key).await?;
            let mut changed = false;
            let state = match snapshot.value {
                None | Some(php_std::Value::Bool(false)) => {
                    changed = true;
                    None
                }
                Some(value) => Some(value),
            };
            let records = match &state {
                None => Array::new(),
                Some(php_std::Value::Array(state)) => match state.get_str("owners") {
                    Some(php_std::Value::Array(owners)) => owners.clone(),
                    _ => return Err(unavailable(format!("Invalid cache mutation record '{key}'"))),
                },
                Some(_) => return Err(unavailable(format!("Invalid cache mutation record '{key}'"))),
            };
            let mut epoch = snapshot.version;
            let now = php_std::datetime::time();
            let mut owners: Vec<(String, i64)> = Vec::new();
            for (owner, created) in records.iter() {
                // PHP's array keys: a numeric token is an integer key, which
                // `is_string()` refuses.
                let (php_std::types::KeyRef::Str(owner), php_std::Value::Int(created)) = (owner, created) else {
                    return Err(unavailable(format!("Invalid cache writer in '{key}'")));
                };
                let owner = String::from_utf8_lossy(owner.as_bytes()).into_owned();
                if begin || created.wrapping_add(timeout) > now {
                    owners.push((owner, *created));
                } else if token.is_some() {
                    changed = true;
                }
            }
            if let Some(token) = token {
                if begin {
                    match owners.iter_mut().find(|(o, _)| o == token) {
                        Some(entry) => entry.1 = now,
                        None => owners.push((token.to_owned(), now)),
                    }
                } else {
                    owners.retain(|(o, _)| o != token);
                }
                changed = true;
            }
            if changed {
                let record: Array = owners
                    .iter()
                    .map(|(owner, created)| (ArrayKey::Str(Str::from(owner.as_str())), php_std::Value::Int(*created)))
                    .collect();
                let data = php_std::Value::Array(
                    [(ArrayKey::Str(Str::from("owners")), php_std::Value::Array(record))].into_iter().collect(),
                );
                match self.cache.compare_and_swap(&key, &data, &epoch).await? {
                    Some(version) => epoch = version,
                    None => continue,
                }
            }
            return Ok(match owners.iter().map(|(_, created)| *created).min() {
                None => Epoch { value: Some(format!("active:{epoch}")), blocked_at: None },
                Some(blocked_at) => Epoch { value: None, blocked_at: Some(blocked_at) },
            });
        }
        Err(unavailable(format!("Cache mutation record kept changing '{key}'")))
    }
}
