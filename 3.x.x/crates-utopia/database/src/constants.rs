//! PHP `Utopia\Database` constants.

use serde_json::{json, Value};

pub const VAR_STRING: &str = "string";
pub const VAR_INTEGER: &str = "integer";
pub const VAR_BIGINT: &str = "bigint";
pub const VAR_FLOAT: &str = "double";
pub const VAR_BOOLEAN: &str = "boolean";
pub const VAR_DATETIME: &str = "datetime";
pub const VAR_VARCHAR: &str = "varchar";
pub const VAR_TEXT: &str = "text";
pub const VAR_MEDIUMTEXT: &str = "mediumtext";
pub const VAR_LONGTEXT: &str = "longtext";
pub const VAR_ID: &str = "id";
pub const VAR_UUID7: &str = "uuid7";
pub const VAR_OBJECT: &str = "object";
pub const VAR_VECTOR: &str = "vector";
pub const VECTOR_DISTANCE: &str = "$distance";
pub const VAR_RELATIONSHIP: &str = "relationship";
pub const VAR_POINT: &str = "point";
pub const VAR_LINESTRING: &str = "linestring";
pub const VAR_POLYGON: &str = "polygon";

pub const STRING_TYPES: &[&str] = &[
    VAR_STRING,
    VAR_VARCHAR,
    VAR_TEXT,
    VAR_MEDIUMTEXT,
    VAR_LONGTEXT,
];
pub const SPATIAL_TYPES: &[&str] = &[VAR_POINT, VAR_LINESTRING, VAR_POLYGON];
pub const ATTRIBUTE_FILTER_TYPES: &[&str] = &[
    VAR_POINT,
    VAR_LINESTRING,
    VAR_POLYGON,
    VAR_VECTOR,
    VAR_OBJECT,
    VAR_DATETIME,
];

pub const INDEX_KEY: &str = "key";
pub const INDEX_FULLTEXT: &str = "fulltext";
pub const INDEX_UNIQUE: &str = "unique";
pub const INDEX_SPATIAL: &str = "spatial";
pub const INDEX_OBJECT: &str = "object";
pub const INDEX_HNSW_EUCLIDEAN: &str = "hnsw_euclidean";
pub const INDEX_HNSW_COSINE: &str = "hnsw_cosine";
pub const INDEX_HNSW_DOT: &str = "hnsw_dot";
pub const INDEX_TRIGRAM: &str = "trigram";
pub const INDEX_TTL: &str = "ttl";

pub const MAX_INT: i64 = 2_147_483_647;
pub const MAX_BIG_INT: i64 = i64::MAX;
pub const MAX_DOUBLE: f64 = f64::MAX;
pub const MAX_VECTOR_DIMENSIONS: i64 = 16_000;
pub const MAX_ARRAY_INDEX_LENGTH: i64 = 255;
pub const MAX_UID_DEFAULT_LENGTH: i64 = 36;
pub const MAX_TEXT_BYTES: u64 = 65_535;
pub const MAX_MEDIUMTEXT_BYTES: u64 = 16_777_215;
pub const MAX_LONGTEXT_BYTES: u64 = 4_294_967_295;
pub const MIN_INT: i64 = -2_147_483_648;

pub const DEFAULT_SRID: i64 = 4326;
pub const EARTH_RADIUS: i64 = 6_371_000;

pub const RELATION_ONE_TO_ONE: &str = "oneToOne";
pub const RELATION_ONE_TO_MANY: &str = "oneToMany";
pub const RELATION_MANY_TO_ONE: &str = "manyToOne";
pub const RELATION_MANY_TO_MANY: &str = "manyToMany";
pub const RELATION_MUTATE_CASCADE: &str = "cascade";
pub const RELATION_MUTATE_RESTRICT: &str = "restrict";
pub const RELATION_MUTATE_SET_NULL: &str = "setNull";
pub const RELATION_SIDE_PARENT: &str = "parent";
pub const RELATION_SIDE_CHILD: &str = "child";
pub const RELATION_MAX_DEPTH: i64 = 3;
pub const RELATION_QUERY_CHUNK_SIZE: i64 = 5000;

pub const ORDER_ASC: &str = "ASC";
pub const ORDER_DESC: &str = "DESC";
pub const ORDER_RANDOM: &str = "RANDOM";

pub const PERMISSION_CREATE: &str = "create";
pub const PERMISSION_READ: &str = "read";
pub const PERMISSION_UPDATE: &str = "update";
pub const PERMISSION_DELETE: &str = "delete";
pub const PERMISSION_WRITE: &str = "write";
pub const PERMISSIONS: &[&str] = &[
    PERMISSION_CREATE,
    PERMISSION_READ,
    PERMISSION_UPDATE,
    PERMISSION_DELETE,
];

pub const METADATA: &str = "_metadata";
pub const CURSOR_BEFORE: &str = "before";
pub const CURSOR_AFTER: &str = "after";
pub const LENGTH_KEY: i64 = 255;
pub const TTL: i64 = 60 * 60 * 24;
pub const CACHE_EMPTY_MARKER: &str = "$empty";

pub const EVENT_ALL: &str = "*";
pub const EVENT_DATABASE_LIST: &str = "database_list";
pub const EVENT_DATABASE_CREATE: &str = "database_create";
pub const EVENT_DATABASE_DELETE: &str = "database_delete";
pub const EVENT_COLLECTION_LIST: &str = "collection_list";
pub const EVENT_COLLECTION_CREATE: &str = "collection_create";
pub const EVENT_COLLECTION_UPDATE: &str = "collection_update";
pub const EVENT_COLLECTION_READ: &str = "collection_read";
pub const EVENT_COLLECTION_DELETE: &str = "collection_delete";
pub const EVENT_DOCUMENT_FIND: &str = "document_find";
pub const EVENT_DOCUMENT_PURGE: &str = "document_purge";
pub const EVENT_DOCUMENT_CREATE: &str = "document_create";
pub const EVENT_DOCUMENTS_CREATE: &str = "documents_create";
pub const EVENT_DOCUMENT_READ: &str = "document_read";
pub const EVENT_DOCUMENT_UPDATE: &str = "document_update";
pub const EVENT_DOCUMENTS_UPDATE: &str = "documents_update";
pub const EVENT_DOCUMENTS_UPSERT: &str = "documents_upsert";
pub const EVENT_DOCUMENT_DELETE: &str = "document_delete";
pub const EVENT_DOCUMENTS_DELETE: &str = "documents_delete";
pub const EVENT_DOCUMENT_COUNT: &str = "document_count";
pub const EVENT_DOCUMENT_SUM: &str = "document_sum";
pub const EVENT_DOCUMENT_INCREASE: &str = "document_increase";
pub const EVENT_DOCUMENT_DECREASE: &str = "document_decrease";
pub const EVENT_PERMISSIONS_CREATE: &str = "permissions_create";
pub const EVENT_PERMISSIONS_READ: &str = "permissions_read";
pub const EVENT_PERMISSIONS_DELETE: &str = "permissions_delete";
pub const EVENT_ATTRIBUTE_CREATE: &str = "attribute_create";
pub const EVENT_ATTRIBUTES_CREATE: &str = "attributes_create";
pub const EVENT_ATTRIBUTE_UPDATE: &str = "attribute_update";
pub const EVENT_ATTRIBUTE_DELETE: &str = "attribute_delete";
pub const EVENT_INDEX_RENAME: &str = "index_rename";
pub const EVENT_INDEX_CREATE: &str = "index_create";
pub const EVENT_INDEX_DELETE: &str = "index_delete";

pub const INSERT_BATCH_SIZE: i64 = 1_000;
pub const DELETE_BATCH_SIZE: i64 = 1_000;

pub const INTERNAL_ATTRIBUTE_KEYS: &[&str] = &["_uid", "_createdAt", "_updatedAt", "_permissions"];
pub const INTERNAL_INDEXES: &[&str] = &[
    "_id",
    "_uid",
    "_createdAt",
    "_updatedAt",
    "_permissions_id",
    "_permissions",
];

fn attr(id: &str, type_: &str, size: i64, required: bool, extra: Value) -> Value {
    let mut v = json!({
        "$id": id,
        "type": type_,
        "size": size,
        "required": required,
        "signed": true,
        "array": false,
        "filters": [],
    });
    if let (Value::Object(base), Value::Object(add)) = (&mut v, extra) {
        for (k, val) in add {
            base.insert(k, val);
        }
    }
    v
}

/// PHP `Database::INTERNAL_ATTRIBUTES`.
pub fn internal_attributes() -> Vec<Value> {
    vec![
        attr("$id", VAR_STRING, LENGTH_KEY, true, json!({})),
        attr("$sequence", VAR_ID, 0, true, json!({})),
        attr("$collection", VAR_STRING, LENGTH_KEY, true, json!({})),
        attr("$tenant", VAR_ID, 0, false, json!({"default": null})),
        attr(
            "$createdAt",
            VAR_DATETIME,
            0,
            false,
            json!({"format": "", "signed": false, "default": null, "filters": ["datetime"]}),
        ),
        attr(
            "$updatedAt",
            VAR_DATETIME,
            0,
            false,
            json!({"format": "", "signed": false, "default": null, "filters": ["datetime"]}),
        ),
        attr(
            "$permissions",
            VAR_STRING,
            1_000_000,
            false,
            json!({"default": [], "filters": ["json"]}),
        ),
    ]
}

/// Lazy JSON copies of `INTERNAL_ATTRIBUTES` for Document filtering.
pub static INTERNAL_ATTRIBUTES: once_cell::sync::Lazy<Vec<Value>> =
    once_cell::sync::Lazy::new(internal_attributes);

pub fn collection_metadata() -> Value {
    json!({
        "$id": METADATA,
        "$collection": METADATA,
        "name": "collections",
        "attributes": [
            {
                "$id": "name",
                "key": "name",
                "type": VAR_STRING,
                "size": 256,
                "required": true,
                "signed": true,
                "array": false,
                "filters": [],
            },
            {
                "$id": "attributes",
                "key": "attributes",
                "type": VAR_STRING,
                "size": 1000000,
                "required": false,
                "signed": true,
                "array": false,
                "filters": ["json"],
            },
            {
                "$id": "indexes",
                "key": "indexes",
                "type": VAR_STRING,
                "size": 1000000,
                "required": false,
                "signed": true,
                "array": false,
                "filters": ["json"],
            },
            {
                "$id": "documentSecurity",
                "key": "documentSecurity",
                "type": VAR_BOOLEAN,
                "size": 0,
                "required": true,
                "signed": true,
                "array": false,
                "filters": [],
            }
        ],
        "indexes": [],
    })
}
