//! The names the engines store documents under: the columns of the
//! internal attributes, the permissions table and the internal indexes.
//!
//! PHP: `Utopia\Database\Storage`.

use crate::document;

/// The column of `$id`.
pub const UID: &str = "_uid";
/// The column of `$sequence`.
pub const SEQUENCE: &str = "_id";
/// The column of `$collection`.
pub const COLLECTION: &str = "_collection";
/// The column of `$tenant`.
pub const TENANT: &str = "_tenant";
/// The column of `$createdAt`.
pub const CREATED_AT: &str = "_createdAt";
/// The column of `$updatedAt`.
pub const UPDATED_AT: &str = "_updatedAt";
/// The column of `$permissions`.
pub const PERMISSIONS: &str = "_permissions";
/// The column of `$distance`.
pub const DISTANCE: &str = "_distance";
/// The column of `$deletedAt`.
pub const DELETED_AT: &str = "_deletedAt";
/// The suffix of a collection's permissions table.
pub const PERMISSIONS_TABLE_SUFFIX: &str = "_perms";
/// The document column of the permissions table.
pub const PERMISSIONS_DOCUMENT: &str = "_document";
/// The type column of the permissions table.
pub const PERMISSIONS_TYPE: &str = "_type";
/// The permission column of the permissions table.
pub const PERMISSIONS_PERMISSION: &str = "_permission";
/// The primary index.
pub const INDEX_PRIMARY: &str = "primary";
/// The index on `_createdAt`.
pub const INDEX_CREATED_AT: &str = "_created_at";
/// The index on `_updatedAt`.
pub const INDEX_UPDATED_AT: &str = "_updated_at";
/// The index on `_tenant`, `_id`.
pub const INDEX_TENANT_ID: &str = "_tenant_id";
/// The first index of the permissions table.
pub const INDEX_1: &str = "_index1";
/// The index on the permissions table's document id.
pub const INDEX_PERMISSIONS_ID: &str = "_permissions_id";

/// The internal attributes and their columns, in PHP's order.
///
/// PHP: `Storage::attributeMap()`.
pub const ATTRIBUTE_MAP: [(&str, &str); 9] = [
    (document::ID, UID),
    (document::SEQUENCE, SEQUENCE),
    (document::COLLECTION, COLLECTION),
    (document::TENANT, TENANT),
    (document::CREATED_AT, CREATED_AT),
    (document::UPDATED_AT, UPDATED_AT),
    (document::DELETED_AT, DELETED_AT),
    (document::PERMISSIONS, PERMISSIONS),
    (document::DISTANCE, DISTANCE),
];

/// The column an attribute is stored in: the internal attributes' own
/// columns, any other attribute under its key.
///
/// ```
/// use utopia_database::storage;
///
/// assert_eq!(storage::column("$id"), "_uid");
/// assert_eq!(storage::column("title"), "title");
/// assert_eq!(storage::attribute("_createdAt"), "$createdAt");
/// assert_eq!(storage::permissions_table("movies"), "movies_perms");
/// ```
///
/// PHP: `Storage::column()`.
pub fn column(attribute: &str) -> &str {
    ATTRIBUTE_MAP.iter().find(|(a, _)| *a == attribute).map_or(attribute, |(_, c)| c)
}

/// The attribute a column stores (the inverse of [`column`]).
///
/// PHP: `Storage::attribute()`.
pub fn attribute(column: &str) -> &str {
    ATTRIBUTE_MAP.iter().find(|(_, c)| *c == column).map_or(column, |(a, _)| a)
}

/// The permissions table of a collection.
///
/// PHP: `Storage::permissionsTable()`.
pub fn permissions_table(collection: &str) -> String {
    format!("{collection}{PERMISSIONS_TABLE_SUFFIX}")
}
