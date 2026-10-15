//! Schemas, tables, columns, relationship columns and indexes.
//!
//! PHP: `Postgres::create`, `delete`, `createCollection`,
//! `deleteCollection`, `getSizeOfCollection(OnDisk)`, `createAttribute`,
//! `updateAttribute`, `deleteAttribute`, `renameAttribute`,
//! `createRelationship`, `updateRelationship`, `deleteRelationship`,
//! `createIndex`, `deleteIndex`, `renameIndex`, and `SQL::exists`,
//! `SQL::createAttributes`, `SQL::getAttributeWidth`, `SQL::getKeywords`.

use super::Postgres;
use super::pdo::{ParamType, Statement};
use super::sql::{self, filter, interpolate, quote, short_key};
use crate::database::Database;
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::value::{Array, Value};

/// PHP's `empty()` of a string argument: `""` and `"0"`.
fn empty_str(s: &str) -> bool {
    s.is_empty() || s == "0"
}

/// The value of an attribute of an attribute document or array
/// (`$attribute['key']` / `getAttribute('key')`).
fn attr<'a>(attribute: &'a Value, key: &str) -> Option<&'a Value> {
    match attribute {
        Value::Ext(crate::value::Ext::Document(d)) => d.raw(key),
        Value::Array(a) => a.get_str(key),
        _ => None,
    }
}

fn vector_checks(kind: &str, size: i64) -> Result<()> {
    if kind == Database::VAR_VECTOR {
        if size <= 0 {
            return Err(Error::database("Vector dimensions must be a positive integer"));
        }
        if size > Database::MAX_VECTOR_DIMENSIONS {
            return Err(Error::database(format!(
                "Vector dimensions cannot exceed {}",
                Database::MAX_VECTOR_DIMENSIONS
            )));
        }
    }
    Ok(())
}

impl Postgres {
    /// `create($name)`: the schema, then the extensions and the
    /// `utf8_ci_ai` collation; `true` when the schema already exists.
    pub(super) async fn create_database(&self, name: &str) -> Result<bool> {
        let name = filter(name);
        if self.exists_impl(&name, None).await? {
            return Ok(true);
        }
        let sql = self.trigger_sql(Database::EVENT_DATABASE_CREATE, format!("CREATE SCHEMA \"{name}\""));
        let created = self.run(&Statement::new(sql)).await.map_err(sql::raw)?.ok;
        for ext in [
            "CREATE EXTENSION IF NOT EXISTS postgis",
            "CREATE EXTENSION IF NOT EXISTS vector",
            "CREATE EXTENSION IF NOT EXISTS pg_trgm",
            "\n            CREATE COLLATION IF NOT EXISTS utf8_ci_ai (\n            provider = icu,\n            locale = 'und-u-ks-level1',\n            deterministic = false\n            )\n        ",
        ] {
            self.run(&Statement::new(ext)).await.map_err(sql::raw)?;
        }
        Ok(created)
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
                let e = sql::processed(e);
                if e.kind() == Kind::NotFound {
                    return Ok(false);
                }
                Err(e)
            }
        }
    }

    /// `delete($name)`: drops the schema and everything in it.
    pub(super) async fn delete_database(&self, name: &str) -> Result<bool> {
        let name = filter(name);
        let sql =
            self.trigger_sql(Database::EVENT_DATABASE_DELETE, format!("DROP SCHEMA IF EXISTS \"{name}\" CASCADE"));
        self.run(&Statement::new(sql)).await.map(|r| r.ok).map_err(sql::raw)
    }

    /// `createCollection($name, $attributes, $indexes)`.
    pub(super) async fn create_collection_impl(
        &self,
        name: &str,
        attributes: &[Document],
        indexes: &[Document],
    ) -> Result<bool> {
        let (namespace, tenant) = {
            let s = self.state.lock();
            (s.namespace.clone(), interpolate(&s.tenant))
        };
        let shared = self.shared();
        let id = filter(name);

        let mut attribute_strings: Vec<String> = Vec::new();
        for attribute in attributes {
            let attr_id = filter(attribute.id());
            let kind = text_attr(attribute, "type");
            let size = int_attr(attribute, "size", 0);
            let signed = bool_attr(attribute, "signed", true);
            let array = bool_attr(attribute, "array", false);
            let required = bool_attr(attribute, "required", false);
            let attr_type = sql::sql_type(&kind, size, signed, array, required)?;

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
            attribute_strings.push(format!("\"{attr_id}\" {attr_type}, "));
        }

        let table = self.sql_table(&id);
        let sql_tenant = if shared { "_tenant INTEGER DEFAULT NULL," } else { "" };
        let mut collection = format!(
            "\n            CREATE TABLE {table} (\n                _id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,\n                _uid VARCHAR(255) NOT NULL,\n                {sql_tenant}\n                \"_createdAt\" TIMESTAMP(3) DEFAULT NULL,\n                \"_updatedAt\" TIMESTAMP(3) DEFAULT NULL,\n                {}\n                _permissions JSONB DEFAULT NULL\n            );\n        ",
            attribute_strings.join(" ")
        );
        if shared {
            let uid = short_key(&format!("{namespace}_{tenant}_{id}_uid"));
            let created = short_key(&format!("{namespace}_{tenant}_{id}_created"));
            let updated = short_key(&format!("{namespace}_{tenant}_{id}_updated"));
            let tenant_id = short_key(&format!("{namespace}_{tenant}_{id}_tenant_id"));
            let permissions = short_key(&format!("{namespace}_{tenant}_{id}_permissions"));
            collection.push_str(&format!(
                "\n\t\t\t\tCREATE UNIQUE INDEX \"{uid}\" ON {table} (\"_uid\" COLLATE utf8_ci_ai, \"_tenant\");\n            \tCREATE INDEX \"{created}\" ON {table} (_tenant, \"_createdAt\");\n            \tCREATE INDEX \"{updated}\" ON {table} (_tenant, \"_updatedAt\");\n            \tCREATE INDEX \"{tenant_id}\" ON {table} (_tenant, _id);\n            \tCREATE INDEX \"{permissions}\" ON {table} USING gin (_permissions);\n\t\t\t"
            ));
        } else {
            let uid = short_key(&format!("{namespace}_{id}_uid"));
            let created = short_key(&format!("{namespace}_{id}_created"));
            let updated = short_key(&format!("{namespace}_{id}_updated"));
            let permissions = short_key(&format!("{namespace}_{id}_permissions"));
            collection.push_str(&format!(
                "\n\t\t\t\tCREATE UNIQUE INDEX \"{uid}\" ON {table} (\"_uid\" COLLATE utf8_ci_ai);\n            \tCREATE INDEX \"{created}\" ON {table} (\"_createdAt\");\n            \tCREATE INDEX \"{updated}\" ON {table} (\"_updatedAt\");\n            \tCREATE INDEX \"{permissions}\" ON {table} USING gin (_permissions);\n\t\t\t"
            ));
        }
        let collection = self.trigger_sql(Database::EVENT_COLLECTION_CREATE, collection);

        let perms = self.sql_table(&format!("{id}_perms"));
        let mut permissions = format!(
            "\n            CREATE TABLE {perms} (\n                _id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,\n                _tenant INTEGER DEFAULT NULL,\n                _type VARCHAR(12) NOT NULL,\n                _permission VARCHAR(255) NOT NULL,\n                _document VARCHAR(255) NOT NULL\n            );\n        "
        );
        if shared {
            let unique = short_key(&format!("{namespace}_{tenant}_{id}_ukey"));
            let index = short_key(&format!("{namespace}_{tenant}_{id}_permission"));
            permissions.push_str(&format!(
                "\n                CREATE UNIQUE INDEX \"{unique}\" \n                    ON {perms} USING btree (_tenant,_document,_type,_permission);\n                CREATE INDEX \"{index}\" \n                    ON {perms} USING btree (_tenant,_permission,_type); \n            "
            ));
        } else {
            let unique = short_key(&format!("{namespace}_{id}_ukey"));
            let index = short_key(&format!("{namespace}_{id}_permission"));
            permissions.push_str(&format!(
                "\n                CREATE UNIQUE INDEX \"{unique}\" \n                    ON {perms} USING btree (_document COLLATE utf8_ci_ai,_type,_permission);\n                CREATE INDEX \"{index}\" \n                    ON {perms} USING btree (_permission,_type); \n            "
            ));
        }
        let permissions = self.trigger_sql(Database::EVENT_COLLECTION_CREATE, permissions);

        for statement in [collection, permissions] {
            match self.run(&Statement::new(statement)).await {
                Ok(_) => {}
                Err(super::pdo::PdoFailure::Pdo(e)) => return self.failed_collection(&id, e).await,
                Err(super::pdo::PdoFailure::Other(e)) => return Err(e),
            }
        }

        for index in indexes {
            let index_id = filter(index.id());
            let index_type = text_attr(index, "type");
            let index_attributes: Vec<String> = match index.get("attributes") {
                Some(Value::Array(a)) => a.values().map(interpolate).collect(),
                _ => Vec::new(),
            };
            let mut with_type: Vec<(String, String)> = Vec::new();
            for index_attribute in &index_attributes {
                for attribute in attributes {
                    if attribute.id() == index_attribute {
                        let t = text_attr(attribute, "type");
                        match with_type.iter_mut().find(|(k, _)| k == index_attribute) {
                            Some(slot) => slot.1 = t,
                            None => with_type.push((index_attribute.clone(), t)),
                        }
                    }
                }
            }
            let orders: Vec<Value> = match index.get("orders") {
                Some(Value::Array(a)) => a.values().cloned().collect(),
                _ => Vec::new(),
            };
            let ttl = int_attr(index, "ttl", 0);
            if index_type == Database::INDEX_SPATIAL && !orders.is_empty() {
                return Err(Error::database(
                    "Spatial indexes with explicit orders are not supported. Remove the orders to create this index.",
                ));
            }
            match self
                .create_index_inner(&id, &index_id, &index_type, &index_attributes, &orders, &with_type, ttl)
                .await
            {
                Ok(_) => {}
                // createIndex() throws processException()'s result: only an
                // error it leaves a PDOException reaches the cleanup.
                Err(IndexFailure::Pdo(e)) => {
                    let mapped = sql::process_exception(e.clone());
                    if mapped.kind() != Kind::Pdo {
                        return Err(mapped);
                    }
                    return self.failed_collection(&id, e).await;
                }
                Err(IndexFailure::Other(e)) => return Err(e),
            }
        }
        Ok(true)
    }

    /// A driver error while creating a collection drops the half-made
    /// tables, unless the table already existed.
    async fn failed_collection(&self, id: &str, e: super::pdo::PdoError) -> Result<bool> {
        let e = sql::process_exception(e);
        if !e.is_a(Kind::Duplicate) {
            let drop =
                format!("DROP TABLE IF EXISTS {}, {};", self.sql_table(id), self.sql_table(&format!("{id}_perms")));
            self.run_timed(&Statement::new(drop)).await.map_err(sql::raw)?;
        }
        Err(e)
    }

    /// `deleteCollection($id)`: drops the table and its permissions table.
    pub(super) async fn delete_collection_impl(&self, id: &str) -> Result<bool> {
        let id = filter(id);
        let sql = format!("DROP TABLE {}, {}", self.sql_table(&id), self.sql_table(&format!("{id}_perms")));
        let sql = self.trigger_sql(Database::EVENT_COLLECTION_DELETE, sql);
        self.run(&Statement::new(sql)).await.map(|r| r.ok).map_err(sql::processed)
    }

    /// `getSizeOfCollection()` / `getSizeOfCollectionOnDisk()`: the size of
    /// the table plus its permissions table.
    pub(super) async fn collection_size(&self, collection: &str, function: &str) -> Result<i64> {
        let collection = filter(collection);
        let name = self.sql_table(&collection);
        let permissions = self.sql_table(&format!("{collection}_perms"));
        let mut a = Statement::new(format!("\n             SELECT {function}(:name);\n        "));
        a.bind(":name", Value::Str(name.into()), ParamType::Str);
        let mut b = Statement::new(format!("\n             SELECT {function}(:permissions);\n        "));
        b.bind(":permissions", Value::Str(permissions.into()), ParamType::Str);
        let fail = |e: super::pdo::PdoFailure| match e {
            super::pdo::PdoFailure::Pdo(e) => Error::database(format!("Failed to get collection size: {}", e.message)),
            super::pdo::PdoFailure::Other(e) => e,
        };
        let first = self.run_timed(&a).await.map_err(fail)?;
        let second = self.run_timed(&b).await.map_err(fail)?;
        let column = |r: &super::pdo::Executed| r.column().unwrap_or(Value::Bool(false));
        let sum = php_std::format::add(number(&column(&first)), number(&column(&second)));
        Ok(match sum {
            php_std::value::Number::Int(i) => i,
            php_std::value::Number::Float(f) => php_std::format::dval_to_lval(f),
        })
    }

    /// `createAttribute(...)`: `ALTER TABLE ... ADD COLUMN`.
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
        vector_checks(kind, size)?;
        let name = filter(collection);
        let id = filter(id);
        let ty = sql::sql_type(kind, size, signed, array, required)?;
        let sql = format!("\n\t\t\tALTER TABLE {}\n\t\t\tADD COLUMN \"{id}\" {ty}\n\t\t", self.sql_table(&name));
        let sql = self.trigger_sql(Database::EVENT_ATTRIBUTE_CREATE, sql);
        self.run_timed(&Statement::new(sql)).await.map(|r| r.ok).map_err(sql::processed)
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
            )?;
            parts.push(format!("{id} {ty}"));
        }
        let columns = parts.join(", ADD COLUMN ");
        let sql = format!("ALTER TABLE {} ADD COLUMN {columns} {};", self.sql_table(collection), self.lock_type());
        let sql = self.trigger_sql(Database::EVENT_ATTRIBUTE_CREATE, sql);
        self.run(&Statement::new(sql)).await.map(|r| r.ok).map_err(sql::processed)
    }

    /// `updateAttribute(...)`: renames the column when the key changes, then
    /// changes its type.
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
        let mut id = filter(id);
        let new_key = match new_key {
            Some(k) if !empty_str(k) => Some(filter(k)),
            _ => None,
        };
        vector_checks(kind, size)?;
        let mut ty = sql::sql_type(kind, size, signed, array, required)?;
        if ty == "TIMESTAMP(3)" {
            ty = format!("TIMESTAMP(3) without time zone USING TO_TIMESTAMP(\"{id}\", 'YYYY-MM-DD HH24:MI:SS.MS')");
        }
        if let Some(new_key) = new_key.filter(|k| !empty_str(k) && *k != id) {
            let new_key = filter(&new_key);
            let sql = format!(
                "\n                    ALTER TABLE {}\n                    RENAME COLUMN \"{id}\" TO \"{new_key}\"\n                ",
                self.sql_table(&name)
            );
            let sql = self.trigger_sql(Database::EVENT_ATTRIBUTE_UPDATE, sql);
            let result = self.run_timed(&Statement::new(sql)).await.map_err(sql::raw)?;
            if !result.ok {
                return Ok(false);
            }
            id = new_key;
        }
        let sql = format!(
            "\n                ALTER TABLE {}\n                ALTER COLUMN \"{id}\" TYPE {ty}\n            ",
            self.sql_table(&name)
        );
        let sql = self.trigger_sql(Database::EVENT_ATTRIBUTE_UPDATE, sql);
        self.run_timed(&Statement::new(sql)).await.map(|r| r.ok).map_err(sql::processed)
    }

    /// `deleteAttribute($collection, $id)`: a missing column is no error.
    pub(super) async fn delete_attribute_impl(&self, collection: &str, id: &str) -> Result<bool> {
        let name = filter(collection);
        let id = filter(id);
        let sql = format!("\n\t\t\tALTER TABLE {}\n\t\t\tDROP COLUMN \"{id}\";\n\t\t", self.sql_table(&name));
        let sql = self.trigger_sql(Database::EVENT_ATTRIBUTE_DELETE, sql);
        match self.run_timed(&Statement::new(sql)).await {
            Ok(r) => Ok(r.ok),
            Err(super::pdo::PdoFailure::Pdo(e)) if e.sqlstate == "42703" && e.native == Some(7) => Ok(true),
            Err(e) => Err(sql::raw(e)),
        }
    }

    /// `renameAttribute($collection, $old, $new)`.
    pub(super) async fn rename_attribute_impl(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        let collection = filter(collection);
        let old = filter(old);
        let new = filter(new);
        let sql = format!(
            "\n\t\t\tALTER TABLE {} \n\t\t\tRENAME COLUMN \"{old}\" TO \"{new}\"\n\t\t",
            self.sql_table(&collection)
        );
        let sql = self.trigger_sql(Database::EVENT_ATTRIBUTE_UPDATE, sql);
        self.run_timed(&Statement::new(sql)).await.map(|r| r.ok).map_err(sql::raw)
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
        let ty = sql::sql_type(Database::VAR_RELATIONSHIP, 0, false, false, false)?;
        let sql = match kind {
            Database::RELATION_ONE_TO_ONE => {
                let mut sql = format!("ALTER TABLE {table} ADD COLUMN \"{id}\" {ty} DEFAULT NULL;");
                if two_way {
                    sql.push_str(&format!(
                        "ALTER TABLE {related_table} ADD COLUMN \"{two_way_key}\" {ty} DEFAULT NULL;"
                    ));
                }
                sql
            }
            Database::RELATION_ONE_TO_MANY => {
                format!("ALTER TABLE {related_table} ADD COLUMN \"{two_way_key}\" {ty} DEFAULT NULL;")
            }
            Database::RELATION_MANY_TO_ONE => format!("ALTER TABLE {table} ADD COLUMN \"{id}\" {ty} DEFAULT NULL;"),
            Database::RELATION_MANY_TO_MANY => return Ok(true),
            _ => return Err(Error::database("Invalid relationship type")),
        };
        let sql = self.trigger_sql(Database::EVENT_ATTRIBUTE_CREATE, sql);
        self.run_timed(&Statement::new(sql)).await.map(|r| r.ok).map_err(sql::raw)
    }

    /// The junction table of two collections by their metadata documents'
    /// sequences (`getSQLTable('_' . $a->getSequence() . '_' . $b->getSequence())`).
    async fn junction_sequences(&self, collection: &str, related: &str) -> Result<(String, String)> {
        let metadata = Document::from_array_unchecked(
            [(crate::value::ArrayKey::from("$id"), Value::Str(Database::METADATA.into()))].into_iter().collect(),
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
                    sql = format!("ALTER TABLE {table} RENAME COLUMN \"{key}\" TO \"{nk}\";");
                }
                if two_way && Some(&two_way_key) != new_two_way_key.as_ref() {
                    sql.push_str(&format!("ALTER TABLE {related_table} RENAME COLUMN \"{two_way_key}\" TO \"{ntk}\";"));
                }
            }
            Database::RELATION_ONE_TO_MANY => {
                if side == Database::RELATION_SIDE_PARENT {
                    if Some(&two_way_key) != new_two_way_key.as_ref() {
                        sql = format!("ALTER TABLE {related_table} RENAME COLUMN \"{two_way_key}\" TO \"{ntk}\";");
                    }
                } else if Some(&key) != new_key.as_ref() {
                    sql = format!("ALTER TABLE {table} RENAME COLUMN \"{key}\" TO \"{nk}\";");
                }
            }
            Database::RELATION_MANY_TO_ONE => {
                if side == Database::RELATION_SIDE_CHILD {
                    if Some(&two_way_key) != new_two_way_key.as_ref() {
                        sql = format!("ALTER TABLE {related_table} RENAME COLUMN \"{two_way_key}\" TO \"{ntk}\";");
                    }
                } else if Some(&key) != new_key.as_ref() {
                    sql = format!("ALTER TABLE {table} RENAME COLUMN \"{key}\" TO \"{nk}\";");
                }
            }
            Database::RELATION_MANY_TO_MANY => {
                let (a, b) = self.junction_sequences(collection, related_collection).await?;
                let junction = self.sql_table(&format!("_{a}_{b}"));
                if new_key.is_some() {
                    sql = format!("ALTER TABLE {junction} RENAME COLUMN \"{key}\" TO \"{nk}\";");
                }
                if two_way && new_two_way_key.is_some() {
                    sql.push_str(&format!("ALTER TABLE {junction} RENAME COLUMN \"{two_way_key}\" TO \"{ntk}\";"));
                }
            }
            _ => return Err(Error::database("Invalid relationship type")),
        }
        if sql.is_empty() {
            return Ok(true);
        }
        let sql = self.trigger_sql(Database::EVENT_ATTRIBUTE_UPDATE, sql);
        self.run_timed(&Statement::new(sql)).await.map(|r| r.ok).map_err(sql::raw)
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
                    sql = format!("ALTER TABLE {table} DROP COLUMN \"{key}\";");
                    if two_way {
                        sql.push_str(&format!("ALTER TABLE {related_table} DROP COLUMN \"{two_way_key}\";"));
                    }
                } else if side == Database::RELATION_SIDE_CHILD {
                    sql = format!("ALTER TABLE {related_table} DROP COLUMN \"{two_way_key}\";");
                    if two_way {
                        sql.push_str(&format!("ALTER TABLE {table} DROP COLUMN \"{key}\";"));
                    }
                }
            }
            Database::RELATION_ONE_TO_MANY => {
                sql = if side == Database::RELATION_SIDE_PARENT {
                    format!("ALTER TABLE {related_table} DROP COLUMN \"{two_way_key}\";")
                } else {
                    format!("ALTER TABLE {table} DROP COLUMN \"{key}\";")
                };
            }
            Database::RELATION_MANY_TO_ONE => {
                sql = if side == Database::RELATION_SIDE_CHILD {
                    format!("ALTER TABLE {related_table} DROP COLUMN \"{two_way_key}\";")
                } else {
                    format!("ALTER TABLE {table} DROP COLUMN \"{key}\";")
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
        self.run_timed(&Statement::new(sql)).await.map(|r| r.ok).map_err(sql::raw)
    }

    /// `createIndex(...)`.
    #[allow(clippy::too_many_arguments)]
    pub(super) async fn create_index_impl(
        &self,
        collection: &str,
        id: &str,
        kind: &str,
        attributes: &[String],
        _lengths: &[Value],
        orders: &[Value],
        attribute_types: &[(String, String)],
        _collation: &[String],
        ttl: i64,
    ) -> Result<bool> {
        match self.create_index_inner(collection, id, kind, attributes, orders, attribute_types, ttl).await {
            Ok(b) => Ok(b),
            Err(IndexFailure::Pdo(e)) => Err(sql::process_exception(e)),
            Err(IndexFailure::Other(e)) => Err(e),
        }
    }

    /// `createIndex` up to its error mapping: a driver error that is not a
    /// unique violation is left for the caller to process.
    #[allow(clippy::too_many_arguments)]
    async fn create_index_inner(
        &self,
        collection: &str,
        id: &str,
        kind: &str,
        attributes: &[String],
        orders: &[Value],
        attribute_types: &[(String, String)],
        _ttl: i64,
    ) -> Result<bool, IndexFailure> {
        let collection = filter(collection);
        let id = filter(id);
        let mut columns: Vec<String> = Vec::with_capacity(attributes.len());
        for (i, attr) in attributes.iter().enumerate() {
            let order = match orders.get(i) {
                Some(v) if !is_empty(v) && kind != Database::INDEX_FULLTEXT => interpolate(v),
                _ => String::new(),
            };
            let attr_type = attribute_types.iter().rev().find(|(k, _)| k == attr).map(|(_, t)| t.as_str());
            let nested = attr_type.is_some() && attr.contains('.') && attr_type == Some(Database::VAR_OBJECT);
            if nested {
                let path = super::find::jsonb_path(attr, true).map_err(IndexFailure::Other)?;
                columns.push(if order.is_empty() { path } else { format!("{path} {order}") });
            } else {
                let attr = filter(sql::internal_key(attr));
                columns.push(format!("\"{attr}\" {order}"));
            }
        }
        let sql_type = match kind {
            Database::INDEX_KEY
            | Database::INDEX_FULLTEXT
            | Database::INDEX_SPATIAL
            | Database::INDEX_HNSW_EUCLIDEAN
            | Database::INDEX_HNSW_COSINE
            | Database::INDEX_HNSW_DOT
            | Database::INDEX_OBJECT
            | Database::INDEX_TRIGRAM => "INDEX",
            Database::INDEX_UNIQUE => "UNIQUE INDEX",
            _ => {
                return Err(IndexFailure::Other(Error::database(format!(
                    "Unknown index type: {kind}. Must be one of {}, {}, {}, {}, {}, {}, {}, {}",
                    Database::INDEX_KEY,
                    Database::INDEX_UNIQUE,
                    Database::INDEX_FULLTEXT,
                    Database::INDEX_SPATIAL,
                    Database::INDEX_OBJECT,
                    Database::INDEX_HNSW_EUCLIDEAN,
                    Database::INDEX_HNSW_COSINE,
                    Database::INDEX_HNSW_DOT
                ))));
            }
        };
        let (namespace, tenant) = {
            let s = self.state.lock();
            (s.namespace.clone(), interpolate(&s.tenant))
        };
        let key_name = short_key(&format!("{namespace}_{tenant}_{collection}_{id}"));
        let mut attributes = columns.join(", ");
        if self.shared() && (kind == Database::INDEX_KEY || kind == Database::INDEX_UNIQUE) {
            attributes = format!("_tenant, {attributes}");
        }
        let mut sql = format!("CREATE {sql_type} \"{key_name}\" ON {}", self.sql_table(&collection));
        sql.push_str(&match kind {
            Database::INDEX_SPATIAL => format!(" USING GIST ({attributes})"),
            Database::INDEX_HNSW_EUCLIDEAN => format!(" USING HNSW ({attributes} vector_l2_ops)"),
            Database::INDEX_HNSW_COSINE => format!(" USING HNSW ({attributes} vector_cosine_ops)"),
            Database::INDEX_HNSW_DOT => format!(" USING HNSW ({attributes} vector_ip_ops)"),
            Database::INDEX_OBJECT => format!(" USING GIN ({attributes})"),
            Database::INDEX_TRIGRAM => format!(
                " USING GIN ({})",
                attributes.split(',').map(|a| format!("{} gin_trgm_ops", php_trim(a))).collect::<Vec<_>>().join(", ")
            ),
            _ => format!(" ({attributes})"),
        });
        let sql = self.trigger_sql(Database::EVENT_INDEX_CREATE, sql);
        match self.run(&Statement::new(sql)).await {
            Ok(r) => Ok(r.ok),
            Err(super::pdo::PdoFailure::Pdo(e)) if e.sqlstate == "23505" && e.native == Some(7) => {
                Err(IndexFailure::Other(Error::unique("Unique index violation")))
            }
            Err(super::pdo::PdoFailure::Pdo(e)) => Err(IndexFailure::Pdo(e)),
            Err(super::pdo::PdoFailure::Other(e)) => Err(IndexFailure::Other(e)),
        }
    }

    /// `deleteIndex($collection, $id)`.
    pub(super) async fn delete_index_impl(&self, collection: &str, id: &str) -> Result<bool> {
        let collection = filter(collection);
        let id = filter(id);
        let (schema, namespace, tenant) = {
            let s = self.state.lock();
            (s.database.clone(), s.namespace.clone(), interpolate(&s.tenant))
        };
        let key_name = short_key(&format!("{namespace}_{tenant}_{collection}_{id}"));
        let sql =
            self.trigger_sql(Database::EVENT_INDEX_DELETE, format!("DROP INDEX IF EXISTS \"{schema}\".\"{key_name}\""));
        self.run_timed(&Statement::new(sql)).await.map(|r| r.ok).map_err(sql::raw)
    }

    /// `renameIndex($collection, $old, $new)`.
    pub(super) async fn rename_index_impl(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        let collection = filter(collection);
        let old = filter(old);
        let new = filter(new);
        let (schema, namespace, tenant) = {
            let s = self.state.lock();
            (s.database.clone(), s.namespace.clone(), interpolate(&s.tenant))
        };
        let old_name = short_key(&format!("{namespace}_{tenant}_{collection}_{old}"));
        let new_name = short_key(&format!("{namespace}_{tenant}_{collection}_{new}"));
        let sql = self.trigger_sql(
            Database::EVENT_INDEX_RENAME,
            format!("ALTER INDEX \"{schema}\".\"{old_name}\" RENAME TO \"{new_name}\""),
        );
        self.run_timed(&Statement::new(sql)).await.map(|r| r.ok).map_err(sql::raw)
    }

    /// `getAttributeWidth($collection)`, failing on an unknown type as PHP
    /// does (the [`crate::Adapter::attribute_width`] of this adapter
    /// reports `0` then).
    ///
    /// PHP: `Utopia\Database\Adapter\SQL::getAttributeWidth`.
    pub fn try_attribute_width(&self, collection: &Document) -> Result<i64> {
        attribute_width(collection)
    }
}

/// Why `createIndex` failed before its error mapping.
pub(super) enum IndexFailure {
    Pdo(super::pdo::PdoError),
    Other(Error),
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

fn php_trim(s: &str) -> String {
    String::from_utf8_lossy(php_std::string::trim(s.as_bytes(), b" \t\n\r\0\x0B")).into_owned()
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
            Database::VAR_POINT => 32,
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
