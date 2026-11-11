//! Tables, columns, relationship columns, indexes (FTS5 virtual tables for
//! fulltext ones) and schema introspection.
//!
//! PHP: `SQLite::exists`, `createCollection`, `getSizeOfCollection`,
//! `deleteCollection`, `updateAttribute`, `deleteAttribute`, `renameIndex`,
//! `createIndex`, `createFulltextIndex`, `deleteIndex`,
//! `dropFulltextIndexById`, `resolveFulltextTableById`,
//! `findFulltextTables`, `createAttributes`, `createRelationship`,
//! `updateRelationship`, `deleteRelationship`, `getSchemaAttributes`,
//! `getSchemaIndexes`, `getFulltextSchemaIndexes`, `parseSqliteColumnType`,
//! `getSQLIndex`, `getKeywords`, and `SQL::createAttribute`,
//! `SQL::renameAttribute`, `SQL::getAttributeWidth`.

use php_std::pcre;

use super::Sqlite;
use super::pdo::{ParamType, PdoError, PdoFailure, Statement};
use super::sql::{self, filter, interpolate, quote};
use crate::database::Database;
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::value::{Array, ArrayKey, Str, Value};

/// `FTS_TABLE_SUFFIX`.
const FTS_TABLE_SUFFIX: &str = "_fts";
/// The trigger suffixes of an FTS5 table (insert, delete, update).
const FTS_TRIGGERS: [&str; 3] = ["ai", "ad", "au"];

/// Why a statement sequence failed: a driver error (which callers map
/// with `processException`, or let escape as a `PDOException`), or any
/// other exception.
pub(super) enum Failure {
    Pdo(PdoError),
    Other(Error),
}

impl From<PdoFailure> for Failure {
    fn from(e: PdoFailure) -> Self {
        match e {
            PdoFailure::Pdo(e) => Failure::Pdo(e),
            PdoFailure::Other(e) => Failure::Other(e),
        }
    }
}

impl From<Error> for Failure {
    fn from(e: Error) -> Self {
        Failure::Other(e)
    }
}

impl Failure {
    /// The exception as it escapes unprocessed.
    pub(super) fn raw(self) -> Error {
        match self {
            Failure::Pdo(e) => e.into_error(),
            Failure::Other(e) => e,
        }
    }
}

/// The value of an attribute of an index or attribute array or document
/// (`$index['key']` / `getAttribute('key')`).
fn attr<'a>(item: &'a Value, key: &str) -> Option<&'a Value> {
    match item {
        Value::Ext(crate::value::Ext::Document(d)) => d.raw(key),
        Value::Array(a) => a.get_str(key),
        _ => None,
    }
}

fn text_attr(document: &Document, key: &str) -> String {
    document.get(key).map(interpolate).unwrap_or_default()
}

fn int_attr(document: &Document, key: &str, default: i64) -> i64 {
    document.get(key).map(super::pdo::to_long).unwrap_or(default)
}

fn bool_attr(document: &Document, key: &str, default: bool) -> bool {
    document.get(key).map(Value::truthy).unwrap_or(default)
}

/// `$collection->getAttribute('indexes', [])`, decoded when it is the JSON
/// text a raw row holds (`json_decode($indexes, true) ?? []`).
fn decoded_indexes(collection: &Document) -> Vec<Value> {
    match collection.get("indexes") {
        Some(Value::Str(s)) => {
            match php_std::json::decode(
                s.as_bytes(),
                Some(true),
                php_std::json::DEFAULT_DEPTH,
                php_std::json::Flags::NONE,
            ) {
                Ok(php_std::Value::Array(a)) => a.into_iter().map(|(_, v)| crate::value::from_plain(v)).collect(),
                Ok(php_std::Value::Null) | Err(_) => Vec::new(),
                Ok(other) => vec![crate::value::from_plain(other)],
            }
        }
        Some(Value::Array(a)) => a.values().cloned().collect(),
        _ => Vec::new(),
    }
}

/// `$collection->getAttribute('indexes', [])` iterated as it is: the raw
/// row's JSON text is not iterable (`foreach` over a string does nothing).
fn iterable_indexes(collection: &Document) -> Vec<Value> {
    match collection.get("indexes") {
        Some(Value::Array(a)) => a.values().cloned().collect(),
        _ => Vec::new(),
    }
}

/// A list of strings from an index's `attributes`, `lengths` or `orders`.
fn values_of(item: &Value, key: &str) -> Vec<Value> {
    match attr(item, key) {
        Some(Value::Array(a)) => a.values().cloned().collect(),
        _ => Vec::new(),
    }
}

/// `escapeLikePattern($value)`: `\`, `_` and `%` escaped with `\`.
fn escape_like_pattern(value: &str) -> String {
    value.replace('\\', "\\\\").replace('_', "\\_").replace('%', "\\%")
}

/// PHP's `empty()` of a string.
fn empty_str(s: &str) -> bool {
    s.is_empty() || s == "0"
}

impl Sqlite {
    /// `getTenantSegment()`: the filtered tenant, for identifiers.
    fn tenant_segment(&self) -> String {
        filter(&interpolate(&self.tenant_of_state()))
    }

    /// `getTenantSqlLiteral()`: the tenant as an SQL literal.
    fn tenant_sql_literal(&self) -> Result<String> {
        Ok(match self.tenant_of_state() {
            Value::Null => "NULL".to_owned(),
            Value::Int(i) => i.to_string(),
            other => {
                let quoted = super::pdo::Pdo::quote(other.to_php_string().map_err(Error::from)?.as_bytes())?;
                String::from_utf8_lossy(&quoted).into_owned()
            }
        })
    }

    /// `getFulltextTablePrefix($collection)`.
    fn fulltext_table_prefix(&self, collection: &str) -> String {
        let namespace = self.namespace_of_state();
        if self.shared() {
            format!("{namespace}_{}_{}_", self.tenant_segment(), filter(collection))
        } else {
            format!("{namespace}_{}_", filter(collection))
        }
    }

    /// `getFulltextTableName($collection, $attributes)`: the table of an
    /// FTS5 index, keyed by its sorted columns.
    fn fulltext_table_name(&self, collection: &str, attributes: &[String]) -> Result<String> {
        let mut attrs: Array = attributes.iter().map(|a| Value::Str(Str::from(filter(a)))).collect();
        php_std::sort::sort(&mut attrs, php_std::sort::SortFlags::Regular).map_err(Error::from)?;
        let joined: Vec<u8> = attrs
            .values()
            .map(|v| v.to_php_string().unwrap_or(Str::EMPTY).as_bytes().to_vec())
            .collect::<Vec<_>>()
            .join(&0u8);
        let hash = php_std::string::hash(b"sha1", &joined).unwrap_or_default();
        Ok(format!("{}{}{FTS_TABLE_SUFFIX}", self.fulltext_table_prefix(collection), &hash[..16]))
    }

    /// `exists($database, $collection)` (SQLite): only collections exist.
    pub(super) fn exists_impl(&self, database: &str, collection: Option<&str>) -> Result<bool> {
        let _ = filter(database);
        let Some(collection) = collection else {
            return Ok(false);
        };
        let collection = filter(collection);
        let sql = self.trigger_sql(
            Database::EVENT_DATABASE_CREATE,
            "\n\t\t\tSELECT name FROM sqlite_master \n\t\t\tWHERE type='table' AND name = :table\n\t\t".to_owned(),
        );
        let table = format!("{}_{collection}", self.namespace_of_state());
        let mut stmt = Statement::new(sql);
        stmt.bind(":table", Value::Str(table.as_str().into()), ParamType::Str);
        let rows = self.run(&stmt).map_err(sql::raw)?.rows;
        let name = rows.first().and_then(|r| r.get_str("name")).cloned().unwrap_or(Value::Str(Str::EMPTY));
        Ok(name.strict_eq(&Value::Str(table.into())))
    }

    /// `createCollection($name, $attributes, $indexes)` (SQLite): the table,
    /// its permissions table, then the internal and given indexes.
    pub(super) async fn create_collection_impl(
        &self,
        name: &str,
        attributes: &[Document],
        indexes: &[Document],
    ) -> Result<bool> {
        let id = filter(name);
        let mut attribute_strings = Vec::new();
        for attribute in attributes {
            let attr_id = filter(attribute.id());
            let kind = sql::sql_type(
                &text_attr(attribute, "type"),
                int_attr(attribute, "size", 0),
                bool_attr(attribute, "signed", true),
                bool_attr(attribute, "array", false),
                bool_attr(attribute, "required", false),
            )?;
            attribute_strings.push(format!("`{attr_id}` {kind}, "));
        }
        let tenant_type = if self.emulate_mysql() { "\"INT(11) UNSIGNED\"" } else { "INTEGER" };
        let tenant_query = if self.shared() { format!("`_tenant` {tenant_type} DEFAULT NULL,") } else { String::new() };
        let joined = attribute_strings.join(" ");
        let columns = &joined[..joined.len().saturating_sub(2)];
        let collection = format!(
            "\n\t\t\tCREATE TABLE {} (\n\t\t\t\t`_id` INTEGER PRIMARY KEY AUTOINCREMENT,\n\t\t\t\t`_uid` VARCHAR(36) NOT NULL,\n\t\t\t\t{tenant_query}\n\t\t\t\t`_createdAt` DATETIME(3) DEFAULT NULL,\n\t\t\t\t`_updatedAt` DATETIME(3) DEFAULT NULL,\n\t\t\t\t`_permissions` MEDIUMTEXT DEFAULT NULL{}\n\t\t\t\t{columns}\n\t\t\t)\n\t\t",
            self.sql_table(&id),
            if attributes.is_empty() { "" } else { "," },
        );
        let collection = self.trigger_sql(Database::EVENT_COLLECTION_CREATE, collection);
        let permissions = format!(
            "\n\t\t\tCREATE TABLE {} (\n\t\t\t\t`_id` INTEGER PRIMARY KEY AUTOINCREMENT,\n\t\t\t\t{tenant_query}\n\t\t\t\t`_type` VARCHAR(12) NOT NULL,\n\t\t\t\t`_permission` VARCHAR(255) NOT NULL,\n\t\t\t\t`_document` VARCHAR(255) NOT NULL\n\t\t\t)\n\t\t",
            self.sql_table(&format!("{id}_perms"))
        );
        let permissions = self.trigger_sql(Database::EVENT_COLLECTION_CREATE, permissions);
        let result: std::result::Result<(), Failure> = (|| {
            self.run(&Statement::new(collection))?;
            self.run(&Statement::new(permissions))?;
            let none: [Value; 0] = [];
            self.create_index_raw(&id, "_index1", Database::INDEX_UNIQUE, &["_uid".to_owned()], &none, &none)?;
            self.create_index_raw(&id, "_created_at", Database::INDEX_KEY, &["_createdAt".to_owned()], &none, &none)?;
            self.create_index_raw(&id, "_updated_at", Database::INDEX_KEY, &["_updatedAt".to_owned()], &none, &none)?;
            let perms = format!("{id}_perms");
            self.create_index_raw(
                &perms,
                "_index_1",
                Database::INDEX_UNIQUE,
                &["_document".to_owned(), "_type".to_owned(), "_permission".to_owned()],
                &none,
                &none,
            )?;
            self.create_index_raw(
                &perms,
                "_index_2",
                Database::INDEX_KEY,
                &["_permission".to_owned(), "_type".to_owned()],
                &none,
                &none,
            )?;
            if self.shared() {
                self.create_index_raw(&id, "_tenant_id", Database::INDEX_KEY, &["_id".to_owned()], &none, &none)?;
            }
            for index in indexes {
                let index_id = filter(index.id());
                let kind = text_attr(index, "type");
                let item = Value::from(index.clone());
                let attributes: Vec<String> = values_of(&item, "attributes").iter().map(interpolate).collect();
                let lengths = values_of(&item, "lengths");
                let orders = values_of(&item, "orders");
                self.create_index_raw(&id, &index_id, &kind, &attributes, &lengths, &orders)?;
            }
            Ok(())
        })();
        match result {
            Ok(()) => Ok(true),
            Err(Failure::Pdo(e)) => Err(sql::process_exception(e)),
            Err(Failure::Other(e)) => Err(e),
        }
    }

    /// `getSizeOfCollection($collection)` (SQLite): the bytes the table, its
    /// permissions table and its FTS5 tables use (`dbstat`).
    pub(super) fn collection_size(&self, collection: &str) -> Result<i64> {
        let collection = filter(collection);
        let namespace = self.namespace_of_state();
        let name = format!("{namespace}_{collection}");
        let permissions = format!("{namespace}_{collection}_perms");
        let pattern = format!(
            "{}%{}%",
            escape_like_pattern(&self.fulltext_table_prefix(&collection)),
            escape_like_pattern(FTS_TABLE_SUFFIX)
        );
        let mut stmt = Statement::new(
            "\n             SELECT COALESCE(SUM(\"pgsize\" - \"unused\"), 0)\n             FROM \"dbstat\"\n             WHERE name = :name OR name = :perms OR name LIKE :fts_pattern ESCAPE '\\';\n        ",
        );
        stmt.bind_str(":name", Value::Str(name.into()));
        stmt.bind_str(":perms", Value::Str(permissions.into()));
        stmt.bind_str(":fts_pattern", Value::Str(pattern.into()));
        match self.run(&stmt) {
            Ok(r) => Ok(r.column().map(|v| super::pdo::to_long(&v)).unwrap_or(0)),
            Err(PdoFailure::Pdo(e)) => Err(Error::database(format!("Failed to get collection size: {}", e.message))),
            Err(PdoFailure::Other(e)) => Err(e),
        }
    }

    /// `deleteCollection($id)` (SQLite): its FTS5 tables, the table and its
    /// permissions table.
    pub(super) fn delete_collection_impl(&self, id: &str) -> Result<bool> {
        let id = filter(id);
        for table in self.find_fulltext_tables(&id).map_err(Failure::raw)? {
            let sql = self.trigger_sql(Database::EVENT_COLLECTION_DELETE, format!("DROP TABLE IF EXISTS `{table}`"));
            self.run(&Statement::new(sql)).map_err(sql::raw)?;
        }
        let sql = self
            .trigger_sql(Database::EVENT_COLLECTION_DELETE, format!("DROP TABLE IF EXISTS {}", self.sql_table(&id)));
        self.run(&Statement::new(sql)).map_err(sql::raw)?;
        let sql = self.trigger_sql(
            Database::EVENT_COLLECTION_DELETE,
            format!("DROP TABLE IF EXISTS {}", self.sql_table(&format!("{id}_perms"))),
        );
        self.run(&Statement::new(sql)).map_err(sql::raw)?;
        lock_fts(self).retain(|(c, _)| *c != id);
        Ok(true)
    }

    /// `createAttribute(...)` (SQL): `ALTER TABLE ... ADD COLUMN`.
    #[allow(clippy::too_many_arguments)]
    pub(super) fn create_attribute_impl(
        &self,
        collection: &str,
        id: &str,
        kind: &str,
        size: i64,
        signed: bool,
        array: bool,
        required: bool,
    ) -> Result<bool> {
        let id = quote(&filter(id));
        let kind = sql::sql_type(kind, size, signed, array, required)?;
        let sql = format!("ALTER TABLE {} ADD COLUMN {id} {kind} {};", self.sql_table(collection), self.lock_type());
        let sql = self.trigger_sql(Database::EVENT_ATTRIBUTE_CREATE, sql);
        self.run(&Statement::new(sql)).map(|r| r.ok).map_err(sql::processed)
    }

    /// `createAttributes($collection, $attributes)` (SQLite): one
    /// `ADD COLUMN` each, in a transaction.
    pub(super) async fn create_attributes_impl(&self, collection: &str, attributes: &[Array]) -> Result<bool> {
        self.start_transaction_impl()?;
        let result: Result<()> = (|| {
            for attribute in attributes {
                let get = |k: &str| attribute.get_str(k).filter(|v| !matches!(v, Value::Null));
                self.create_attribute_impl(
                    collection,
                    &get("$id").map(interpolate).unwrap_or_default(),
                    &get("type").map(interpolate).unwrap_or_default(),
                    get("size").map(super::pdo::to_long).unwrap_or(0),
                    get("signed").map(Value::truthy).unwrap_or(true),
                    get("array").map(Value::truthy).unwrap_or(false),
                    get("required").map(Value::truthy).unwrap_or(false),
                )?;
            }
            self.commit_transaction_impl()?;
            Ok(())
        })();
        if let Err(e) = result {
            let _ = self.rollback_transaction_impl();
            return Err(e);
        }
        Ok(true)
    }

    /// `updateAttribute(...)` (SQLite): a rename when the key changes;
    /// otherwise, under MySQL emulation, shrinking a string fails when a
    /// stored value is longer than the new size.
    #[allow(clippy::too_many_arguments)]
    pub(super) fn update_attribute_impl(
        &self,
        collection: &str,
        id: &str,
        kind: &str,
        size: i64,
        _signed: bool,
        array: bool,
        new_key: Option<&str>,
        _required: bool,
    ) -> Result<bool> {
        if let Some(new_key) = new_key
            && !empty_str(new_key)
            && new_key != id
        {
            return self.rename_attribute_impl(collection, id, new_key);
        }
        if self.emulate_mysql() && kind == Database::VAR_STRING && size > 0 && !array {
            let name = filter(collection);
            let column = filter(id);
            let shared = self.shared();
            let tenant_clause = if shared { " AND `_tenant` = :_tenant" } else { "" };
            let sql = format!(
                "SELECT 1 FROM {} WHERE LENGTH(`{column}`) > :max{tenant_clause} LIMIT 1",
                self.sql_table(&name)
            );
            let mut stmt = Statement::new(sql);
            stmt.bind(":max", Value::Int(size), ParamType::Int);
            if shared {
                let tenant = self.tenant_of_state();
                let ty = if matches!(tenant, Value::Int(_)) { ParamType::Int } else { ParamType::Str };
                stmt.bind(":_tenant", tenant, ty);
            }
            let exceeds = !self.run(&stmt).map_err(sql::raw)?.rows.is_empty();
            if exceeds {
                return Err(Error::new(
                    Kind::Truncate,
                    format!("Attribute '{id}' has values exceeding new size {size}"),
                ));
            }
        }
        Ok(true)
    }

    /// `deleteAttribute($collection, $id)` (SQLite): the indexes using the
    /// column are dropped (or rebuilt without it), then the column.
    pub(super) async fn delete_attribute_impl(&self, collection: &str, id: &str) -> Result<bool> {
        let name = filter(collection);
        let id = filter(id);
        let metadata = Document::from_pairs([("$id", Value::Str(Database::METADATA.into()))])?;
        let stored = self.get_document_impl(&metadata, &Value::Str(name.as_str().into()), &[], false)?;
        if stored.is_empty() {
            return Err(Error::new(Kind::NotFound, "Collection not found"));
        }
        for index in decoded_indexes(&stored) {
            let attributes = values_of(&index, "attributes");
            let index_id = attr(&index, "$id").map(interpolate).unwrap_or_default();
            let only = attributes.len() == 1
                && matches!(attr(&index, "attributes"), Some(Value::Array(a)) if a.get(&ArrayKey::Int(0)).is_some_and(|v| v.strict_eq(&Value::Str(id.as_str().into()))));
            if only {
                self.delete_index_impl(&name, &index_id).await?;
            } else if attributes.iter().any(|a| a.loose_eq(&Value::Str(id.as_str().into()))) {
                self.delete_index_impl(&name, &index_id).await?;
                let remaining: Vec<String> = attributes
                    .iter()
                    .filter(|a| a.to_php_string().map(|s| s.as_bytes() != id.as_bytes()).unwrap_or(true))
                    .map(interpolate)
                    .collect();
                let kind = attr(&index, "type").map(interpolate).unwrap_or_default();
                self.create_index_raw(
                    &name,
                    &index_id,
                    &kind,
                    &remaining,
                    &values_of(&index, "lengths"),
                    &values_of(&index, "orders"),
                )
                .map_err(Failure::raw)?;
            }
        }
        let sql = format!("ALTER TABLE {} DROP COLUMN `{id}`", self.sql_table(&name));
        let sql = self.trigger_sql(Database::EVENT_COLLECTION_DELETE, sql);
        match self.run(&Statement::new(sql)) {
            Ok(r) => Ok(r.ok),
            Err(PdoFailure::Pdo(e)) if e.message.contains("no such column") => Ok(true),
            Err(e) => Err(sql::raw(e)),
        }
    }

    /// `renameAttribute(...)` (SQL): `ALTER TABLE ... RENAME COLUMN`.
    pub(super) fn rename_attribute_impl(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        let collection = filter(collection);
        let sql = format!(
            "ALTER TABLE {} RENAME COLUMN {} TO {};",
            self.sql_table(&collection),
            quote(&filter(old)),
            quote(&filter(new))
        );
        let sql = self.trigger_sql(Database::EVENT_ATTRIBUTE_UPDATE, sql);
        self.run(&Statement::new(sql)).map(|r| r.ok).map_err(sql::processed)
    }

    /// Runs relationship DDL, one statement at a time.
    fn run_each(&self, event: &str, statements: Vec<String>) -> Result<bool> {
        for statement in statements {
            let sql = self.trigger_sql(event, statement);
            self.run(&Statement::new(sql)).map_err(sql::raw)?;
        }
        Ok(true)
    }

    /// `createRelationship(...)` (SQLite): the key columns.
    pub(super) fn create_relationship_impl(
        &self,
        collection: &str,
        related_collection: &str,
        kind: &str,
        two_way: bool,
        id: &str,
        two_way_key: &str,
    ) -> Result<bool> {
        let table = self.sql_table(&filter(collection));
        let related = self.sql_table(&filter(related_collection));
        let id = filter(id);
        let two_way_key = filter(two_way_key);
        let ty = sql::sql_type(Database::VAR_RELATIONSHIP, 0, false, false, false)?;
        let statements = match kind {
            Database::RELATION_ONE_TO_ONE => {
                let mut s = vec![format!("ALTER TABLE {table} ADD COLUMN `{id}` {ty} DEFAULT NULL")];
                if two_way {
                    s.push(format!("ALTER TABLE {related} ADD COLUMN `{two_way_key}` {ty} DEFAULT NULL"));
                }
                s
            }
            Database::RELATION_ONE_TO_MANY => {
                vec![format!("ALTER TABLE {related} ADD COLUMN `{two_way_key}` {ty} DEFAULT NULL")]
            }
            Database::RELATION_MANY_TO_ONE => vec![format!("ALTER TABLE {table} ADD COLUMN `{id}` {ty} DEFAULT NULL")],
            Database::RELATION_MANY_TO_MANY => Vec::new(),
            _ => return Err(Error::database("Invalid relationship type")),
        };
        self.run_each(Database::EVENT_ATTRIBUTE_CREATE, statements)
    }

    /// The junction table of a many-to-many relationship
    /// (`_<collection sequence>_<related sequence>`), read from metadata.
    fn junction_sequences(&self, collection: &str, related: &str) -> Result<(String, String)> {
        let metadata = Document::from_pairs([("$id", Value::Str(Database::METADATA.into()))])?;
        let a = self.get_document_impl(&metadata, &Value::Str(collection.into()), &[], false)?;
        let b = self.get_document_impl(&metadata, &Value::Str(related.into()), &[], false)?;
        let seq =
            |d: &Document| -> Result<String> { Ok(sql::sequence(d)?.map(|v| interpolate(&v)).unwrap_or_default()) };
        Ok((seq(&a)?, seq(&b)?))
    }

    /// `updateRelationship(...)` (SQLite): renames the key columns.
    #[allow(clippy::too_many_arguments)]
    pub(super) async fn update_relationship_impl(
        &self,
        collection: &str,
        related_collection: &str,
        kind: &str,
        two_way: bool,
        key: &str,
        two_way_key: &str,
        side: &str,
        new_key: Option<&str>,
        new_two_way_key: Option<&str>,
    ) -> Result<bool> {
        let table = self.sql_table(&filter(collection));
        let related = self.sql_table(&filter(related_collection));
        let key = filter(key);
        let two_way_key = filter(two_way_key);
        let new_key = new_key.map(filter);
        let new_two_way_key = new_two_way_key.map(filter);
        let mut statements = Vec::new();
        let rename =
            |table: &str, from: &str, to: &str| format!("ALTER TABLE {table} RENAME COLUMN `{from}` TO `{to}`");
        match kind {
            Database::RELATION_ONE_TO_ONE => {
                if let Some(n) = &new_key
                    && key != *n
                {
                    statements.push(rename(&table, &key, n));
                }
                if two_way
                    && let Some(n) = &new_two_way_key
                    && two_way_key != *n
                {
                    statements.push(rename(&related, &two_way_key, n));
                }
            }
            Database::RELATION_ONE_TO_MANY => {
                if side == Database::RELATION_SIDE_PARENT {
                    if let Some(n) = &new_two_way_key
                        && two_way_key != *n
                    {
                        statements.push(rename(&related, &two_way_key, n));
                    }
                } else if let Some(n) = &new_key
                    && key != *n
                {
                    statements.push(rename(&table, &key, n));
                }
            }
            Database::RELATION_MANY_TO_ONE => {
                if side == Database::RELATION_SIDE_CHILD {
                    if let Some(n) = &new_two_way_key
                        && two_way_key != *n
                    {
                        statements.push(rename(&related, &two_way_key, n));
                    }
                } else if let Some(n) = &new_key
                    && key != *n
                {
                    statements.push(rename(&table, &key, n));
                }
            }
            Database::RELATION_MANY_TO_MANY => {
                let (a, b) = self.junction_sequences(collection, related_collection)?;
                let junction = self.sql_table(&format!("_{a}_{b}"));
                if let Some(n) = &new_key {
                    statements.push(rename(&junction, &key, n));
                }
                if two_way && let Some(n) = &new_two_way_key {
                    statements.push(rename(&junction, &two_way_key, n));
                }
            }
            _ => return Err(Error::database("Invalid relationship type")),
        }
        self.run_each(Database::EVENT_ATTRIBUTE_UPDATE, statements)
    }

    /// `deleteRelationship(...)` (SQLite): drops the key columns, or the
    /// junction tables.
    #[allow(clippy::too_many_arguments)]
    pub(super) async fn delete_relationship_impl(
        &self,
        collection: &str,
        related_collection: &str,
        kind: &str,
        two_way: bool,
        key: &str,
        two_way_key: &str,
        side: &str,
    ) -> Result<bool> {
        let table = self.sql_table(&filter(collection));
        let related = self.sql_table(&filter(related_collection));
        let key = filter(key);
        let two_way_key = filter(two_way_key);
        let drop = |table: &str, column: &str| format!("ALTER TABLE {table} DROP COLUMN `{column}`");
        let mut statements = Vec::new();
        match kind {
            Database::RELATION_ONE_TO_ONE => {
                if side == Database::RELATION_SIDE_PARENT {
                    statements.push(drop(&table, &key));
                    if two_way {
                        statements.push(drop(&related, &two_way_key));
                    }
                } else if side == Database::RELATION_SIDE_CHILD {
                    statements.push(drop(&related, &two_way_key));
                    if two_way {
                        statements.push(drop(&table, &key));
                    }
                }
            }
            Database::RELATION_ONE_TO_MANY => statements.push(if side == Database::RELATION_SIDE_PARENT {
                drop(&related, &two_way_key)
            } else {
                drop(&table, &key)
            }),
            Database::RELATION_MANY_TO_ONE => statements.push(if side == Database::RELATION_SIDE_PARENT {
                drop(&table, &key)
            } else {
                drop(&related, &two_way_key)
            }),
            Database::RELATION_MANY_TO_MANY => {
                let (a, b) = self.junction_sequences(collection, related_collection)?;
                let base =
                    if side == Database::RELATION_SIDE_PARENT { format!("_{a}_{b}") } else { format!("_{b}_{a}") };
                statements.push(format!("DROP TABLE {}", self.sql_table(&base)));
                statements.push(format!("DROP TABLE {}", self.sql_table(&format!("{base}_perms"))));
            }
            _ => return Err(Error::database("Invalid relationship type")),
        }
        self.run_each(Database::EVENT_ATTRIBUTE_DELETE, statements)
    }

    /// `createIndex(...)` (SQLite), with its driver errors unprocessed.
    pub(super) fn create_index_impl(
        &self,
        collection: &str,
        id: &str,
        kind: &str,
        attributes: &[String],
        lengths: &[Value],
        orders: &[Value],
    ) -> Result<bool> {
        self.create_index_raw(collection, id, kind, attributes, lengths, orders).map_err(Failure::raw)
    }

    /// `createIndex(...)` (SQLite): an FTS5 table for a fulltext index,
    /// otherwise `CREATE [UNIQUE] INDEX` unless one of that name exists.
    pub(super) fn create_index_raw(
        &self,
        collection: &str,
        id: &str,
        kind: &str,
        attributes: &[String],
        _lengths: &[Value],
        _orders: &[Value],
    ) -> std::result::Result<bool, Failure> {
        let name = filter(collection);
        let id = filter(id);
        if kind == Database::INDEX_FULLTEXT {
            return self.create_fulltext_index(&name, &id, attributes);
        }
        let mut stmt = Statement::new(
            "\n\t\t\tSELECT name\n\t\t\tFROM sqlite_master\n\t\t\tWHERE type='index' AND name=:_index;\n\t\t",
        );
        let index = format!("{}_{}_{name}_{id}", self.namespace_of_state(), self.tenant_segment());
        stmt.bind_str(":_index", Value::Str(index.into()));
        if !self.run(&stmt)?.rows.is_empty() {
            return Ok(true);
        }
        let sql = self.sql_index(&name, &id, kind, attributes)?;
        let sql = self.trigger_sql(Database::EVENT_INDEX_CREATE, sql);
        match self.run(&Statement::new(sql)) {
            Ok(r) => Ok(r.ok),
            Err(e) => Err(Failure::Other(sql::processed(e))),
        }
    }

    /// `getSQLIndex($collection, $id, $type, $attributes)` (SQLite).
    fn sql_index(&self, collection: &str, id: &str, kind: &str, attributes: &[String]) -> Result<String> {
        let (kind, postfix) = match kind {
            Database::INDEX_KEY => ("INDEX", ""),
            Database::INDEX_UNIQUE => ("UNIQUE INDEX", "COLLATE NOCASE"),
            other => {
                return Err(Error::database(format!(
                    "Unknown index type: {other}. Must be one of {}, {}, {}",
                    Database::INDEX_KEY,
                    Database::INDEX_UNIQUE,
                    Database::INDEX_FULLTEXT
                )));
            }
        };
        let columns: Vec<String> =
            attributes.iter().map(|a| format!("`{}` {postfix}", filter(sql::internal_key(a)))).collect();
        let namespace = self.namespace_of_state();
        let key = format!("`{namespace}_{}_{collection}_{id}`", self.tenant_segment());
        let mut columns = columns.join(", ");
        if self.shared() {
            columns = format!("`_tenant` {postfix}, {columns}");
        }
        Ok(format!("CREATE {kind} {key} ON `{namespace}_{collection}` ({columns})"))
    }

    /// `createFulltextIndex($collection, $id, $attributes)`: an FTS5 table
    /// over the columns, triggers keeping it in sync with the collection's
    /// table, and the rows it already has.
    fn create_fulltext_index(
        &self,
        collection: &str,
        _id: &str,
        attributes: &[String],
    ) -> std::result::Result<bool, Failure> {
        if attributes.is_empty() {
            return Err(Failure::Other(Error::database("Fulltext index requires at least one attribute")));
        }
        let attributes: Vec<String> = attributes.iter().map(|a| sql::internal_key(a).to_owned()).collect();
        let fts = self.fulltext_table_name(collection, &attributes)?;
        let parent = format!("{}_{collection}", self.namespace_of_state());
        let mut stmt = Statement::new(
            "\n            SELECT name\n            FROM sqlite_master\n            WHERE type='table' AND name=:_table;\n        ",
        );
        stmt.bind_str(":_table", Value::Str(fts.as_str().into()));
        if !self.run(&stmt)?.rows.is_empty() {
            return Ok(true);
        }
        let columns: Vec<String> = attributes.iter().map(|a| filter(a)).collect();
        let fts_columns = columns.join(", ");
        let column_list = columns.iter().map(|c| format!("`{c}`")).collect::<Vec<_>>().join(", ");
        let new_list = columns.iter().map(|c| format!("NEW.`{c}`")).collect::<Vec<_>>().join(", ");
        let old_list = columns.iter().map(|c| format!("OLD.`{c}`")).collect::<Vec<_>>().join(", ");
        let literal = if self.shared() { Some(self.tenant_sql_literal()?) } else { None };
        let insert_when = literal.as_ref().map(|t| format!(" WHEN NEW.`_tenant` IS {t}")).unwrap_or_default();
        let delete_when = literal.as_ref().map(|t| format!(" WHEN OLD.`_tenant` IS {t}")).unwrap_or_default();
        let update_when =
            literal.as_ref().map(|t| format!(" WHEN OLD.`_tenant` IS {t} OR NEW.`_tenant` IS {t}")).unwrap_or_default();
        let backfill_where = literal.as_ref().map(|t| format!(" WHERE `_tenant` IS {t}")).unwrap_or_default();

        self.start_transaction_impl()?;
        let result: std::result::Result<(), Failure> = (|| {
            let create = format!(
                "CREATE VIRTUAL TABLE `{fts}` USING fts5({fts_columns}, content=\"{parent}\", content_rowid=\"_id\")"
            );
            let create = self.trigger_sql(Database::EVENT_INDEX_CREATE, create);
            self.run(&Statement::new(create))?;
            let [ai, ad, au] = FTS_TRIGGERS;
            self.run(&Statement::new(format!(
                "\n                CREATE TRIGGER `{fts}_{ai}` AFTER INSERT ON `{parent}`{insert_when} BEGIN\n                    INSERT INTO `{fts}` (rowid, {column_list}) VALUES (NEW.`_id`, {new_list});\n                END\n            "
            )))?;
            self.run(&Statement::new(format!(
                "\n                CREATE TRIGGER `{fts}_{ad}` AFTER DELETE ON `{parent}`{delete_when} BEGIN\n                    INSERT INTO `{fts}` (`{fts}`, rowid, {column_list}) VALUES ('delete', OLD.`_id`, {old_list});\n                END\n            "
            )))?;
            self.run(&Statement::new(format!(
                "\n                CREATE TRIGGER `{fts}_{au}` AFTER UPDATE OF {column_list} ON `{parent}`{update_when} BEGIN\n                    INSERT INTO `{fts}` (`{fts}`, rowid, {column_list}) VALUES ('delete', OLD.`_id`, {old_list});\n                    INSERT INTO `{fts}` (rowid, {column_list}) VALUES (NEW.`_id`, {new_list});\n                END\n            "
            )))?;
            self.run(&Statement::new(format!(
                "INSERT INTO `{fts}` (rowid, {column_list}) SELECT `_id`, {column_list} FROM `{parent}`{backfill_where}"
            )))?;
            self.commit_transaction_impl()?;
            Ok(())
        })();
        if let Err(e) = result {
            let _ = self.rollback_transaction_impl();
            return Err(e);
        }
        lock_fts(self).retain(|(c, _)| c != collection);
        Ok(true)
    }

    /// `deleteIndex($collection, $id)` (SQLite): `DROP INDEX`, or the FTS5
    /// table when no regular index has that name; a missing index is fine.
    pub(super) async fn delete_index_impl(&self, collection: &str, id: &str) -> Result<bool> {
        let name = filter(collection);
        let id = filter(id);
        let regular = format!("{}_{}_{name}_{id}", self.namespace_of_state(), self.tenant_segment());
        let mut stmt = Statement::new(
            "\n            SELECT name FROM sqlite_master WHERE type='index' AND name=:_index\n        ",
        );
        stmt.bind_str(":_index", Value::Str(regular.as_str().into()));
        let has_regular = !self.run(&stmt).map_err(sql::raw)?.rows.is_empty();
        if !has_regular && self.drop_fulltext_index_by_id(&name, &id).map_err(Failure::raw)? {
            return Ok(true);
        }
        let sql = self.trigger_sql(Database::EVENT_INDEX_DELETE, format!("DROP INDEX `{regular}`"));
        match self.run(&Statement::new(sql)) {
            Ok(r) => Ok(r.ok),
            Err(PdoFailure::Pdo(e)) if e.message.contains("no such index") => Ok(true),
            Err(e) => Err(sql::raw(e)),
        }
    }

    /// `dropFulltextIndexById($collection, $id)`: drops the FTS5 table (and
    /// its triggers) of index `id`; `false` when the collection has none.
    fn drop_fulltext_index_by_id(&self, collection: &str, id: &str) -> std::result::Result<bool, Failure> {
        let tables = self.find_fulltext_tables(collection)?;
        if tables.is_empty() {
            return Ok(false);
        }
        let fts = match self.resolve_fulltext_table_by_id(collection, id, &tables)? {
            Some(t) => t,
            None if tables.len() == 1 => tables[0].clone(),
            None => {
                return Err(Failure::Other(Error::database(format!(
                    "Cannot resolve fulltext index '{id}' on '{collection}': {} FTS5 tables exist and metadata does not match any of them.",
                    tables.len()
                ))));
            }
        };
        self.start_transaction_impl()?;
        let result: std::result::Result<(), Failure> = (|| {
            for suffix in FTS_TRIGGERS {
                self.run(&Statement::new(format!("DROP TRIGGER IF EXISTS `{fts}_{suffix}`")))?;
            }
            let sql = self.trigger_sql(Database::EVENT_INDEX_DELETE, format!("DROP TABLE IF EXISTS `{fts}`"));
            self.run(&Statement::new(sql))?;
            self.commit_transaction_impl()?;
            Ok(())
        })();
        if let Err(e) = result {
            let _ = self.rollback_transaction_impl();
            return Err(e);
        }
        lock_fts(self).retain(|(c, _)| c != collection);
        Ok(true)
    }

    /// `resolveFulltextTableById($collection, $id, $candidates)`: the FTS5
    /// table of index `id` per the collection's metadata, if it is one of
    /// `candidates`.
    fn resolve_fulltext_table_by_id(
        &self,
        collection: &str,
        id: &str,
        candidates: &[String],
    ) -> std::result::Result<Option<String>, Failure> {
        let metadata = Document::from_pairs([("$id", Value::Str(Database::METADATA.into()))])?;
        let stored = match self.get_document_impl(&metadata, &Value::Str(collection.into()), &[], false) {
            Ok(d) => d,
            Err(e) if e.kind() == Kind::NotFound => return Ok(None),
            Err(e) => return Err(Failure::Other(e)),
        };
        if stored.is_empty() {
            return Ok(None);
        }
        let filtered = filter(id);
        for index in iterable_indexes(&stored) {
            let Some(index_id) = attr(&index, "$id").filter(|v| !matches!(v, Value::Null)) else {
                continue;
            };
            if filter(&interpolate(index_id)) != filtered {
                continue;
            }
            if !attr(&index, "type").is_some_and(|t| t.strict_eq(&Value::Str(Database::INDEX_FULLTEXT.into()))) {
                return Ok(None);
            }
            let internal: Vec<String> =
                values_of(&index, "attributes").iter().map(|a| sql::internal_key(&interpolate(a)).to_owned()).collect();
            let candidate = self.fulltext_table_name(collection, &internal)?;
            return Ok(candidates.contains(&candidate).then_some(candidate));
        }
        Ok(None)
    }

    /// `findFulltextTables($collection)`: every FTS5 table of the collection.
    pub(super) fn find_fulltext_tables(&self, collection: &str) -> std::result::Result<Vec<String>, Failure> {
        let mut stmt = Statement::new(
            "\n            SELECT name FROM sqlite_master\n            WHERE type='table'\n              AND name LIKE :_prefix ESCAPE '\\'\n              AND name LIKE :_suffix ESCAPE '\\'\n        ",
        );
        stmt.bind_str(
            ":_prefix",
            Value::Str(format!("{}%", escape_like_pattern(&self.fulltext_table_prefix(collection))).into()),
        );
        stmt.bind_str(":_suffix", Value::Str(format!("%{}", escape_like_pattern(FTS_TABLE_SUFFIX)).into()));
        let rows = self.run(&stmt)?.rows;
        Ok(rows.into_iter().filter_map(|r| r.values().next().map(interpolate)).collect())
    }

    /// `findFulltextTableForAttribute($collection, $attribute)`: the FTS5
    /// table that has a column for the attribute (cached per collection).
    pub(super) fn fulltext_table_for_attribute(
        &self,
        collection: &str,
        attribute: &str,
    ) -> std::result::Result<Option<String>, Failure> {
        let cached = lock_fts(self).iter().find(|(c, _)| c == collection).map(|(_, m)| m.clone());
        let map = match cached {
            Some(m) => m,
            None => {
                let mut map: Vec<(String, String)> = Vec::new();
                for table in self.find_fulltext_tables(collection)? {
                    let rows = self.run(&Statement::new(format!("PRAGMA table_info(`{table}`)")))?.rows;
                    for row in rows {
                        if let Some(Value::Str(name)) = row.get_str("name")
                            && !name.is_empty()
                        {
                            let name = String::from_utf8_lossy(name.as_bytes()).into_owned();
                            match map.iter_mut().find(|(k, _)| *k == name) {
                                Some(slot) => slot.1 = table.clone(),
                                None => map.push((name, table.clone())),
                            }
                        }
                    }
                }
                lock_fts(self).push((collection.to_owned(), map.clone()));
                map
            }
        };
        Ok(map.into_iter().find(|(k, _)| k == attribute).map(|(_, t)| t))
    }

    /// `renameIndex($collection, $old, $new)` (SQLite): dropped and created
    /// again under the new name.
    pub(super) async fn rename_index_impl(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        let metadata = Document::from_pairs([("$id", Value::Str(Database::METADATA.into()))])?;
        let stored = self.get_document_impl(&metadata, &Value::Str(collection.into()), &[], false)?;
        if stored.is_empty() {
            return Err(Error::new(Kind::NotFound, "Collection not found"));
        }
        let old = filter(old);
        let new = filter(new);
        let index = decoded_indexes(&stored)
            .into_iter()
            .find(|node| attr(node, "key").is_some_and(|k| k.strict_eq(&Value::Str(old.as_str().into()))));
        let Some(index) = index else {
            return Ok(false);
        };
        let id = stored.id().to_owned();
        if !self.delete_index_impl(&id, &old).await? {
            return Ok(false);
        }
        let attributes: Vec<String> = values_of(&index, "attributes").iter().map(interpolate).collect();
        let kind = attr(&index, "type").map(interpolate).unwrap_or_default();
        self.create_index_impl(
            &id,
            &new,
            &kind,
            &attributes,
            &values_of(&index, "lengths"),
            &values_of(&index, "orders"),
        )
    }

    /// `getSchemaAttributes($collection)` (SQLite): the table's columns from
    /// `PRAGMA table_info`, shaped like MariaDB's `INFORMATION_SCHEMA` rows.
    pub(super) fn schema_attributes_impl(&self, collection: &str) -> Result<Vec<Document>> {
        let table = format!("{}_{}", self.namespace_of_state(), filter(collection));
        let rows = self.run(&Statement::new(format!("PRAGMA table_info(`{table}`)"))).map_err(sql::raw)?.rows;
        let mut out = Vec::with_capacity(rows.len());
        for row in rows {
            let raw = row.get_str("type").map(interpolate).unwrap_or_default();
            let parsed = self.parse_column_type(&raw)?;
            let null_or = |v: Option<String>| v.map_or(Value::Null, |s| Value::Str(s.into()));
            let empty = |k: &str| row.get_str(k).is_none_or(is_empty);
            let pairs: Vec<(&str, Value)> = vec![
                ("$id", row.get_str("name").cloned().unwrap_or(Value::Null)),
                ("columnDefault", row.get_str("dflt_value").cloned().unwrap_or(Value::Null)),
                ("isNullable", Value::Str(if empty("notnull") { "YES" } else { "NO" }.into())),
                ("dataType", Value::Str(parsed.data_type.into())),
                ("characterMaximumLength", null_or(parsed.character_maximum_length)),
                ("numericPrecision", null_or(parsed.numeric_precision)),
                ("numericScale", null_or(parsed.numeric_scale)),
                ("datetimePrecision", null_or(parsed.datetime_precision)),
                ("columnType", Value::Str(raw.to_lowercase().into())),
                ("columnKey", Value::Str(if empty("pk") { "" } else { "PRI" }.into())),
                ("extra", Value::Str(Str::EMPTY)),
            ];
            out.push(Document::from_pairs(pairs)?);
        }
        Ok(out)
    }

    /// `parseSqliteColumnType($declaration)`.
    fn parse_column_type(&self, declaration: &str) -> Result<ColumnType> {
        let squeezed = preg_replace(br"/\s+/", b" ", declaration.as_bytes());
        let declaration = php_std::string::trim(&squeezed, b" \n\r\t\x0B\0").to_vec();
        let mut base = declaration.clone();
        let mut argument: Option<i64> = None;
        let mut second: Option<i64> = None;
        if let Ok(r) = pcre::preg_match(br"/^([A-Za-z]+)\s*\((\d+)(?:\s*,\s*(\d+))?\s*\)/", &declaration, 0, 0)
            && matches!(r.value.result, pcre::Value::Int(1))
            && let Some(m) = r.value.matches
        {
            let group = |i: i64| match m.get(&pcre::Key::Int(i)) {
                Some(pcre::Value::Str(s)) => Some(s.clone()),
                _ => None,
            };
            base = group(1).unwrap_or_default();
            argument = group(2).map(|s| php_std::format::str_to_int(&s));
            second = group(3).filter(|s| !s.is_empty()).map(|s| php_std::format::str_to_int(&s));
        }
        let emulate = self.emulate_mysql();
        let mut data_type = String::from_utf8_lossy(&base.to_ascii_lowercase()).into_owned();
        if emulate && data_type == "integer" {
            data_type = "int".to_owned();
        }
        let mut out = ColumnType { data_type: data_type.clone(), ..ColumnType::default() };
        match data_type.as_str() {
            "varchar" | "char" => out.character_maximum_length = argument.map(|a| a.to_string()),
            "datetime" | "timestamp" | "time" => out.datetime_precision = argument.map(|a| a.to_string()),
            "decimal" | "numeric" => {
                out.numeric_precision = argument.map(|a| a.to_string());
                if let Some(s) = second {
                    out.numeric_scale = Some(s.to_string());
                } else if emulate && argument.is_some() {
                    out.numeric_scale = Some("0".to_owned());
                }
            }
            _ => {}
        }
        if emulate {
            match data_type.as_str() {
                "text" => out.character_maximum_length = Some(Database::MAX_TEXT_BYTES.to_string()),
                "mediumtext" => out.character_maximum_length = Some(Database::MAX_MEDIUMTEXT_BYTES.to_string()),
                "longtext" | "json" => out.character_maximum_length = Some(Database::MAX_LONGTEXT_BYTES.to_string()),
                "tinyint" => out.numeric_precision = Some("3".to_owned()),
                "smallint" => out.numeric_precision = Some("5".to_owned()),
                "mediumint" => out.numeric_precision = Some("7".to_owned()),
                "int" | "integer" => out.numeric_precision = Some("10".to_owned()),
                "bigint" => out.numeric_precision = Some("19".to_owned()),
                "decimal" | "numeric" => {
                    if out.numeric_precision.is_none() {
                        out.numeric_precision = Some("10".to_owned());
                    }
                }
                "float" => out.numeric_precision = Some("12".to_owned()),
                "double" => out.numeric_precision = Some("22".to_owned()),
                _ => {}
            }
        }
        Ok(out)
    }

    /// `getSchemaIndexes($collection)` (SQLite): the table's indexes from
    /// `PRAGMA index_list` / `index_info`, then its FTS5 tables.
    pub(super) async fn schema_indexes_impl(&self, collection: &str) -> Result<Vec<Document>> {
        let table = format!("{}_{}", self.namespace_of_state(), filter(collection));
        let indexes = self.run(&Statement::new(format!("PRAGMA index_list(`{table}`)"))).map_err(sql::raw)?.rows;
        let mut out = Vec::new();
        for index in indexes {
            let name = index.get_str("name").cloned().unwrap_or(Value::Null);
            let unique = index.get_str("unique").is_some_and(|v| !is_empty(v));
            let mut columns = self
                .run(&Statement::new(format!("PRAGMA index_info(`{}`)", interpolate(&name))))
                .map_err(sql::raw)?
                .rows;
            columns.sort_by_key(|c| c.get_str("seqno").map(super::pdo::to_long).unwrap_or(0));
            let names: Array = columns.iter().map(|c| c.get_str("name").cloned().unwrap_or(Value::Null)).collect();
            let lengths: Array = columns.iter().map(|_| Value::Null).collect();
            out.push(Document::from_pairs([
                ("$id", name.clone()),
                ("indexName", name),
                ("indexType", Value::Str("BTREE".into())),
                ("nonUnique", Value::Int(if unique { 0 } else { 1 })),
                ("columns", Value::Array(names)),
                ("lengths", Value::Array(lengths)),
            ])?);
        }
        out.extend(self.fulltext_schema_indexes(collection).map_err(Failure::raw)?);
        Ok(out)
    }

    /// `getFulltextSchemaIndexes($collection)`: an entry per FTS5 table,
    /// named by the index it serves when the metadata says so.
    fn fulltext_schema_indexes(&self, collection: &str) -> std::result::Result<Vec<Document>, Failure> {
        let tables = self.find_fulltext_tables(collection)?;
        if tables.is_empty() {
            return Ok(Vec::new());
        }
        let mut hash_to_id: Vec<(String, String)> = Vec::new();
        let metadata = Document::from_pairs([("$id", Value::Str(Database::METADATA.into()))])?;
        if let Ok(stored) = self.get_document_impl(&metadata, &Value::Str(collection.into()), &[], false)
            && !stored.is_empty()
        {
            for index in iterable_indexes(&stored) {
                let id = attr(&index, "$id").filter(|v| !matches!(v, Value::Null));
                let fulltext =
                    attr(&index, "type").is_some_and(|t| t.strict_eq(&Value::Str(Database::INDEX_FULLTEXT.into())));
                let Some(id) = id.filter(|_| fulltext) else { continue };
                let internal: Vec<String> = values_of(&index, "attributes")
                    .iter()
                    .map(|a| sql::internal_key(&interpolate(a)).to_owned())
                    .collect();
                if let Ok(table) = self.fulltext_table_name(collection, &internal) {
                    hash_to_id.push((table, filter(&interpolate(id))));
                }
            }
        }
        let mut entries = Vec::new();
        for table in tables {
            let rows = self.run(&Statement::new(format!("PRAGMA table_info(`{table}`)")))?.rows;
            let columns: Array = rows
                .iter()
                .filter_map(|r| r.get_str("name").map(interpolate).filter(|n| !n.is_empty()))
                .map(|n| Value::Str(n.into()))
                .collect();
            let lengths: Array = columns.values().map(|_| Value::Null).collect();
            let id = hash_to_id.iter().rev().find(|(t, _)| *t == table).map(|(_, i)| i.clone()).unwrap_or(table);
            entries.push(Document::from_pairs([
                ("$id", Value::Str(id.as_str().into())),
                ("indexName", Value::Str(id.into())),
                ("indexType", Value::Str("FULLTEXT".into())),
                ("nonUnique", Value::Int(1)),
                ("columns", Value::Array(columns)),
                ("lengths", Value::Array(lengths)),
            ])?);
        }
        Ok(entries)
    }
}

fn lock_fts(adapter: &Sqlite) -> std::sync::MutexGuard<'_, super::FtsTables> {
    adapter.fts_tables.lock().unwrap_or_else(|e| e.into_inner())
}

/// What `parseSqliteColumnType` returns.
#[derive(Default)]
struct ColumnType {
    data_type: String,
    character_maximum_length: Option<String>,
    numeric_precision: Option<String>,
    numeric_scale: Option<String>,
    datetime_precision: Option<String>,
}

/// `preg_replace($pattern, $replacement, $subject) ?? ''`.
fn preg_replace(pattern: &[u8], replacement: &[u8], subject: &[u8]) -> Vec<u8> {
    match pcre::preg_replace(
        &pcre::StrOrArray::Str(pattern),
        &pcre::StrOrArray::Str(replacement),
        &pcre::StrOrArray::Str(subject),
        -1,
    ) {
        Ok(p) => match p.value.result {
            pcre::Value::Str(s) => s,
            _ => Vec::new(),
        },
        Err(_) => Vec::new(),
    }
}

/// PHP's `empty($value)`.
fn is_empty(v: &Value) -> bool {
    match v {
        Value::Null => true,
        Value::Bool(b) => !b,
        Value::Int(i) => *i == 0,
        Value::Float(f) => *f == 0.0,
        Value::Str(s) => s.is_empty() || s.as_bytes() == b"0",
        Value::Array(a) => a.is_empty(),
        Value::Object(_) | Value::Ext(_) => false,
    }
}

/// `getAttributeWidth($collection)` (SQL, with MariaDB's 25-byte points).
pub(super) fn attribute_width(collection: &Document) -> Result<i64> {
    let mut total: i64 = 1067;
    let attributes: Vec<Value> = match collection.raw("attributes") {
        Some(Value::Array(a)) => a.values().cloned().collect(),
        _ => Vec::new(),
    };
    for attribute in &attributes {
        if attr(attribute, "array").is_some_and(|v| !matches!(v, Value::Null) && v.truthy()) {
            total += 20;
            continue;
        }
        let kind = attr(attribute, "type").map(interpolate).unwrap_or_default();
        let size = attr(attribute, "size").map(super::pdo::to_long).unwrap_or(0);
        total += match kind.as_str() {
            Database::VAR_ID => 8,
            Database::VAR_STRING => {
                if size > sql::MAX_VARCHAR_LENGTH {
                    20
                } else if size > 255 {
                    size * 4 + 2
                } else {
                    size * 4 + 1
                }
            }
            Database::VAR_VARCHAR => {
                if size > 255 {
                    size * 4 + 2
                } else {
                    size * 4 + 1
                }
            }
            Database::VAR_TEXT | Database::VAR_MEDIUMTEXT | Database::VAR_LONGTEXT => 20,
            Database::VAR_INTEGER => {
                if size >= 8 {
                    8
                } else {
                    4
                }
            }
            Database::VAR_BIGINT => 8,
            Database::VAR_FLOAT => 8,
            Database::VAR_BOOLEAN => 1,
            Database::VAR_RELATIONSHIP => Database::LENGTH_KEY * 4 + 1,
            Database::VAR_DATETIME => 7,
            Database::VAR_OBJECT => 20,
            Database::VAR_POINT => 25,
            Database::VAR_LINESTRING | Database::VAR_POLYGON => 20,
            Database::VAR_VECTOR => size * 4,
            _ => return Err(Error::database(format!("Unknown type: {kind}"))),
        };
    }
    Ok(total)
}

/// `getKeywords()` (SQLite): <https://www.sqlite.org/lang_keywords.html>.
pub(super) const KEYWORDS: &[&str] = &[
    "ABORT",
    "ACTION",
    "ADD",
    "AFTER",
    "ALL",
    "ALTER",
    "ALWAYS",
    "ANALYZE",
    "AND",
    "AS",
    "ASC",
    "ATTACH",
    "AUTOINCREMENT",
    "BEFORE",
    "BEGIN",
    "BETWEEN",
    "BY",
    "CASCADE",
    "CASE",
    "CAST",
    "CHECK",
    "COLLATE",
    "COLUMN",
    "COMMIT",
    "CONFLICT",
    "CONSTRAINT",
    "CREATE",
    "CROSS",
    "CURRENT",
    "CURRENT_DATE",
    "CURRENT_TIME",
    "CURRENT_TIMESTAMP",
    "DATABASE",
    "DEFAULT",
    "DEFERRABLE",
    "DEFERRED",
    "DELETE",
    "DESC",
    "DETACH",
    "DISTINCT",
    "DO",
    "DROP",
    "EACH",
    "ELSE",
    "END",
    "ESCAPE",
    "EXCEPT",
    "EXCLUDE",
    "EXCLUSIVE",
    "EXISTS",
    "EXPLAIN",
    "FAIL",
    "FILTER",
    "FIRST",
    "FOLLOWING",
    "FOR",
    "FOREIGN",
    "FROM",
    "FULL",
    "GENERATED",
    "GLOB",
    "GROUP",
    "GROUPS",
    "HAVING",
    "IF",
    "IGNORE",
    "IMMEDIATE",
    "IN",
    "INDEX",
    "INDEXED",
    "INITIALLY",
    "INNER",
    "INSERT",
    "INSTEAD",
    "INTERSECT",
    "INTO",
    "IS",
    "ISNULL",
    "JOIN",
    "KEY",
    "LAST",
    "LEFT",
    "LIKE",
    "LIMIT",
    "MATCH",
    "MATERIALIZED",
    "NATURAL",
    "NO",
    "NOT",
    "NOTHING",
    "NOTNULL",
    "NULL",
    "NULLS",
    "OF",
    "OFFSET",
    "ON",
    "OR",
    "ORDER",
    "OTHERS",
    "OUTER",
    "OVER",
    "PARTITION",
    "PLAN",
    "PRAGMA",
    "PRECEDING",
    "PRIMARY",
    "QUERY",
    "RAISE",
    "RANGE",
    "RECURSIVE",
    "REFERENCES",
    "REGEXP",
    "REINDEX",
    "RELEASE",
    "RENAME",
    "REPLACE",
    "RESTRICT",
    "RETURNING",
    "RIGHT",
    "ROLLBACK",
    "ROW",
    "ROWS",
    "SAVEPOINT",
    "SELECT",
    "SET",
    "TABLE",
    "TEMP",
    "TEMPORARY",
    "THEN",
    "TIES",
    "TO",
    "TRANSACTION",
    "TRIGGER",
    "UNBOUNDED",
    "UNION",
    "UNIQUE",
    "UPDATE",
    "USING",
    "VACUUM",
    "VALUES",
    "VIEW",
    "VIRTUAL",
    "WHEN",
    "WHERE",
    "WINDOW",
    "WITH",
    "WITHOUT",
];
