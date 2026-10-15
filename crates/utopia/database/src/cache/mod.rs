//! The query cache: `find()` results per collection, its invalidation, and
//! the value objects both take.
//!
//! The cache is shared with the PHP library (`utopia-php/database` 8.x)
//! through Redis, so keys, fields and stored values are byte for byte what
//! PHP reads and writes.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Cache\Query` | [`Query`] |
//! | `Cache\Invalidator` | [`Invalidator`] |
//! | `Cache\Scope` | [`Scope`] |
//! | `Cache\Entry` | [`Entry`] |
//! | `Cache\Epoch` | [`Epoch`] |
//! | `Cache\Region` | [`Region`] |
//! | `Cache\Registration` | [`Registration`] |
//! | `Cache\Owners`, `Cache\Fields` | [`Owners`] |

mod invalidator;
mod owners;
mod query;

#[cfg(test)]
pub(crate) mod testing;
#[cfg(test)]
mod tests;

pub use invalidator::Invalidator;
pub use owners::Owners;
pub use query::Query;

use crate::document::Document;
use crate::value::Value;

/// What a query cache entry belongs to, and the settings of the database it
/// is read or written through: one query cache shared by several databases
/// keys and times out each call by the scope it is handed.
///
/// PHP: `Utopia\Database\Cache\Scope`.
#[derive(Debug, Clone, PartialEq)]
pub struct Scope {
    /// `$hostname`.
    pub hostname: String,
    /// `$database`.
    pub database: String,
    /// `$namespace`.
    pub namespace: String,
    /// `$tenant` (`null`, an integer or a string).
    pub tenant: Value,
    /// The database's cache name (`$name`).
    pub name: String,
    /// Seconds after which a write that has not activated is treated as
    /// abandoned (`$writerTimeout`).
    pub writer_timeout: i64,
}

impl Scope {
    /// `Scope::NAME`.
    pub const NAME: &'static str = "default";
    /// `Scope::WRITER_TIMEOUT`.
    pub const WRITER_TIMEOUT: i64 = 3600;

    /// The scope with another tenant (`withTenant()`).
    pub fn with_tenant(&self, tenant: Value) -> Scope {
        Scope { tenant, ..self.clone() }
    }
}

impl Default for Scope {
    fn default() -> Self {
        Scope {
            hostname: String::new(),
            database: String::new(),
            namespace: String::new(),
            tenant: Value::Null,
            name: Scope::NAME.to_owned(),
            writer_timeout: Scope::WRITER_TIMEOUT,
        }
    }
}

/// A query's slot in a collection's current epoch.
///
/// PHP: `Utopia\Database\Cache\Entry`.
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct Entry {
    /// The collection key (`$key`).
    pub key: String,
    /// The collection id (`$collection`).
    pub collection: String,
    /// The query's field (`$field`).
    pub field: String,
    /// The epoch it is read under (`$epoch`).
    pub epoch: String,
    /// The slot the field hashes to (`$slot`).
    pub slot: String,
}

/// The epoch a collection's documents may be cached under, or none while a
/// write blocks the collection, with the time that write blocked it.
///
/// PHP: `Utopia\Database\Cache\Epoch`.
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct Epoch {
    /// `$value`.
    pub value: Option<String>,
    /// `$blockedAt`.
    pub blocked_at: Option<i64>,
}

/// A collection's query cache settings.
///
/// PHP: `Utopia\Database\Cache\Region`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Region {
    /// Seconds results live (`$ttl`).
    pub ttl: i64,
    /// Whether results are cached (`$enabled`).
    pub enabled: bool,
}

impl Default for Region {
    fn default() -> Self {
        Region { ttl: 3600, enabled: true }
    }
}

/// Where a write's owner token is registered.
///
/// PHP: `Utopia\Database\Cache\Registration`.
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct Registration {
    /// `$key`.
    pub key: String,
    /// `$field` (empty for a key of its own).
    pub field: String,
}

/// What a mutation writes, for the invalidation to find the collections it
/// touches: a document (its collection, or for the metadata collection the
/// collection it defines; an attribute event's relationship attribute also
/// names its related collection), or a collection id.
///
/// PHP: the `mixed $data` of `Invalidator::tokens()` (a `Document`, a
/// string, or an array of them).
#[derive(Debug, Clone, PartialEq)]
pub enum Target {
    /// A document.
    Document(Document),
    /// A collection id.
    Collection(String),
}

/// Invalidation tokens by the collection key they invalidate, in order,
/// each key once.
///
/// PHP: `array<string, string>`.
pub type Tokens = Vec<(String, String)>;
