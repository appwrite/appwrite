//! The constants of PHP's `Database` class, as associated constants of
//! [`Database`] with the same names (`Database::VAR_STRING`).

use super::Database;
use crate::document::Document;
use crate::value::{Array, ArrayKey, Str, Value};

#[allow(missing_docs)]
impl Database {
    // Simple types
    pub const VAR_STRING: &'static str = "string";
    pub const VAR_INTEGER: &'static str = "integer";
    pub const VAR_BIGINT: &'static str = "bigint";
    pub const VAR_FLOAT: &'static str = "double";
    pub const VAR_BOOLEAN: &'static str = "boolean";
    pub const VAR_DATETIME: &'static str = "datetime";
    pub const VAR_VARCHAR: &'static str = "varchar";
    pub const VAR_TEXT: &'static str = "text";
    pub const VAR_MEDIUMTEXT: &'static str = "mediumtext";
    pub const VAR_LONGTEXT: &'static str = "longtext";
    // ID types
    pub const VAR_ID: &'static str = "id";
    pub const VAR_UUID7: &'static str = "uuid7";
    // Object, vector, relationship
    pub const VAR_OBJECT: &'static str = "object";
    pub const VAR_VECTOR: &'static str = "vector";
    pub const VECTOR_DISTANCE: &'static str = "$distance";
    pub const VAR_RELATIONSHIP: &'static str = "relationship";
    // Spatial types
    pub const VAR_POINT: &'static str = "point";
    pub const VAR_LINESTRING: &'static str = "linestring";
    pub const VAR_POLYGON: &'static str = "polygon";

    pub const STRING_TYPES: [&'static str; 5] =
        [Self::VAR_STRING, Self::VAR_VARCHAR, Self::VAR_TEXT, Self::VAR_MEDIUMTEXT, Self::VAR_LONGTEXT];
    pub const SPATIAL_TYPES: [&'static str; 3] = [Self::VAR_POINT, Self::VAR_LINESTRING, Self::VAR_POLYGON];
    pub const ATTRIBUTE_FILTER_TYPES: [&'static str; 6] = [
        Self::VAR_POINT,
        Self::VAR_LINESTRING,
        Self::VAR_POLYGON,
        Self::VAR_VECTOR,
        Self::VAR_OBJECT,
        Self::VAR_DATETIME,
    ];

    // Index types
    pub const INDEX_KEY: &'static str = "key";
    pub const INDEX_FULLTEXT: &'static str = "fulltext";
    pub const INDEX_UNIQUE: &'static str = "unique";
    pub const INDEX_SPATIAL: &'static str = "spatial";
    pub const INDEX_OBJECT: &'static str = "object";
    pub const INDEX_HNSW_EUCLIDEAN: &'static str = "hnsw_euclidean";
    pub const INDEX_HNSW_COSINE: &'static str = "hnsw_cosine";
    pub const INDEX_HNSW_DOT: &'static str = "hnsw_dot";
    pub const INDEX_TRIGRAM: &'static str = "trigram";
    pub const INDEX_TTL: &'static str = "ttl";

    // Limits
    pub const MAX_INT: i64 = 2147483647;
    pub const MAX_BIG_INT: i64 = i64::MAX;
    pub const MAX_DOUBLE: f64 = f64::MAX;
    pub const MAX_VECTOR_DIMENSIONS: i64 = 16000;
    pub const MAX_ARRAY_INDEX_LENGTH: i64 = 255;
    pub const MAX_UID_DEFAULT_LENGTH: i64 = 36;
    pub const MAX_TEXT_BYTES: i64 = 65535;
    pub const MAX_MEDIUMTEXT_BYTES: i64 = 16777215;
    pub const MAX_LONGTEXT_BYTES: i64 = 4294967295;
    pub const MIN_INT: i64 = -2147483648;
    pub const DEFAULT_SRID: i64 = 4326;
    pub const EARTH_RADIUS: i64 = 6371000;

    // Relations
    pub const RELATION_ONE_TO_ONE: &'static str = "oneToOne";
    pub const RELATION_ONE_TO_MANY: &'static str = "oneToMany";
    pub const RELATION_MANY_TO_ONE: &'static str = "manyToOne";
    pub const RELATION_MANY_TO_MANY: &'static str = "manyToMany";
    pub const RELATION_MUTATE_CASCADE: &'static str = "cascade";
    pub const RELATION_MUTATE_RESTRICT: &'static str = "restrict";
    pub const RELATION_MUTATE_SET_NULL: &'static str = "setNull";
    pub const RELATION_SIDE_PARENT: &'static str = "parent";
    pub const RELATION_SIDE_CHILD: &'static str = "child";
    pub const RELATION_MAX_DEPTH: i64 = 3;

    // Orders
    pub const ORDER_ASC: &'static str = "ASC";
    pub const ORDER_DESC: &'static str = "DESC";
    pub const ORDER_RANDOM: &'static str = "RANDOM";

    // Permissions
    pub const PERMISSION_CREATE: &'static str = "create";
    pub const PERMISSION_READ: &'static str = "read";
    pub const PERMISSION_UPDATE: &'static str = "update";
    pub const PERMISSION_DELETE: &'static str = "delete";
    pub const PERMISSION_WRITE: &'static str = "write";
    pub const PERMISSIONS: [&'static str; 4] =
        [Self::PERMISSION_CREATE, Self::PERMISSION_READ, Self::PERMISSION_UPDATE, Self::PERMISSION_DELETE];

    // Collections
    pub const METADATA: &'static str = "_metadata";

    // Cursor
    pub const CURSOR_BEFORE: &'static str = "before";
    pub const CURSOR_AFTER: &'static str = "after";

    // Lengths
    pub const LENGTH_KEY: i64 = 255;

    // Cache
    pub const TTL: i64 = 60 * 60 * 24;

    // Events
    pub const EVENT_ALL: &'static str = "*";
    pub const EVENT_DATABASE_LIST: &'static str = "database_list";
    pub const EVENT_DATABASE_CREATE: &'static str = "database_create";
    pub const EVENT_DATABASE_DELETE: &'static str = "database_delete";
    pub const EVENT_COLLECTION_LIST: &'static str = "collection_list";
    pub const EVENT_COLLECTION_CREATE: &'static str = "collection_create";
    pub const EVENT_COLLECTION_UPDATE: &'static str = "collection_update";
    pub const EVENT_COLLECTION_READ: &'static str = "collection_read";
    pub const EVENT_COLLECTION_DELETE: &'static str = "collection_delete";
    pub const EVENT_DOCUMENT_FIND: &'static str = "document_find";
    pub const EVENT_DOCUMENT_PURGE: &'static str = "document_purge";
    pub const EVENT_DOCUMENT_CREATE: &'static str = "document_create";
    pub const EVENT_DOCUMENTS_CREATE: &'static str = "documents_create";
    pub const EVENT_DOCUMENT_READ: &'static str = "document_read";
    pub const EVENT_DOCUMENT_UPDATE: &'static str = "document_update";
    pub const EVENT_DOCUMENTS_UPDATE: &'static str = "documents_update";
    pub const EVENT_DOCUMENTS_UPSERT: &'static str = "documents_upsert";
    pub const EVENT_DOCUMENT_DELETE: &'static str = "document_delete";
    pub const EVENT_DOCUMENTS_DELETE: &'static str = "documents_delete";
    pub const EVENT_DOCUMENT_COUNT: &'static str = "document_count";
    pub const EVENT_DOCUMENT_SUM: &'static str = "document_sum";
    pub const EVENT_DOCUMENT_INCREASE: &'static str = "document_increase";
    pub const EVENT_DOCUMENT_DECREASE: &'static str = "document_decrease";
    pub const EVENT_PERMISSIONS_CREATE: &'static str = "permissions_create";
    pub const EVENT_PERMISSIONS_READ: &'static str = "permissions_read";
    pub const EVENT_PERMISSIONS_DELETE: &'static str = "permissions_delete";
    pub const EVENT_ATTRIBUTE_CREATE: &'static str = "attribute_create";
    pub const EVENT_ATTRIBUTES_CREATE: &'static str = "attributes_create";
    pub const EVENT_ATTRIBUTE_UPDATE: &'static str = "attribute_update";
    pub const EVENT_ATTRIBUTE_DELETE: &'static str = "attribute_delete";
    pub const EVENT_INDEX_RENAME: &'static str = "index_rename";
    pub const EVENT_INDEX_CREATE: &'static str = "index_create";
    pub const EVENT_INDEX_DELETE: &'static str = "index_delete";

    // Batches
    pub const INSERT_BATCH_SIZE: i64 = 1_000;
    pub const DELETE_BATCH_SIZE: i64 = 1_000;

    // Internal keys
    pub const INTERNAL_ATTRIBUTE_KEYS: [&'static str; 4] = ["_uid", "_createdAt", "_updatedAt", "_permissions"];
    pub const INTERNAL_INDEXES: [&'static str; 6] =
        ["_id", "_uid", "_createdAt", "_updatedAt", "_permissions_id", "_permissions"];
}

impl Database {
    /// `Database::INTERNAL_ATTRIBUTES`: the system attributes every
    /// collection has, as attribute documents (`$id`, `type`, `size`, ...),
    /// in PHP's key order.
    pub fn internal_attributes_const() -> Vec<Document> {
        fn attr(entries: Vec<(&'static str, Value)>) -> Document {
            Document::from_array_unchecked(entries.into_iter().map(|(k, v)| (ArrayKey::from(k), v)).collect())
        }
        let s = |v: &str| Value::Str(Str::from(v));
        let filters =
            |names: &[&str]| -> Value { Value::Array(names.iter().map(|n| Value::Str(Str::from(*n))).collect()) };
        vec![
            attr(vec![
                ("$id", s("$id")),
                ("type", s(Self::VAR_STRING)),
                ("size", Value::Int(Self::LENGTH_KEY)),
                ("required", Value::Bool(true)),
                ("signed", Value::Bool(true)),
                ("array", Value::Bool(false)),
                ("filters", filters(&[])),
            ]),
            attr(vec![
                ("$id", s("$sequence")),
                ("type", s(Self::VAR_ID)),
                ("size", Value::Int(0)),
                ("required", Value::Bool(true)),
                ("signed", Value::Bool(true)),
                ("array", Value::Bool(false)),
                ("filters", filters(&[])),
            ]),
            attr(vec![
                ("$id", s("$collection")),
                ("type", s(Self::VAR_STRING)),
                ("size", Value::Int(Self::LENGTH_KEY)),
                ("required", Value::Bool(true)),
                ("signed", Value::Bool(true)),
                ("array", Value::Bool(false)),
                ("filters", filters(&[])),
            ]),
            attr(vec![
                ("$id", s("$tenant")),
                ("type", s(Self::VAR_ID)),
                ("size", Value::Int(0)),
                ("required", Value::Bool(false)),
                ("default", Value::Null),
                ("signed", Value::Bool(true)),
                ("array", Value::Bool(false)),
                ("filters", filters(&[])),
            ]),
            attr(vec![
                ("$id", s("$createdAt")),
                ("type", s(Self::VAR_DATETIME)),
                ("format", s("")),
                ("size", Value::Int(0)),
                ("signed", Value::Bool(false)),
                ("required", Value::Bool(false)),
                ("default", Value::Null),
                ("array", Value::Bool(false)),
                ("filters", filters(&["datetime"])),
            ]),
            attr(vec![
                ("$id", s("$updatedAt")),
                ("type", s(Self::VAR_DATETIME)),
                ("format", s("")),
                ("size", Value::Int(0)),
                ("signed", Value::Bool(false)),
                ("required", Value::Bool(false)),
                ("default", Value::Null),
                ("array", Value::Bool(false)),
                ("filters", filters(&["datetime"])),
            ]),
            attr(vec![
                ("$id", s("$permissions")),
                ("type", s(Self::VAR_STRING)),
                ("size", Value::Int(1_000_000)),
                ("signed", Value::Bool(true)),
                ("required", Value::Bool(false)),
                ("default", Value::Array(Array::new())),
                ("array", Value::Bool(false)),
                ("filters", filters(&["json"])),
            ]),
        ]
    }

    /// The `_metadata` collection's own definition: what
    /// `getCollection(METADATA)` returns and `create()` creates.
    ///
    /// PHP: `new Document(Database::COLLECTION)`.
    pub(crate) fn metadata_collection() -> Document {
        let s = |v: &str| Value::Str(Str::from(v));
        let attribute = |id: &str, kind: &str, size: i64, required: bool, filters: &[&str]| -> Value {
            Value::Array(
                [
                    ("$id", s(id)),
                    ("key", s(id)),
                    ("type", s(kind)),
                    ("size", Value::Int(size)),
                    ("required", Value::Bool(required)),
                    ("signed", Value::Bool(true)),
                    ("array", Value::Bool(false)),
                    ("filters", Value::Array(filters.iter().map(|f| s(f)).collect())),
                ]
                .into_iter()
                .map(|(k, v)| (ArrayKey::from(k), v))
                .collect(),
            )
        };
        let attributes = Value::Array(
            [
                attribute("name", Self::VAR_STRING, 256, true, &[]),
                attribute("attributes", Self::VAR_STRING, 1_000_000, false, &["json"]),
                attribute("indexes", Self::VAR_STRING, 1_000_000, false, &["json"]),
                attribute("documentSecurity", Self::VAR_BOOLEAN, 0, true, &[]),
            ]
            .into_iter()
            .collect(),
        );
        let definition: Array = [
            ("$id", s(Self::METADATA)),
            ("$collection", s(Self::METADATA)),
            ("name", s("collections")),
            ("attributes", attributes),
            ("indexes", Value::Array(Array::new())),
        ]
        .into_iter()
        .map(|(k, v)| (ArrayKey::from(k), v))
        .collect();
        Document::new(definition).unwrap_or_else(|_| unreachable!("a valid definition"))
    }
}
