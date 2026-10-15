//! The constants of a database, and its internal attributes.
//!
//! PHP: the constants and `internalAttributesFor()` of
//! `Utopia\Database\Database`. The `Database` type itself (collections,
//! documents, hooks, caches, transactions) is built on this module by the
//! next layer of the port.

use std::sync::OnceLock;

use crate::attribute::Attribute;
use crate::document;
use crate::filter::Filter;
use crate::storage;
use crate::value::{Array, Value};

/// The largest 32-bit integer (`Database::MAX_INT`).
pub const MAX_INT: i64 = 2_147_483_647;
/// The smallest 32-bit integer (`Database::MIN_INT`).
pub const MIN_INT: i64 = -2_147_483_648;
/// The largest 64-bit integer (`Database::MAX_BIG_INT`).
pub const MAX_BIG_INT: i64 = i64::MAX;
/// The largest double (`Database::MAX_DOUBLE`, `PHP_FLOAT_MAX`).
pub const MAX_DOUBLE: f64 = f64::MAX;
/// The most dimensions a vector attribute may have (`Database::MAX_VECTOR_DIMENSIONS`).
pub const MAX_VECTOR_DIMENSIONS: i64 = 16_000;
/// The attribute holding a vector distance (`Database::VECTOR_DISTANCE`).
pub const VECTOR_DISTANCE: &str = document::DISTANCE;
/// The longest index on an array attribute (`Database::MAX_ARRAY_INDEX_LENGTH`).
pub const MAX_ARRAY_INDEX_LENGTH: i64 = 255;
/// The default longest document id (`Database::MAX_UID_DEFAULT_LENGTH`).
pub const MAX_UID_DEFAULT_LENGTH: i64 = 36;
/// The bytes a `text` attribute holds (`Database::MAX_TEXT_BYTES`).
pub const MAX_TEXT_BYTES: i64 = 65_535;
/// The bytes a `mediumtext` attribute holds (`Database::MAX_MEDIUMTEXT_BYTES`).
pub const MAX_MEDIUMTEXT_BYTES: i64 = 16_777_215;
/// The bytes a `longtext` attribute holds (`Database::MAX_LONGTEXT_BYTES`).
pub const MAX_LONGTEXT_BYTES: i64 = 4_294_967_295;
/// The spatial reference system of spatial attributes (`Database::DEFAULT_SRID`).
pub const DEFAULT_SRID: i64 = 4326;
/// The earth's radius in meters (`Database::EARTH_RADIUS`).
pub const EARTH_RADIUS: i64 = 6_371_000;
/// How deep relationships are populated (`Database::RELATION_MAX_DEPTH`).
pub const RELATION_MAX_DEPTH: i64 = 3;
/// How many related ids one query reads (`Database::RELATION_QUERY_CHUNK_SIZE`).
pub const RELATION_QUERY_CHUNK_SIZE: i64 = 5_000;
/// The metadata collection (`Database::METADATA`).
pub const METADATA: &str = "_metadata";
/// The default length of a key (`Database::LENGTH_KEY`).
pub const LENGTH_KEY: i64 = 255;
/// How long cache entries live, in seconds (`Database::TTL`).
pub const TTL: i64 = 60 * 60 * 24;
/// The largest batch of a bulk write (`Database::BATCH_SIZE`).
pub const BATCH_SIZE: i64 = 1_000;
/// The default batch of `cursor()` (`Database::CURSOR_BATCH_SIZE`).
pub const CURSOR_BATCH_SIZE: i64 = 100;

/// The built-in filters (`Database::DEFAULT_FILTERS`).
pub const DEFAULT_FILTERS: [&str; 7] = ["json", "datetime", "point", "linestring", "polygon", "vector", "object"];

/// The columns of the internal attributes (`Database::INTERNAL_ATTRIBUTE_KEYS`).
pub const INTERNAL_ATTRIBUTE_KEYS: [&str; 4] =
    [storage::UID, storage::CREATED_AT, storage::UPDATED_AT, storage::PERMISSIONS];

/// The internal indexes (`Database::INTERNAL_INDEXES`).
pub const INTERNAL_INDEXES: [&str; 6] = [
    storage::SEQUENCE,
    storage::UID,
    storage::CREATED_AT,
    storage::UPDATED_AT,
    storage::INDEX_PERMISSIONS_ID,
    storage::PERMISSIONS,
];

/// The attributes every document has, with `$tenant` under shared tables:
/// `$id`, `$sequence`, `$collection`, (`$tenant`,) `$createdAt`,
/// `$updatedAt`, `$permissions`.
///
/// PHP: `Database::internalAttributesFor($sharedTables)`.
pub fn internal_attributes(shared_tables: bool) -> &'static [Attribute] {
    static SHARED: OnceLock<Vec<Attribute>> = OnceLock::new();
    static OWN: OnceLock<Vec<Attribute>> = OnceLock::new();
    let build = move || {
        let mut list = vec![
            Attribute::string(document::ID, LENGTH_KEY).required(true),
            Attribute::id(document::SEQUENCE).required(true),
            Attribute::string(document::COLLECTION, LENGTH_KEY).required(true),
        ];
        if shared_tables {
            list.push(Attribute::id(document::TENANT));
        }
        list.push(Attribute::datetime(document::CREATED_AT));
        list.push(Attribute::datetime(document::UPDATED_AT));
        list.push(
            Attribute::string(document::PERMISSIONS, 1_000_000)
                .default(Value::Array(Array::new()))
                .filters([Filter::Json]),
        );
        list
    };
    if shared_tables { SHARED.get_or_init(build) } else { OWN.get_or_init(build) }
}
