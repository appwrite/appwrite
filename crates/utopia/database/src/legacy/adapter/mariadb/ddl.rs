//! Databases, tables, columns, relationship columns and indexes, and the
//! schema as the server reports it.
//!
//! PHP: `MariaDB::create`, `delete`, `createCollection`,
//! `deleteCollection`, `analyzeCollection`, `getSizeOfCollection(OnDisk)`
//! (and `MySQL::getSizeOfCollectionOnDisk`), `getSchemaAttributes`,
//! `getSchemaIndexes`, `updateAttribute`, `createRelationship`,
//! `updateRelationship`, `deleteRelationship`, `createIndex`,
//! `deleteIndex`, `renameIndex`, and `SQL::exists`, `SQL::createAttribute`,
//! `SQL::createAttributes`, `SQL::renameAttribute`, `SQL::deleteAttribute`,
//! `SQL::getAttributeWidth`, `SQL::getKeywords`.

use super::MariaDB;
use super::pdo::{Executed, ParamType, PdoFailure, Statement};
use super::sql::{self, filter, interpolate, quote};
use crate::database::Database;
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::value::{Array, ArrayKey, Ext, Str, Value};

/// PHP's `empty($value)`.
pub(super) fn is_empty(v: &Value) -> bool {
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

/// The value of an attribute of an attribute document or array
/// (`$attribute['key']` / `getAttribute('key')`).
fn attr<'a>(attribute: &'a Value, key: &str) -> Option<&'a Value> {
    match attribute {
        Value::Ext(Ext::Document(d)) => d.raw(key),
        Value::Array(a) => a.get_str(key),
        _ => None,
    }
}

/// `$array[$i]` of a list attribute (`null` when absent).
fn nth(list: Option<&Value>, i: &ArrayKey) -> Value {
    match list {
        Some(Value::Array(a)) => a.get(i).cloned().unwrap_or(Value::Null),
        _ => Value::Null,
    }
}

/// `'(' . (int)$length . ')'`, or `''` for an empty length.
fn length_sql(length: &Value) -> String {
    if is_empty(length) { String::new() } else { format!("({})", super::pdo::to_long(length)) }
}

impl MariaDB {
    /// `create($name)`: `true` when the database already exists.
    pub(super) async fn create_database(&self, name: &str) -> Result<bool> {
        let name = filter(name);
        if self.exists_impl(&name, None).await? {
            return Ok(true);
        }
        let sql = self.trigger_sql(
            Database::EVENT_DATABASE_CREATE,
            format!("CREATE DATABASE `{name}` /*!40100 DEFAULT CHARACTER SET utf8mb4 */;"),
        );
        self.run(&Statement::new(sql)).await.map(|r| r.ok).map_err(sql::raw)
    }

    /// `exists($database, $collection)` (`SQL::exists`).
    pub(super) async fn exists_impl(&self, database: &str, collection: Option<&str>) -> Result<bool> {
        let database = filter(database);
        let mut stmt;
        match collection {
            Some(c) => {
                let c = filter(c);
                stmt = Statement::new(
                    "\n                SELECT TABLE_NAME \n                FROM INFORMATION_SCHEMA.TABLES \n                WHERE TABLE_SCHEMA = :schema \n                  AND TABLE_NAME = :table\n            ",
                );
                stmt.bind(":schema", Value::Str(database.as_str().into()), ParamType::Str);
                let table = format!("{}_{c}", self.state.lock().namespace);
                stmt.bind(":table", Value::Str(table.into()), ParamType::Str);
            }
            None => {
                stmt = Statement::new(
                    "\n                SELECT SCHEMA_NAME FROM\n                INFORMATION_SCHEMA.SCHEMATA\n                WHERE SCHEMA_NAME = :schema\n            ",
                );
                stmt.bind(":schema", Value::Str(database.as_str().into()), ParamType::Str);
            }
        }
        match self.run(&stmt).await {
            Ok(r) => Ok(!r.rows.is_empty()),
            Err(e) => {
                let e = self.processed(e);
                if e.kind() == Kind::NotFound {
                    return Ok(false);
                }
                Err(e)
            }
        }
    }

    /// `delete($name)`: drops the database.
    pub(super) async fn delete_database(&self, name: &str) -> Result<bool> {
        let name = filter(name);
        let sql = self.trigger_sql(Database::EVENT_DATABASE_DELETE, format!("DROP DATABASE `{name}`;"));
        self.run(&Statement::new(sql)).await.map(|r| r.ok).map_err(sql::raw)
    }

    /// `createCollection($name, $attributes, $indexes)`: the table with its
    /// columns and indexes, and its permissions table.
    pub(super) async fn create_collection_impl(
        &self,
        name: &str,
        attributes: &[Document],
        indexes: &[Document],
    ) -> Result<bool> {
        let id = filter(name);
        let shared = self.shared();
        let mysql = self.mysql();

        let mut attribute_strings: Vec<String> = Vec::new();
        // $hash: filtered attribute id => attribute.
        let mut hash: Vec<(String, &Document)> = Vec::new();
        for attribute in attributes {
            let attr_id = filter(attribute.id());
            match hash.iter_mut().find(|(k, _)| *k == attr_id) {
                Some(slot) => slot.1 = attribute,
                None => hash.push((attr_id.clone(), attribute)),
            }
            let kind = text_attr(attribute, "type");
            let attr_type = sql::sql_type(
                &kind,
                int_attr(attribute, "size", 0),
                bool_attr(attribute, "signed", true),
                bool_attr(attribute, "array", false),
                bool_attr(attribute, "required", false),
                mysql,
            )?;
            if kind == Database::VAR_RELATIONSHIP {
                let options = match attribute.get("options") {
                    Some(Value::Array(a)) => a.clone(),
                    _ => Array::new(),
                };
                let text = |k: &str| match options.get_str(k) {
                    Some(Value::Str(s)) => Some(String::from_utf8_lossy(s.as_bytes()).into_owned()),
                    _ => None,
                };
                let relation = text("relationType");
                let two_way = options.get_str("twoWay").is_some_and(|v| !matches!(v, Value::Null) && v.truthy());
                let side = text("side");
                let is = |v: &Option<String>, s: &str| v.as_deref() == Some(s);
                if is(&relation, Database::RELATION_MANY_TO_MANY)
                    || (is(&relation, Database::RELATION_ONE_TO_ONE)
                        && !two_way
                        && is(&side, Database::RELATION_SIDE_CHILD))
                    || (is(&relation, Database::RELATION_ONE_TO_MANY) && is(&side, Database::RELATION_SIDE_PARENT))
                    || (is(&relation, Database::RELATION_MANY_TO_ONE) && is(&side, Database::RELATION_SIDE_CHILD))
                {
                    continue;
                }
            }
            attribute_strings.push(format!("`{attr_id}` {attr_type}, "));
        }

        let mut index_strings: Vec<String> = Vec::new();
        for index in indexes {
            let index_id = filter(index.id());
            let index_type = text_attr(index, "type");
            let lengths = index.get("lengths");
            let orders = index.get("orders");
            let mut columns: Vec<String> = Vec::new();
            if let Some(Value::Array(index_attributes)) = index.get("attributes") {
                for (nested, attribute) in index_attributes.iter() {
                    let nested = nested.to_owned();
                    let length = length_sql(&nth(lengths, &nested));
                    let order = nth(orders, &nested);
                    if index_type == Database::INDEX_SPATIAL
                        && !self.support_for_spatial_index_order_impl()
                        && !is_empty(&order)
                    {
                        return Err(Error::database(
                            "Spatial indexes with explicit orders are not supported. Remove the orders to create this index.",
                        ));
                    }
                    let index_attribute = filter(sql::internal_key(&interpolate(attribute)));
                    let order = if index_type == Database::INDEX_FULLTEXT || matches!(order, Value::Null) {
                        String::new()
                    } else {
                        interpolate(&order)
                    };
                    let mut column = format!("`{index_attribute}`{length} {order}");
                    let array = hash
                        .iter()
                        .find(|(k, _)| *k == index_attribute)
                        .and_then(|(_, a)| a.get("array"))
                        .is_some_and(|v| !is_empty(v));
                    if array && mysql {
                        column =
                            format!("(CAST(`{index_attribute}` AS char({}) ARRAY))", Database::MAX_ARRAY_INDEX_LENGTH);
                    }
                    columns.push(column);
                }
            }
            let mut list = columns.join(", ");
            if shared && index_type != Database::INDEX_FULLTEXT && index_type != Database::INDEX_SPATIAL {
                list = format!("_tenant, {list}");
            }
            index_strings.push(format!("{index_type} `{index_id}` ({list}),"));
        }

        let mut collection = format!(
            "\n\t\t\tCREATE TABLE {} (\n\t\t\t\t_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n\t\t\t\t_uid VARCHAR(255) NOT NULL,\n\t\t\t\t_createdAt DATETIME(3) DEFAULT NULL,\n\t\t\t\t_updatedAt DATETIME(3) DEFAULT NULL,\n\t\t\t\t_permissions MEDIUMTEXT DEFAULT NULL,\n\t\t\t\tPRIMARY KEY (_id),\n\t\t\t\t{}\n\t\t\t\t{}\n\t\t",
            self.sql_table(&id),
            attribute_strings.join(" "),
            index_strings.join(" ")
        );
        if shared {
            collection.push_str("\n            \t_tenant INT(11) UNSIGNED DEFAULT NULL,\n\t\t\t\tUNIQUE KEY _uid (_uid, _tenant),\n\t\t\t\tKEY _created_at (_tenant, _createdAt),\n\t\t\t\tKEY _updated_at (_tenant, _updatedAt),\n\t\t\t\tKEY _tenant_id (_tenant, _id)\n\t\t\t");
        } else {
            collection.push_str("\n\t\t\t\tUNIQUE KEY _uid (_uid),\n\t\t\t\tKEY _created_at (_createdAt),\n\t\t\t\tKEY _updated_at (_updatedAt)\n\t\t\t");
        }
        collection.push(')');
        let collection = self.trigger_sql(Database::EVENT_COLLECTION_CREATE, collection);

        let mut permissions = format!(
            "\n            CREATE TABLE {} (\n                _id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n                _type VARCHAR(12) NOT NULL,\n                _permission VARCHAR(255) NOT NULL,\n                _document VARCHAR(255) NOT NULL,\n                PRIMARY KEY (_id),\n        ",
            self.sql_table(&format!("{id}_perms"))
        );
        if shared {
            permissions.push_str("\n                _tenant INT(11) UNSIGNED DEFAULT NULL,\n                UNIQUE INDEX _index1 (_document, _tenant, _type, _permission),\n                INDEX _permission (_tenant, _permission, _type)\n            ");
        } else {
            permissions.push_str("\n                UNIQUE INDEX _index1 (_document, _type, _permission),\n                INDEX _permission (_permission, _type)\n            ");
        }
        permissions.push(')');
        let permissions = self.trigger_sql(Database::EVENT_COLLECTION_CREATE, permissions);

        for statement in [collection, permissions] {
            self.run(&Statement::new(statement)).await.map_err(|e| self.processed(e))?;
        }
        Ok(true)
    }

    /// `getSupportForSpatialIndexOrder()`.
    fn support_for_spatial_index_order_impl(&self) -> bool {
        !self.mysql()
    }

    /// `deleteCollection($id)`: drops the table and its permissions table.
    pub(super) async fn delete_collection_impl(&self, id: &str) -> Result<bool> {
        let id = filter(id);
        let sql = format!("DROP TABLE {}, {};", self.sql_table(&id), self.sql_table(&format!("{id}_perms")));
        let sql = self.trigger_sql(Database::EVENT_COLLECTION_DELETE, sql);
        self.run(&Statement::new(sql)).await.map(|r| r.ok).map_err(|e| self.processed(e))
    }

    /// `analyzeCollection($collection)`: `ANALYZE TABLE`.
    pub(super) async fn analyze_collection_impl(&self, collection: &str) -> Result<bool> {
        let name = filter(collection);
        let sql = format!("ANALYZE TABLE {}", self.sql_table(&name));
        self.run(&Statement::new(sql)).await.map(|r| r.ok).map_err(sql::raw)
    }

    /// `getSizeOfCollection($collection)`: data and index length of the
    /// table and its permissions table.
    pub(super) async fn collection_size(&self, collection: &str) -> Result<i64> {
        let collection = filter(collection);
        let (database, namespace) = {
            let s = self.state.lock();
            (s.database.clone(), s.namespace.clone())
        };
        let collection = format!("{namespace}_{collection}");
        let permissions = format!("{collection}_perms");
        let mut stmt = Statement::new(
            "\n            SELECT SUM(size) FROM (\n                SELECT data_length + index_length AS size\n                FROM INFORMATION_SCHEMA.TABLES\n                WHERE table_name = :name AND\n                table_schema = :database_name\n                UNION ALL\n                SELECT data_length + index_length AS size\n                FROM INFORMATION_SCHEMA.TABLES\n                WHERE table_name = :permissions AND\n                table_schema = :database_permissions\n            ) AS sizes\n        ",
        );
        stmt.bind_str(":name", Value::Str(collection.into()));
        stmt.bind_str(":permissions", Value::Str(permissions.into()));
        stmt.bind_str(":database_name", Value::Str(database.as_str().into()));
        stmt.bind_str(":database_permissions", Value::Str(database.into()));
        let r = self.run(&stmt).await.map_err(size_failure)?;
        Ok(r.column().map(|v| super::pdo::to_long(&v)).unwrap_or(0))
    }

    /// `getSizeOfCollectionOnDisk($collection)`: the InnoDB tablespaces of
    /// the table and its permissions table.
    pub(super) async fn collection_size_on_disk(&self, collection: &str) -> Result<i64> {
        let collection = filter(collection);
        let (database, namespace) = {
            let s = self.state.lock();
            (s.database.clone(), s.namespace.clone())
        };
        let collection = format!("{namespace}_{collection}");
        let name = format!("{database}/{collection}");
        let permissions = format!("{database}/{collection}_perms");
        let (a, b) = if self.mysql() {
            (
                "\n             SELECT SUM(FS_BLOCK_SIZE + ALLOCATED_SIZE)  \n             FROM INFORMATION_SCHEMA.INNODB_TABLESPACES\n             WHERE NAME = :name\n        ",
                "\n             SELECT SUM(FS_BLOCK_SIZE + ALLOCATED_SIZE)  \n             FROM INFORMATION_SCHEMA.INNODB_TABLESPACES\n             WHERE NAME = :permissions\n        ",
            )
        } else {
            (
                "\n            SELECT SUM(FS_BLOCK_SIZE + ALLOCATED_SIZE)  \n            FROM INFORMATION_SCHEMA.INNODB_SYS_TABLESPACES\n            WHERE NAME = :name\n         ",
                "\n            SELECT SUM(FS_BLOCK_SIZE + ALLOCATED_SIZE)  \n            FROM INFORMATION_SCHEMA.INNODB_SYS_TABLESPACES\n            WHERE NAME = :permissions\n        ",
            )
        };
        let mut first = Statement::new(a);
        first.bind_str(":name", Value::Str(name.into()));
        let mut second = Statement::new(b);
        second.bind_str(":permissions", Value::Str(permissions.into()));
        let first = self.run(&first).await.map_err(size_failure)?;
        let second = self.run(&second).await.map_err(size_failure)?;
        let column = |r: &Executed| r.column().unwrap_or(Value::Bool(false));
        let sum = php_std::format::add(number(&column(&first)), number(&column(&second)));
        Ok(match sum {
            php_std::value::Number::Int(i) => i,
            php_std::value::Number::Float(f) => php_std::format::dval_to_lval(f),
        })
    }

    /// `getSchemaAttributes($collection)`: the columns as the server's
    /// `INFORMATION_SCHEMA.COLUMNS` reports them.
    pub(super) async fn schema_attributes_impl(&self, collection: &str) -> Result<Vec<Document>> {
        let (schema, namespace) = {
            let s = self.state.lock();
            (s.database.clone(), s.namespace.clone())
        };
        let table = format!("{namespace}_{}", filter(collection));
        let mut stmt = Statement::new(
            "\n                SELECT\n                COLUMN_NAME as _id,\n                COLUMN_DEFAULT as columnDefault,\n                IS_NULLABLE as isNullable,\n                DATA_TYPE as dataType,\n                CHARACTER_MAXIMUM_LENGTH as characterMaximumLength,\n                NUMERIC_PRECISION as numericPrecision,\n                NUMERIC_SCALE as numericScale,\n                DATETIME_PRECISION as datetimePrecision,\n                COLUMN_TYPE as columnType,\n                COLUMN_KEY as columnKey,\n                EXTRA as extra\n                FROM INFORMATION_SCHEMA.COLUMNS\n                WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table\n            ",
        );
        stmt.bind_str(":schema", Value::Str(schema.into()));
        stmt.bind_str(":table", Value::Str(table.into()));
        let rows = self.run(&stmt).await.map_err(|_| Error::database("Failed to get schema attributes"))?.rows;
        let mut out = Vec::with_capacity(rows.len());
        for mut row in rows {
            let id = row.get_str("_id").cloned().unwrap_or(Value::Null);
            row.set(ArrayKey::from("$id"), id);
            row.remove(&ArrayKey::from("_id"));
            out.push(Document::new(row)?);
        }
        Ok(out)
    }

    /// `getSchemaIndexes($collection)`: the indexes as the server's
    /// `INFORMATION_SCHEMA.STATISTICS` reports them, one document each.
    pub(super) async fn schema_indexes_impl(&self, collection: &str) -> Result<Vec<Document>> {
        let (schema, namespace) = {
            let s = self.state.lock();
            (s.database.clone(), s.namespace.clone())
        };
        let table = format!("{namespace}_{}", filter(collection));
        let mut stmt = Statement::new(
            "\n                SELECT\n                    INDEX_NAME as indexName,\n                    COLUMN_NAME as columnName,\n                    NON_UNIQUE as nonUnique,\n                    SEQ_IN_INDEX as seqInIndex,\n                    INDEX_TYPE as indexType,\n                    SUB_PART as subPart\n                FROM INFORMATION_SCHEMA.STATISTICS\n                WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table\n                ORDER BY INDEX_NAME, SEQ_IN_INDEX\n            ",
        );
        stmt.bind_str(":schema", Value::Str(schema.into()));
        stmt.bind_str(":table", Value::Str(table.into()));
        let rows = self.run(&stmt).await.map_err(|_| Error::database("Failed to get schema indexes"))?.rows;
        // $grouped[$name], in first-seen order.
        let mut grouped: Vec<(ArrayKey, Array)> = Vec::new();
        for row in rows {
            let name = row.get_str("indexName").cloned().unwrap_or(Value::Null);
            let key = ArrayKey::normalize(name.to_php_string().unwrap_or(Str::EMPTY));
            let slot = match grouped.iter().position(|(k, _)| *k == key) {
                Some(i) => i,
                None => {
                    let mut index = Array::new();
                    index.set(ArrayKey::from("$id"), name.clone());
                    index.set(ArrayKey::from("indexName"), name.clone());
                    index.set(ArrayKey::from("indexType"), row.get_str("indexType").cloned().unwrap_or(Value::Null));
                    index.set(
                        ArrayKey::from("nonUnique"),
                        Value::Int(super::pdo::to_long(row.get_str("nonUnique").unwrap_or(&Value::Null))),
                    );
                    index.set(ArrayKey::from("columns"), Value::Array(Array::new()));
                    index.set(ArrayKey::from("lengths"), Value::Array(Array::new()));
                    grouped.push((key, index));
                    grouped.len() - 1
                }
            };
            let index = &mut grouped[slot].1;
            let column = row.get_str("columnName").cloned().unwrap_or(Value::Null);
            let length = match row.get_str("subPart") {
                None | Some(Value::Null) => Value::Null,
                Some(v) => Value::Int(super::pdo::to_long(v)),
            };
            for (key, value) in [("columns", column), ("lengths", length)] {
                if let Some(Value::Array(list)) = index.get_mut(&ArrayKey::from(key)) {
                    let _ = list.push(value);
                }
            }
        }
        grouped.into_iter().map(|(_, index)| Document::new(index)).collect()
    }

    /// `createAttribute(...)` (`SQL::createAttribute`): `ALTER TABLE ... ADD
    /// COLUMN`.
    #[allow(clippy::too_many_arguments)]
    pub(super) async fn create_attribute_impl(
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
        let ty = sql::sql_type(kind, size, signed, array, required, self.mysql())?;
        let sql = format!("ALTER TABLE {} ADD COLUMN {id} {ty} {};", self.sql_table(collection), self.lock_type());
        let sql = self.trigger_sql(Database::EVENT_ATTRIBUTE_CREATE, sql);
        self.run(&Statement::new(sql)).await.map(|r| r.ok).map_err(|e| self.processed(e))
    }

    /// `createAttributes($collection, $attributes)` (`SQL::createAttributes`):
    /// one `ALTER TABLE` adding every column.
    pub(super) async fn create_attributes_impl(&self, collection: &str, attributes: &[Array]) -> Result<bool> {
        let mut parts = Vec::with_capacity(attributes.len());
        for attribute in attributes {
            let get = |k: &str| attribute.get_str(k).filter(|v| !matches!(v, Value::Null));
            let id = quote(&filter(&get("$id").map(interpolate).unwrap_or_default()));
            let ty = sql::sql_type(
                &get("type").map(interpolate).unwrap_or_default(),
                get("size").map(super::pdo::to_long).unwrap_or(0),
                get("signed").is_none_or(Value::truthy),
                get("array").is_some_and(Value::truthy),
                get("required").is_some_and(Value::truthy),
                self.mysql(),
            )?;
            parts.push(format!("{id} {ty}"));
        }
        let columns = parts.join(", ADD COLUMN ");
        let sql = format!("ALTER TABLE {} ADD COLUMN {columns} {};", self.sql_table(collection), self.lock_type());
        let sql = self.trigger_sql(Database::EVENT_ATTRIBUTE_CREATE, sql);
        self.run(&Statement::new(sql)).await.map(|r| r.ok).map_err(|e| self.processed(e))
    }

    /// `updateAttribute(...)` (MariaDB): `CHANGE COLUMN` to a new key, or
    /// `MODIFY` in place.
    #[allow(clippy::too_many_arguments)]
    pub(super) async fn update_attribute_impl(
        &self,
        collection: &str,
        id: &str,
        kind: &str,
        size: i64,
        signed: bool,
        array: bool,
        new_key: Option<&str>,
        required: bool,
    ) -> Result<bool> {
        let name = filter(collection);
        let id = filter(id);
        let new_key = match new_key {
            Some(k) if !(k.is_empty() || k == "0") => Some(filter(k)),
            _ => None,
        };
        let ty = sql::sql_type(kind, size, signed, array, required, self.mysql())?;
        let sql = match new_key.filter(|k| !(k.is_empty() || k == "0")) {
            Some(new_key) => {
                format!("ALTER TABLE {} CHANGE COLUMN `{id}` `{new_key}` {ty};", self.sql_table(&name))
            }
            None => format!("ALTER TABLE {} MODIFY `{id}` {ty};", self.sql_table(&name)),
        };
        let sql = self.trigger_sql(Database::EVENT_ATTRIBUTE_UPDATE, sql);
        self.run(&Statement::new(sql)).await.map(|r| r.ok).map_err(|e| self.processed(e))
    }

    /// `deleteAttribute($collection, $id)` (`SQL::deleteAttribute`).
    pub(super) async fn delete_attribute_impl(&self, collection: &str, id: &str) -> Result<bool> {
        let id = quote(&filter(id));
        let sql = format!("ALTER TABLE {} DROP COLUMN {id};", self.sql_table(collection));
        let sql = self.trigger_sql(Database::EVENT_ATTRIBUTE_DELETE, sql);
        self.run(&Statement::new(sql)).await.map(|r| r.ok).map_err(|e| self.processed(e))
    }

    /// `renameAttribute($collection, $old, $new)` (`SQL::renameAttribute`).
    pub(super) async fn rename_attribute_impl(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        let collection = filter(collection);
        let old = quote(&filter(old));
        let new = quote(&filter(new));
        let sql = format!("ALTER TABLE {} RENAME COLUMN {old} TO {new};", self.sql_table(&collection));
        let sql = self.trigger_sql(Database::EVENT_ATTRIBUTE_UPDATE, sql);
        self.run(&Statement::new(sql)).await.map(|r| r.ok).map_err(|e| self.processed(e))
    }

    /// `createRelationship(...)`: the relationship's key columns.
    pub(super) async fn create_relationship_impl(
        &self,
        collection: &str,
        related_collection: &str,
        kind: &str,
        two_way: bool,
        id: &str,
        two_way_key: &str,
    ) -> Result<bool> {
        let table = self.sql_table(&filter(collection));
        let related_table = self.sql_table(&filter(related_collection));
        let id = filter(id);
        let two_way_key = filter(two_way_key);
        let ty = sql::sql_type(Database::VAR_RELATIONSHIP, 0, false, false, false, self.mysql())?;
        let sql = match kind {
            Database::RELATION_ONE_TO_ONE => {
                let mut sql = format!("ALTER TABLE {table} ADD COLUMN `{id}` {ty} DEFAULT NULL;");
                if two_way {
                    sql.push_str(&format!("ALTER TABLE {related_table} ADD COLUMN `{two_way_key}` {ty} DEFAULT NULL;"));
                }
                sql
            }
            Database::RELATION_ONE_TO_MANY => {
                format!("ALTER TABLE {related_table} ADD COLUMN `{two_way_key}` {ty} DEFAULT NULL;")
            }
            Database::RELATION_MANY_TO_ONE => format!("ALTER TABLE {table} ADD COLUMN `{id}` {ty} DEFAULT NULL;"),
            Database::RELATION_MANY_TO_MANY => return Ok(true),
            _ => return Err(Error::database("Invalid relationship type")),
        };
        let sql = self.trigger_sql(Database::EVENT_ATTRIBUTE_CREATE, sql);
        self.run(&Statement::new(sql)).await.map(|r| r.ok).map_err(sql::raw)
    }

    /// The junction table of two collections by their metadata documents'
    /// sequences (`getSQLTable('_' . $a->getSequence() . '_' . $b->getSequence())`).
    async fn junction_sequences(&self, collection: &str, related: &str) -> Result<(String, String)> {
        let metadata = Document::from_array_unchecked(
            [(ArrayKey::from("$id"), Value::Str(Database::METADATA.into()))].into_iter().collect(),
        );
        let a = self.get_document_impl(&metadata, &Value::Str(collection.into()), &[], false).await?;
        let b = self.get_document_impl(&metadata, &Value::Str(related.into()), &[], false).await?;
        let seq =
            |d: &Document| -> Result<String> { Ok(sql::sequence(d)?.map(|v| interpolate(&v)).unwrap_or_default()) };
        Ok((seq(&a)?, seq(&b)?))
    }

    /// `updateRelationship(...)`: renames the relationship's key columns.
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
        let related_table = self.sql_table(&filter(related_collection));
        let key = filter(key);
        let two_way_key = filter(two_way_key);
        let new_key = new_key.map(filter);
        let new_two_way_key = new_two_way_key.map(filter);
        let nk = new_key.clone().unwrap_or_default();
        let ntk = new_two_way_key.clone().unwrap_or_default();
        let mut sql = String::new();
        match kind {
            Database::RELATION_ONE_TO_ONE => {
                if Some(&key) != new_key.as_ref() {
                    sql = format!("ALTER TABLE {table} RENAME COLUMN `{key}` TO `{nk}`;");
                }
                if two_way && Some(&two_way_key) != new_two_way_key.as_ref() {
                    sql.push_str(&format!("ALTER TABLE {related_table} RENAME COLUMN `{two_way_key}` TO `{ntk}`;"));
                }
            }
            Database::RELATION_ONE_TO_MANY => {
                if side == Database::RELATION_SIDE_PARENT {
                    if Some(&two_way_key) != new_two_way_key.as_ref() {
                        sql = format!("ALTER TABLE {related_table} RENAME COLUMN `{two_way_key}` TO `{ntk}`;");
                    }
                } else if Some(&key) != new_key.as_ref() {
                    sql = format!("ALTER TABLE {table} RENAME COLUMN `{key}` TO `{nk}`;");
                }
            }
            Database::RELATION_MANY_TO_ONE => {
                if side == Database::RELATION_SIDE_CHILD {
                    if Some(&two_way_key) != new_two_way_key.as_ref() {
                        sql = format!("ALTER TABLE {related_table} RENAME COLUMN `{two_way_key}` TO `{ntk}`;");
                    }
                } else if Some(&key) != new_key.as_ref() {
                    sql = format!("ALTER TABLE {table} RENAME COLUMN `{key}` TO `{nk}`;");
                }
            }
            Database::RELATION_MANY_TO_MANY => {
                let (a, b) = self.junction_sequences(collection, related_collection).await?;
                let junction = self.sql_table(&format!("_{a}_{b}"));
                if new_key.is_some() {
                    sql = format!("ALTER TABLE {junction} RENAME COLUMN `{key}` TO `{nk}`;");
                }
                if two_way && new_two_way_key.is_some() {
                    sql.push_str(&format!("ALTER TABLE {junction} RENAME COLUMN `{two_way_key}` TO `{ntk}`;"));
                }
            }
            _ => return Err(Error::database("Invalid relationship type")),
        }
        if sql.is_empty() {
            return Ok(true);
        }
        let sql = self.trigger_sql(Database::EVENT_ATTRIBUTE_UPDATE, sql);
        self.run(&Statement::new(sql)).await.map(|r| r.ok).map_err(sql::raw)
    }

    /// `deleteRelationship(...)`: drops the relationship's key columns, or
    /// the junction tables of a many-to-many relationship.
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
        let related_table = self.sql_table(&filter(related_collection));
        let key = filter(key);
        let two_way_key = filter(two_way_key);
        let mut sql = String::new();
        match kind {
            Database::RELATION_ONE_TO_ONE => {
                if side == Database::RELATION_SIDE_PARENT {
                    sql = format!("ALTER TABLE {table} DROP COLUMN `{key}`;");
                    if two_way {
                        sql.push_str(&format!("ALTER TABLE {related_table} DROP COLUMN `{two_way_key}`;"));
                    }
                } else if side == Database::RELATION_SIDE_CHILD {
                    sql = format!("ALTER TABLE {related_table} DROP COLUMN `{two_way_key}`;");
                    if two_way {
                        sql.push_str(&format!("ALTER TABLE {table} DROP COLUMN `{key}`;"));
                    }
                }
            }
            Database::RELATION_ONE_TO_MANY => {
                sql = if side == Database::RELATION_SIDE_PARENT {
                    format!("ALTER TABLE {related_table} DROP COLUMN `{two_way_key}`;")
                } else {
                    format!("ALTER TABLE {table} DROP COLUMN `{key}`;")
                };
            }
            Database::RELATION_MANY_TO_ONE => {
                sql = if side == Database::RELATION_SIDE_PARENT {
                    format!("ALTER TABLE {table} DROP COLUMN `{key}`;")
                } else {
                    format!("ALTER TABLE {related_table} DROP COLUMN `{two_way_key}`;")
                };
            }
            Database::RELATION_MANY_TO_MANY => {
                let (a, b) = self.junction_sequences(collection, related_collection).await?;
                let (first, second) = if side == Database::RELATION_SIDE_PARENT { (a, b) } else { (b, a) };
                let junction = self.sql_table(&format!("_{first}_{second}"));
                let perms = self.sql_table(&format!("_{first}_{second}_perms"));
                sql = format!("DROP TABLE {junction}; DROP TABLE {perms}");
            }
            _ => return Err(Error::database("Invalid relationship type")),
        }
        if sql.is_empty() {
            return Ok(true);
        }
        let sql = self.trigger_sql(Database::EVENT_ATTRIBUTE_DELETE, sql);
        self.run(&Statement::new(sql)).await.map(|r| r.ok).map_err(sql::raw)
    }

    /// `createIndex(...)` (MariaDB): the index over the attributes of the
    /// collection's metadata, JSON arrays through a multi-valued `CAST(...
    /// ARRAY)` on MySQL.
    #[allow(clippy::too_many_arguments)]
    pub(super) async fn create_index_impl(
        &self,
        collection: &str,
        id: &str,
        kind: &str,
        attributes: &[String],
        lengths: &[Value],
        orders: &[Value],
        _attribute_types: &[(String, String)],
        _collation: &[String],
        _ttl: i64,
    ) -> Result<bool> {
        let metadata = Document::from_array_unchecked(
            [(ArrayKey::from("$id"), Value::Str(Database::METADATA.into()))].into_iter().collect(),
        );
        let collection = self.get_document_impl(&metadata, &Value::Str(collection.into()), &[], false).await?;
        if collection.is_empty() {
            return Err(Error::not_found("Collection not found"));
        }
        // json_decode($collection->getAttribute('attributes', []), true)
        let collection_attributes: Vec<Value> = match collection.get("attributes") {
            Some(Value::Str(s)) => {
                match php_std::json::decode(
                    s.as_bytes(),
                    Some(true),
                    php_std::json::DEFAULT_DEPTH,
                    php_std::json::Flags::NONE,
                )
                .map(crate::value::from_plain)
                {
                    Ok(Value::Array(a)) => a.values().cloned().collect(),
                    _ => Vec::new(),
                }
            }
            _ => Vec::new(),
        };
        let id = filter(id);
        let mysql = self.mysql();
        let mut columns = Vec::with_capacity(attributes.len());
        for (i, attr_name) in attributes.iter().enumerate() {
            let attribute = collection_attributes
                .iter()
                .find(|a| attr(a, "$id").is_some_and(|v| interpolate(v).eq_ignore_ascii_case(attr_name)));
            let order = match orders.get(i) {
                Some(v) if !is_empty(v) && kind != Database::INDEX_FULLTEXT => interpolate(v),
                _ => String::new(),
            };
            let length = length_sql(lengths.get(i).unwrap_or(&Value::Null));
            let attr_name = filter(sql::internal_key(attr_name));
            let mut column = format!("`{attr_name}`{length} {order}");
            if mysql && attribute.and_then(|a| attr(a, "array")).is_some_and(|v| !is_empty(v)) {
                column = format!("(CAST(`{attr_name}` AS char({}) ARRAY))", Database::MAX_ARRAY_INDEX_LENGTH);
            }
            columns.push(column);
        }
        let sql_type = match kind {
            Database::INDEX_KEY => "INDEX",
            Database::INDEX_UNIQUE => "UNIQUE INDEX",
            Database::INDEX_FULLTEXT => "FULLTEXT INDEX",
            Database::INDEX_SPATIAL => "SPATIAL INDEX",
            _ => {
                return Err(Error::database(format!(
                    "Unknown index type: {kind}. Must be one of {}, {}, {}, {}",
                    Database::INDEX_KEY,
                    Database::INDEX_UNIQUE,
                    Database::INDEX_FULLTEXT,
                    Database::INDEX_SPATIAL
                )));
            }
        };
        let mut list = columns.join(", ");
        if self.shared() && kind != Database::INDEX_FULLTEXT && kind != Database::INDEX_SPATIAL {
            list = format!("_tenant, {list}");
        }
        let sql = format!("CREATE {sql_type} `{id}` ON {} ({list})", self.sql_table(collection.id()));
        let sql = self.trigger_sql(Database::EVENT_INDEX_CREATE, sql);
        match self.run(&Statement::new(sql)).await {
            Ok(r) => Ok(r.ok),
            Err(PdoFailure::Pdo(e)) if e.sqlstate == "23000" && e.native == Some(1062) => {
                Err(Error::unique("Unique index violation"))
            }
            Err(e) => Err(self.processed(e)),
        }
    }

    /// `deleteIndex($collection, $id)`: an index that does not exist is no
    /// error.
    pub(super) async fn delete_index_impl(&self, collection: &str, id: &str) -> Result<bool> {
        let name = filter(collection);
        let id = filter(id);
        let sql = format!("ALTER TABLE {} DROP INDEX `{id}`;", self.sql_table(&name));
        let sql = self.trigger_sql(Database::EVENT_INDEX_DELETE, sql);
        match self.run(&Statement::new(sql)).await {
            Ok(r) => Ok(r.ok),
            Err(PdoFailure::Pdo(e)) if e.sqlstate == "42000" && e.native == Some(1091) => Ok(true),
            Err(e) => Err(sql::raw(e)),
        }
    }

    /// `renameIndex($collection, $old, $new)`.
    pub(super) async fn rename_index_impl(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        let collection = filter(collection);
        let old = filter(old);
        let new = filter(new);
        let sql = format!("ALTER TABLE {} RENAME INDEX `{old}` TO `{new}`;", self.sql_table(&collection));
        let sql = self.trigger_sql(Database::EVENT_INDEX_RENAME, sql);
        self.run(&Statement::new(sql)).await.map(|r| r.ok).map_err(sql::raw)
    }

    /// `getAttributeWidth($collection)`, failing on an unknown type as PHP
    /// does.
    ///
    /// PHP: `Utopia\Database\Adapter\SQL::getAttributeWidth`.
    pub fn try_attribute_width(&self, collection: &Document) -> Result<i64> {
        attribute_width(collection)
    }
}

/// `Failed to get collection size: ...`.
fn size_failure(e: PdoFailure) -> Error {
    match e {
        PdoFailure::Pdo(e) => Error::database(format!("Failed to get collection size: {}", e.message)),
        PdoFailure::Other(e) => e,
    }
}

fn number(v: &Value) -> php_std::value::Number {
    use php_std::value::Number;
    match v {
        Value::Int(i) => Number::Int(*i),
        Value::Float(f) => Number::Float(*f),
        Value::Bool(b) => Number::Int(i64::from(*b)),
        Value::Str(s) => php_std::value::numeric_bytes_ex(s.as_bytes()).map(|(n, _)| n).unwrap_or(Number::Int(0)),
        _ => Number::Int(0),
    }
}

/// The text of an attribute (`$document->getAttribute($key)` as a string).
fn text_attr(document: &Document, key: &str) -> String {
    document.get(key).map(interpolate).unwrap_or_default()
}

fn int_attr(document: &Document, key: &str, default: i64) -> i64 {
    document.get(key).map(super::pdo::to_long).unwrap_or(default)
}

fn bool_attr(document: &Document, key: &str, default: bool) -> bool {
    document.get(key).map(Value::truthy).unwrap_or(default)
}

/// `getMaxPointSize()` (MariaDB): a `POINT` takes 25 bytes.
const MAX_POINT_SIZE: i64 = 25;

/// `getAttributeWidth($collection)`.
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
            Database::VAR_POINT => MAX_POINT_SIZE,
            Database::VAR_LINESTRING | Database::VAR_POLYGON => 20,
            Database::VAR_VECTOR => size * 4,
            _ => return Err(Error::database(format!("Unknown type: {kind}"))),
        };
    }
    Ok(total)
}

/// `getKeywords()`.
pub(super) const KEYWORDS: &[&str] = &[
    "ACCESSIBLE",
    "ADD",
    "ALL",
    "ALTER",
    "ANALYZE",
    "AND",
    "AS",
    "ASC",
    "ASENSITIVE",
    "BEFORE",
    "BETWEEN",
    "BIGINT",
    "BINARY",
    "BLOB",
    "BOTH",
    "BY",
    "CALL",
    "CASCADE",
    "CASE",
    "CHANGE",
    "CHAR",
    "CHARACTER",
    "CHECK",
    "COLLATE",
    "COLUMN",
    "CONDITION",
    "CONSTRAINT",
    "CONTINUE",
    "CONVERT",
    "CREATE",
    "CROSS",
    "CURRENT_DATE",
    "CURRENT_ROLE",
    "CURRENT_TIME",
    "CURRENT_TIMESTAMP",
    "CURRENT_USER",
    "CURSOR",
    "DATABASE",
    "DATABASES",
    "DAY_HOUR",
    "DAY_MICROSECOND",
    "DAY_MINUTE",
    "DAY_SECOND",
    "DEC",
    "DECIMAL",
    "DECLARE",
    "DEFAULT",
    "DELAYED",
    "DELETE",
    "DELETE_DOMAIN_ID",
    "DESC",
    "DESCRIBE",
    "DETERMINISTIC",
    "DISTINCT",
    "DISTINCTROW",
    "DIV",
    "DO_DOMAIN_IDS",
    "DOUBLE",
    "DROP",
    "DUAL",
    "EACH",
    "ELSE",
    "ELSEIF",
    "ENCLOSED",
    "ESCAPED",
    "EXCEPT",
    "EXISTS",
    "EXIT",
    "EXPLAIN",
    "FALSE",
    "FETCH",
    "FLOAT",
    "FLOAT4",
    "FLOAT8",
    "FOR",
    "FORCE",
    "FOREIGN",
    "FROM",
    "FULLTEXT",
    "GENERAL",
    "GRANT",
    "GROUP",
    "HAVING",
    "HIGH_PRIORITY",
    "HOUR_MICROSECOND",
    "HOUR_MINUTE",
    "HOUR_SECOND",
    "IF",
    "IGNORE",
    "IGNORE_DOMAIN_IDS",
    "IGNORE_SERVER_IDS",
    "IN",
    "INDEX",
    "INFILE",
    "INNER",
    "INOUT",
    "INSENSITIVE",
    "INSERT",
    "INT",
    "INT1",
    "INT2",
    "INT3",
    "INT4",
    "INT8",
    "INTEGER",
    "INTERSECT",
    "INTERVAL",
    "INTO",
    "IS",
    "ITERATE",
    "JOIN",
    "KEY",
    "KEYS",
    "KILL",
    "LEADING",
    "LEAVE",
    "LEFT",
    "LIKE",
    "LIMIT",
    "LINEAR",
    "LINES",
    "LOAD",
    "LOCALTIME",
    "LOCALTIMESTAMP",
    "LOCK",
    "LONG",
    "LONGBLOB",
    "LONGTEXT",
    "LOOP",
    "LOW_PRIORITY",
    "MASTER_HEARTBEAT_PERIOD",
    "MASTER_SSL_VERIFY_SERVER_CERT",
    "MATCH",
    "MAXVALUE",
    "MEDIUMBLOB",
    "MEDIUMINT",
    "MEDIUMTEXT",
    "MIDDLEINT",
    "MINUTE_MICROSECOND",
    "MINUTE_SECOND",
    "MOD",
    "MODIFIES",
    "NATURAL",
    "NOT",
    "NO_WRITE_TO_BINLOG",
    "NULL",
    "NUMERIC",
    "OFFSET",
    "ON",
    "OPTIMIZE",
    "OPTION",
    "OPTIONALLY",
    "OR",
    "ORDER",
    "OUT",
    "OUTER",
    "OUTFILE",
    "OVER",
    "PAGE_CHECKSUM",
    "PARSE_VCOL_EXPR",
    "PARTITION",
    "POSITION",
    "PRECISION",
    "PRIMARY",
    "PROCEDURE",
    "PURGE",
    "RANGE",
    "READ",
    "READS",
    "READ_WRITE",
    "REAL",
    "RECURSIVE",
    "REF_SYSTEM_ID",
    "REFERENCES",
    "REGEXP",
    "RELEASE",
    "RENAME",
    "REPEAT",
    "REPLACE",
    "REQUIRE",
    "RESIGNAL",
    "RESTRICT",
    "RETURN",
    "RETURNING",
    "REVOKE",
    "RIGHT",
    "RLIKE",
    "ROWS",
    "SCHEMA",
    "SCHEMAS",
    "SECOND_MICROSECOND",
    "SELECT",
    "SENSITIVE",
    "SEPARATOR",
    "SET",
    "SHOW",
    "SIGNAL",
    "SLOW",
    "SMALLINT",
    "SPATIAL",
    "SPECIFIC",
    "SQL",
    "SQLEXCEPTION",
    "SQLSTATE",
    "SQLWARNING",
    "SQL_BIG_RESULT",
    "SQL_CALC_FOUND_ROWS",
    "SQL_SMALL_RESULT",
    "SSL",
    "STARTING",
    "STATS_AUTO_RECALC",
    "STATS_PERSISTENT",
    "STATS_SAMPLE_PAGES",
    "STRAIGHT_JOIN",
    "TABLE",
    "TERMINATED",
    "THEN",
    "TINYBLOB",
    "TINYINT",
    "TINYTEXT",
    "TO",
    "TRAILING",
    "TRIGGER",
    "TRUE",
    "UNDO",
    "UNION",
    "UNIQUE",
    "UNLOCK",
    "UNSIGNED",
    "UPDATE",
    "USAGE",
    "USE",
    "USING",
    "UTC_DATE",
    "UTC_TIME",
    "UTC_TIMESTAMP",
    "VALUES",
    "VARBINARY",
    "VARCHAR",
    "VARCHARACTER",
    "VARYING",
    "WHEN",
    "WHERE",
    "WHILE",
    "WINDOW",
    "WITH",
    "WRITE",
    "XOR",
    "YEAR_MONTH",
    "ZEROFILL",
    "ACTION",
    "BIT",
    "DATE",
    "ENUM",
    "NO",
    "TEXT",
    "TIME",
    "TIMESTAMP",
    "BODY",
    "ELSIF",
    "GOTO",
    "HISTORY",
    "MINUS",
    "OTHERS",
    "PACKAGE",
    "PERIOD",
    "RAISE",
    "ROWNUM",
    "ROWTYPE",
    "SYSDATE",
    "SYSTEM",
    "SYSTEM_TIME",
    "VERSIONING",
    "WITHOUT",
];
