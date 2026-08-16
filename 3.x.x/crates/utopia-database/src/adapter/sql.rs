//! Shared SQL adapter helpers and live engine adapter (PHP `Utopia\Database\Adapter\SQL`).

use crate::adapter::{filter_key, Adapter, AdapterState};
use crate::constants::*;
use crate::document::Document;
use crate::error::{DatabaseError, Result};
use crate::pdo::{Dialect, Pdo, SqlParam};
use crate::query::{
    Query, TYPE_EQUAL, TYPE_GREATER, TYPE_GREATER_EQUAL, TYPE_IS_NOT_NULL, TYPE_IS_NULL,
    TYPE_LESSER, TYPE_LESSER_EQUAL, TYPE_NOT_EQUAL,
};
use crate::value::AttrValue;
use indexmap::IndexMap;

/// Quote an identifier with backticks (MySQL/MariaDB/SQLite PHP).
#[must_use]
pub fn quote_mysql(ident: &str) -> String {
    format!("`{}`", ident.replace('`', "``"))
}

/// Quote an identifier with double quotes (Postgres/SQLite ANSI).
#[must_use]
pub fn quote_ansi(ident: &str) -> String {
    format!("\"{}\"", ident.replace('"', "\"\""))
}

/// Map a Utopia attribute type to a generic SQL type name.
#[must_use]
pub fn sql_type(attribute_type: &str, size: usize, signed: bool, array: bool) -> String {
    mysql_type(attribute_type, size as i64, signed, array, false)
}

/// Build a WHERE fragment from queries (placeholder - drivers override).
#[must_use]
pub fn queries_to_where(queries: &[Query]) -> String {
    if queries.is_empty() {
        return String::new();
    }
    queries
        .iter()
        .map(|q| q.to_string().unwrap_or_default())
        .collect::<Vec<_>>()
        .join(" AND ")
}

/// Attribute documents stored on a collection.
#[must_use]
pub fn collection_attributes(collection: &Document) -> Vec<Document> {
    match collection.get_attribute("attributes") {
        AttrValue::Array(items) => items
            .values()
            .filter_map(|v| match v {
                AttrValue::Document(d) => Some((**d).clone()),
                _ => None,
            })
            .collect(),
        _ => Vec::new(),
    }
}

/// PHP `Utopia\Database\Adapter\SQL` - PDO-backed SQL adapter.
#[derive(Debug)]
pub struct Sql {
    state: AdapterState,
    pdo: Option<Pdo>,
    float_precision: i32,
}

impl Sql {
    /// PHP `__construct($pdo)`.
    #[must_use]
    pub fn new(pdo: Pdo) -> Self {
        Self {
            state: AdapterState::default(),
            pdo: Some(pdo),
            float_precision: 17,
        }
    }

    /// Construct without an open PDO (used by feature-gated stubs).
    #[must_use]
    pub fn disconnected() -> Self {
        Self {
            state: AdapterState::default(),
            pdo: None,
            float_precision: 17,
        }
    }

    /// PHP `setFloatPrecision`.
    pub fn set_float_precision(&mut self, precision: i32) {
        self.float_precision = precision;
    }

    /// PHP `getFloatPrecision` helper.
    #[must_use]
    pub fn format_float(&self, value: f64) -> String {
        format!(
            "{value:.prec$}",
            prec = self.float_precision.max(0) as usize
        )
    }

    /// The wrapped PDO, if any.
    #[must_use]
    pub fn pdo(&self) -> Option<&Pdo> {
        self.pdo.as_ref()
    }
}

impl Adapter for Sql {
    fn state(&self) -> &AdapterState {
        &self.state
    }
    fn state_mut(&mut self) -> &mut AdapterState {
        &mut self.state
    }
    fn get_driver(&self) -> AttrValue {
        AttrValue::from("sql")
    }
}

/// Result alias used by SQL helpers.
pub type SqlResult<T> = Result<T>;

/// Live SQL engine used by MySQL / MariaDB / Postgres / SQLite.
#[derive(Debug)]
pub struct SqlAdapter {
    state: AdapterState,
    pdo: Pdo,
    dialect: Dialect,
}

impl SqlAdapter {
    /// Wrap an open PDO.
    #[must_use]
    pub fn new(pdo: Pdo) -> Self {
        let dialect = pdo.dialect();
        Self {
            state: AdapterState::default(),
            pdo,
            dialect,
        }
    }

    fn q(&self, ident: &str) -> String {
        match self.dialect {
            Dialect::Postgres => quote_ansi(ident),
            Dialect::Mysql | Dialect::Mariadb | Dialect::Sqlite => quote_mysql(ident),
        }
    }

    fn table(&self, name: &str) -> String {
        let filtered = filter_key(name);
        let table = format!("{}_{filtered}", self.state.namespace);
        match self.dialect {
            Dialect::Sqlite => self.q(&table),
            _ => format!("{}.{}", self.q(&self.state.database), self.q(&table)),
        }
    }

    fn internal_key(attribute: &str) -> &str {
        match attribute {
            "$id" => "_uid",
            "$sequence" | "$id_internal" => "_id",
            "$createdAt" => "_createdAt",
            "$updatedAt" => "_updatedAt",
            "$permissions" => "_permissions",
            "$tenant" => "_tenant",
            other => other,
        }
    }

    fn sql_type(
        &self,
        type_: &str,
        size: i64,
        signed: bool,
        array: bool,
        required: bool,
    ) -> String {
        match self.dialect {
            Dialect::Postgres => postgres_type(type_, size, signed, array, required),
            Dialect::Sqlite => sqlite_type(type_, size, signed, array),
            Dialect::Mysql | Dialect::Mariadb => mysql_type(type_, size, signed, array, required),
        }
    }

    fn pdo_mut(&mut self) -> &mut Pdo {
        &mut self.pdo
    }
}

impl Adapter for SqlAdapter {
    fn state(&self) -> &AdapterState {
        &self.state
    }
    fn state_mut(&mut self) -> &mut AdapterState {
        &mut self.state
    }

    fn ping(&mut self) -> bool {
        self.pdo.ping().unwrap_or(false)
    }

    fn start_transaction(&mut self) -> Result<bool> {
        if self.state.in_transaction == 0 {
            self.pdo.exec("BEGIN", &[])?;
        } else {
            let sql = format!("SAVEPOINT transaction{}", self.state.in_transaction);
            self.pdo.exec(&sql, &[])?;
        }
        self.state.in_transaction += 1;
        Ok(true)
    }

    fn commit_transaction(&mut self) -> Result<bool> {
        if self.state.in_transaction == 0 {
            return Ok(false);
        }
        if self.state.in_transaction == 1 {
            self.pdo.exec("COMMIT", &[])?;
        } else {
            let sql = format!(
                "RELEASE SAVEPOINT transaction{}",
                self.state.in_transaction - 1
            );
            self.pdo.exec(&sql, &[])?;
        }
        self.state.in_transaction -= 1;
        Ok(true)
    }

    fn rollback_transaction(&mut self) -> Result<bool> {
        if self.state.in_transaction == 0 {
            return Ok(false);
        }
        if self.state.in_transaction == 1 {
            self.pdo.exec("ROLLBACK", &[])?;
        } else {
            let sql = format!(
                "ROLLBACK TO SAVEPOINT transaction{}",
                self.state.in_transaction - 1
            );
            self.pdo.exec(&sql, &[])?;
        }
        self.state.in_transaction -= 1;
        Ok(true)
    }

    fn create(&mut self, name: &str) -> Result<bool> {
        let name = filter_key(name);
        match self.dialect {
            Dialect::Sqlite => Ok(true),
            Dialect::Postgres => {
                if self.exists(&name, None)? {
                    return Ok(true);
                }
                let sql = format!("CREATE SCHEMA {}", self.q(&name));
                self.pdo.exec(&sql, &[])?;
                for ext in ["postgis", "vector", "pg_trgm"] {
                    let _ = self
                        .pdo
                        .exec(&format!("CREATE EXTENSION IF NOT EXISTS {ext}"), &[]);
                }
                Ok(true)
            }
            Dialect::Mysql | Dialect::Mariadb => {
                if self.exists(&name, None)? {
                    return Ok(true);
                }
                let sql = format!(
                    "CREATE DATABASE {} /*!40100 DEFAULT CHARACTER SET utf8mb4 */",
                    self.q(&name)
                );
                self.pdo.exec(&sql, &[])?;
                Ok(true)
            }
        }
    }

    fn exists(&mut self, database: &str, collection: Option<&str>) -> Result<bool> {
        let database = filter_key(database);
        match (self.dialect, collection) {
            (Dialect::Sqlite, None) => Ok(true),
            (Dialect::Sqlite, Some(collection)) => {
                let table = format!("{}_{}", self.state.namespace, filter_key(collection));
                let rows = self.pdo.query(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name = :table",
                    &[(":table", SqlParam::from_str(table.clone()))],
                )?;
                Ok(rows
                    .iter()
                    .any(|r| r.get("name").and_then(AttrValue::as_str) == Some(table.as_str())))
            }
            (Dialect::Postgres, None) => {
                let rows = self.pdo.query(
                    "SELECT schema_name FROM information_schema.schemata WHERE schema_name = :schema",
                    &[(":schema", SqlParam::from_str(database))],
                )?;
                Ok(!rows.is_empty())
            }
            (Dialect::Postgres, Some(collection)) => {
                let table = format!("{}_{}", self.state.namespace, filter_key(collection));
                let rows = self.pdo.query(
                    "SELECT table_name FROM information_schema.tables WHERE table_schema = :schema AND table_name = :table",
                    &[
                        (":schema", SqlParam::from_str(database)),
                        (":table", SqlParam::from_str(table)),
                    ],
                )?;
                Ok(!rows.is_empty())
            }
            (_, None) => {
                let rows = self.pdo.query(
                    "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :schema",
                    &[(":schema", SqlParam::from_str(database))],
                )?;
                Ok(!rows.is_empty())
            }
            (_, Some(collection)) => {
                let table = format!("{}_{}", self.state.namespace, filter_key(collection));
                let rows = self.pdo.query(
                    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table",
                    &[
                        (":schema", SqlParam::from_str(database)),
                        (":table", SqlParam::from_str(table)),
                    ],
                )?;
                Ok(!rows.is_empty())
            }
        }
    }

    fn delete(&mut self, name: &str) -> Result<bool> {
        let name = filter_key(name);
        match self.dialect {
            Dialect::Sqlite => Ok(true),
            Dialect::Postgres => {
                let sql = format!("DROP SCHEMA IF EXISTS {} CASCADE", self.q(&name));
                self.pdo.exec(&sql, &[])?;
                Ok(true)
            }
            Dialect::Mysql | Dialect::Mariadb => {
                let sql = format!("DROP DATABASE IF EXISTS {}", self.q(&name));
                self.pdo.exec(&sql, &[])?;
                Ok(true)
            }
        }
    }

    fn create_collection(
        &mut self,
        name: &str,
        attributes: &[Document],
        _indexes: &[Document],
    ) -> Result<bool> {
        let id = filter_key(name);
        let mut cols = Vec::new();
        for attribute in attributes {
            let attr_id = filter_key(&attribute.get_id());
            if attribute.get_attribute("type").as_str() == Some(VAR_RELATIONSHIP) {
                continue;
            }
            let type_ = self.sql_type(
                attribute
                    .get_attribute("type")
                    .as_str()
                    .unwrap_or(VAR_STRING),
                attribute.get_attribute("size").as_i64().unwrap_or(0),
                attribute.get_attribute("signed").as_bool().unwrap_or(true),
                attribute.get_attribute("array").as_bool().unwrap_or(false),
                attribute
                    .get_attribute("required")
                    .as_bool()
                    .unwrap_or(false),
            );
            cols.push(format!("{} {type_}", self.q(&attr_id)));
        }
        let extra = if cols.is_empty() {
            String::new()
        } else {
            format!(", {}", cols.join(", "))
        };
        let table = self.table(&id);
        let perms = self.table(&format!("{id}_perms"));
        let id_col = self.q("_id");
        let uid_col = self.q("_uid");
        let created_col = self.q("_createdAt");
        let updated_col = self.q("_updatedAt");
        let perms_col = self.q("_permissions");
        let type_col = self.q("_type");
        let permission_col = self.q("_permission");
        let document_col = self.q("_document");
        let (collection_sql, perms_sql) = match self.dialect {
            Dialect::Postgres => (
                format!(
                    "CREATE TABLE {table} (
                        {id_col} BIGSERIAL PRIMARY KEY,
                        {uid_col} VARCHAR(255) NOT NULL UNIQUE,
                        {created_col} TIMESTAMP(3) DEFAULT NULL,
                        {updated_col} TIMESTAMP(3) DEFAULT NULL,
                        {perms_col} TEXT DEFAULT NULL
                        {extra}
                    )"
                ),
                format!(
                    "CREATE TABLE {perms} (
                        {id_col} BIGSERIAL PRIMARY KEY,
                        {type_col} VARCHAR(12) NOT NULL,
                        {permission_col} VARCHAR(255) NOT NULL,
                        {document_col} VARCHAR(255) NOT NULL,
                        UNIQUE ({document_col}, {type_col}, {permission_col})
                    )"
                ),
            ),
            Dialect::Sqlite => (
                format!(
                    "CREATE TABLE {table} (
                        {id_col} INTEGER PRIMARY KEY AUTOINCREMENT,
                        {uid_col} VARCHAR(36) NOT NULL UNIQUE,
                        {created_col} DATETIME DEFAULT NULL,
                        {updated_col} DATETIME DEFAULT NULL,
                        {perms_col} TEXT DEFAULT NULL
                        {extra}
                    )"
                ),
                format!(
                    "CREATE TABLE {perms} (
                        {id_col} INTEGER PRIMARY KEY AUTOINCREMENT,
                        {type_col} VARCHAR(12) NOT NULL,
                        {permission_col} VARCHAR(255) NOT NULL,
                        {document_col} VARCHAR(255) NOT NULL,
                        UNIQUE ({document_col}, {type_col}, {permission_col})
                    )"
                ),
            ),
            Dialect::Mysql | Dialect::Mariadb => (
                format!(
                    "CREATE TABLE {table} (
                        {id_col} BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        {uid_col} VARCHAR(255) NOT NULL,
                        {created_col} DATETIME(3) DEFAULT NULL,
                        {updated_col} DATETIME(3) DEFAULT NULL,
                        {perms_col} MEDIUMTEXT DEFAULT NULL
                        {extra},
                        PRIMARY KEY ({id_col}),
                        UNIQUE KEY {uid_col} ({uid_col})
                    )"
                ),
                format!(
                    "CREATE TABLE {perms} (
                        {id_col} BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        {type_col} VARCHAR(12) NOT NULL,
                        {permission_col} VARCHAR(255) NOT NULL,
                        {document_col} VARCHAR(255) NOT NULL,
                        PRIMARY KEY ({id_col}),
                        UNIQUE INDEX _index1 ({document_col}, {type_col}, {permission_col})
                    )"
                ),
            ),
        };
        self.pdo.exec(&collection_sql, &[])?;
        self.pdo.exec(&perms_sql, &[])?;
        Ok(true)
    }

    fn delete_collection(&mut self, id: &str) -> Result<bool> {
        let id = filter_key(id);
        let table = self.table(&id);
        let perms = self.table(&format!("{id}_perms"));
        match self.dialect {
            Dialect::Sqlite => {
                self.pdo
                    .exec(&format!("DROP TABLE IF EXISTS {table}"), &[])?;
                self.pdo
                    .exec(&format!("DROP TABLE IF EXISTS {perms}"), &[])?;
            }
            _ => {
                self.pdo
                    .exec(&format!("DROP TABLE IF EXISTS {table}, {perms}"), &[])?;
            }
        }
        Ok(true)
    }

    fn create_attribute(
        &mut self,
        collection: &str,
        id: &str,
        type_: &str,
        size: i64,
        signed: bool,
        array: bool,
        required: bool,
    ) -> Result<bool> {
        let sql_type = self.sql_type(type_, size, signed, array, required);
        let sql = format!(
            "ALTER TABLE {} ADD COLUMN {} {sql_type}",
            self.table(collection),
            self.q(&filter_key(id))
        );
        self.pdo.exec(&sql, &[])?;
        Ok(true)
    }

    fn delete_attribute(&mut self, collection: &str, id: &str) -> Result<bool> {
        let sql = format!(
            "ALTER TABLE {} DROP COLUMN {}",
            self.table(collection),
            self.q(&filter_key(id))
        );
        self.pdo.exec(&sql, &[])?;
        Ok(true)
    }

    fn rename_attribute(&mut self, collection: &str, old: &str, new: &str) -> Result<bool> {
        let sql = format!(
            "ALTER TABLE {} RENAME COLUMN {} TO {}",
            self.table(collection),
            self.q(&filter_key(old)),
            self.q(&filter_key(new))
        );
        self.pdo.exec(&sql, &[])?;
        Ok(true)
    }

    fn get_document(
        &mut self,
        collection: &Document,
        id: &str,
        _queries: &[Query],
        for_update: bool,
    ) -> Result<Document> {
        let name = filter_key(&collection.get_id());
        let lock = if for_update && self.get_support_for_update_lock() {
            " FOR UPDATE"
        } else {
            ""
        };
        let sql = format!(
            "SELECT * FROM {} WHERE {} = :_uid{lock}",
            self.table(&name),
            self.q("_uid")
        );
        let rows = self
            .pdo_mut()
            .query(&sql, &[(":_uid", SqlParam::from_str(id))])?;
        Ok(rows
            .into_iter()
            .next()
            .map_or_else(Document::new, row_to_document))
    }

    fn create_document(
        &mut self,
        collection: &Document,
        mut document: Document,
    ) -> Result<Document> {
        let name = filter_key(&collection.get_id());
        let mut columns = Vec::new();
        let mut placeholders = Vec::new();
        let mut params: Vec<(String, SqlParam)> = Vec::new();
        let mut i = 0;
        for (attribute, value) in document.get_attributes() {
            let column = filter_key(&attribute);
            let key = format!(":key_{i}");
            columns.push(self.q(&column));
            placeholders.push(key.clone());
            params.push((key, SqlParam::from_attr(&value)));
            i += 1;
        }
        columns.push(self.q("_createdAt"));
        placeholders.push(":created".into());
        params.push((
            ":created".into(),
            document
                .get_created_at()
                .map_or(SqlParam::Null, SqlParam::from_str),
        ));
        columns.push(self.q("_updatedAt"));
        placeholders.push(":updated".into());
        params.push((
            ":updated".into(),
            document
                .get_updated_at()
                .map_or(SqlParam::Null, SqlParam::from_str),
        ));
        columns.push(self.q("_permissions"));
        placeholders.push(":perms".into());
        params.push((
            ":perms".into(),
            SqlParam::Text(
                serde_json::to_string(&document.get_permissions()).unwrap_or_else(|_| "[]".into()),
            ),
        ));
        if let Some(seq) = document.get_sequence() {
            columns.push(self.q("_id"));
            placeholders.push(":_id".into());
            params.push((":_id".into(), SqlParam::from_str(seq)));
        }
        columns.push(self.q("_uid"));
        placeholders.push(":_uid".into());
        params.push((":_uid".into(), SqlParam::from_str(document.get_id())));

        let returning = if self.dialect == Dialect::Postgres {
            format!(" RETURNING {}", self.q("_id"))
        } else {
            String::new()
        };
        let sql = format!(
            "INSERT INTO {} ({}) VALUES ({}){returning}",
            self.table(&name),
            columns.join(", "),
            placeholders.join(", ")
        );
        let param_refs: Vec<(&str, SqlParam)> = params
            .iter()
            .map(|(k, v)| (k.as_str(), v.clone()))
            .collect();
        if self.dialect == Dialect::Postgres {
            let rows = self.pdo.query(&sql, &param_refs)?;
            if let Some(id) = rows
                .first()
                .and_then(|r| r.get("_id"))
                .and_then(|v| v.as_i64())
            {
                document.set_attribute("$sequence", AttrValue::from(id));
            }
        } else {
            self.pdo.exec(&sql, &param_refs)?;
            let id = self.pdo.last_insert_id().to_owned();
            if id.is_empty() || id == "0" {
                return Err(DatabaseError::database(
                    "Error creating document empty \"$sequence\"",
                ));
            }
            document.set_attribute("$sequence", AttrValue::from(id));
        }

        let mut perm_values = Vec::new();
        let mut perm_params: Vec<(String, SqlParam)> = Vec::new();
        perm_params.push((":_uid".into(), SqlParam::from_str(document.get_id())));
        let mut pi = 0;
        for perm_type in PERMISSIONS {
            for permission in document.get_permissions_by_type(perm_type) {
                let key = format!(":p{pi}");
                perm_values.push(format!("('{perm_type}', {key}, :_uid)"));
                perm_params.push((key, SqlParam::from_str(permission.replace('"', ""))));
                pi += 1;
            }
        }
        if !perm_values.is_empty() {
            let sql = format!(
                "INSERT INTO {} ({}, {}, {}) VALUES {}",
                self.table(&format!("{name}_perms")),
                self.q("_type"),
                self.q("_permission"),
                self.q("_document"),
                perm_values.join(", ")
            );
            let refs: Vec<(&str, SqlParam)> = perm_params
                .iter()
                .map(|(k, v)| (k.as_str(), v.clone()))
                .collect();
            let _ = self.pdo.exec(&sql, &refs);
        }
        Ok(document)
    }

    fn update_document(
        &mut self,
        collection: &Document,
        id: &str,
        document: Document,
        _skip_permissions: bool,
    ) -> Result<Document> {
        let name = filter_key(&collection.get_id());
        let mut sets = Vec::new();
        let mut params: Vec<(String, SqlParam)> = Vec::new();
        let mut i = 0;
        for (attribute, value) in document.get_attributes() {
            let column = filter_key(&attribute);
            let key = format!(":key_{i}");
            sets.push(format!("{} = {key}", self.q(&column)));
            params.push((key, SqlParam::from_attr(&value)));
            i += 1;
        }
        sets.push(format!("{} = :updated", self.q("_updatedAt")));
        params.push((
            ":updated".into(),
            document
                .get_updated_at()
                .map_or(SqlParam::Null, SqlParam::from_str),
        ));
        sets.push(format!("{} = :perms", self.q("_permissions")));
        params.push((
            ":perms".into(),
            SqlParam::Text(
                serde_json::to_string(&document.get_permissions()).unwrap_or_else(|_| "[]".into()),
            ),
        ));
        params.push((":_uid".into(), SqlParam::from_str(id)));
        if sets.is_empty() {
            return Ok(document);
        }
        let sql = format!(
            "UPDATE {} SET {} WHERE {} = :_uid",
            self.table(&name),
            sets.join(", "),
            self.q("_uid")
        );
        let refs: Vec<(&str, SqlParam)> = params
            .iter()
            .map(|(k, v)| (k.as_str(), v.clone()))
            .collect();
        self.pdo.exec(&sql, &refs)?;
        Ok(document)
    }

    fn delete_document(&mut self, collection: &str, id: &str) -> Result<bool> {
        let name = filter_key(collection);
        self.pdo.exec(
            &format!(
                "DELETE FROM {} WHERE {} = :_uid",
                self.table(&name),
                self.q("_uid")
            ),
            &[(":_uid", SqlParam::from_str(id))],
        )?;
        self.pdo.exec(
            &format!(
                "DELETE FROM {} WHERE {} = :_uid",
                self.table(&format!("{name}_perms")),
                self.q("_document")
            ),
            &[(":_uid", SqlParam::from_str(id))],
        )?;
        Ok(true)
    }

    fn find(
        &mut self,
        collection: &Document,
        queries: &[Query],
        limit: Option<i64>,
        offset: Option<i64>,
        order_attributes: &[String],
        order_types: &[String],
        _cursor: Option<&Document>,
        _cursor_direction: &str,
        _for_permission: &str,
    ) -> Result<Vec<Document>> {
        let name = filter_key(&collection.get_id());
        let mut where_sql = Vec::new();
        let mut params: Vec<(String, SqlParam)> = Vec::new();
        let mut i = 0;
        for query in queries {
            match query.get_method() {
                TYPE_EQUAL | TYPE_NOT_EQUAL | TYPE_LESSER | TYPE_LESSER_EQUAL | TYPE_GREATER
                | TYPE_GREATER_EQUAL => {
                    let column = Self::internal_key(query.get_attribute());
                    let op = match query.get_method() {
                        TYPE_EQUAL => "=",
                        TYPE_NOT_EQUAL => "!=",
                        TYPE_LESSER => "<",
                        TYPE_LESSER_EQUAL => "<=",
                        TYPE_GREATER => ">",
                        _ => ">=",
                    };
                    if query.get_method() == TYPE_EQUAL && query.get_values().len() > 1 {
                        let mut keys = Vec::new();
                        for value in query.get_values() {
                            let key = format!(":f{i}");
                            keys.push(key.clone());
                            params.push((key, SqlParam::from_attr(value)));
                            i += 1;
                        }
                        where_sql.push(format!("{} IN ({})", self.q(column), keys.join(", ")));
                    } else {
                        let key = format!(":f{i}");
                        where_sql.push(format!("{} {op} {key}", self.q(column)));
                        params.push((key, SqlParam::from_attr(query.get_value())));
                        i += 1;
                    }
                }
                TYPE_IS_NULL => where_sql.push(format!(
                    "{} IS NULL",
                    self.q(Self::internal_key(query.get_attribute()))
                )),
                TYPE_IS_NOT_NULL => {
                    where_sql.push(format!(
                        "{} IS NOT NULL",
                        self.q(Self::internal_key(query.get_attribute()))
                    ));
                }
                _ => {}
            }
        }
        let mut sql = format!("SELECT * FROM {}", self.table(&name));
        if !where_sql.is_empty() {
            sql.push_str(" WHERE ");
            sql.push_str(&where_sql.join(" AND "));
        }
        if !order_attributes.is_empty() {
            let mut orders = Vec::new();
            for (idx, attr) in order_attributes.iter().enumerate() {
                let dir = order_types
                    .get(idx)
                    .map(String::as_str)
                    .unwrap_or(ORDER_ASC);
                orders.push(format!("{} {dir}", self.q(Self::internal_key(attr))));
            }
            sql.push_str(" ORDER BY ");
            sql.push_str(&orders.join(", "));
        } else {
            sql.push_str(&format!(" ORDER BY {}", self.q("_id")));
        }
        if let Some(limit) = limit {
            sql.push_str(&format!(" LIMIT {limit}"));
        }
        if let Some(offset) = offset {
            sql.push_str(&format!(" OFFSET {offset}"));
        }
        let refs: Vec<(&str, SqlParam)> = params
            .iter()
            .map(|(k, v)| (k.as_str(), v.clone()))
            .collect();
        let rows = self.pdo.query(&sql, &refs)?;
        Ok(rows.into_iter().map(row_to_document).collect())
    }

    fn count(&mut self, collection: &Document, queries: &[Query], max: Option<i64>) -> Result<i64> {
        let docs = self.find(
            collection,
            queries,
            max,
            None,
            &[],
            &[],
            None,
            CURSOR_AFTER,
            PERMISSION_READ,
        )?;
        Ok(docs.len() as i64)
    }

    fn get_support_for_timeouts(&self) -> bool {
        !matches!(self.dialect, Dialect::Sqlite)
    }
    fn get_support_for_hostname(&self) -> bool {
        !matches!(self.dialect, Dialect::Sqlite)
    }
    fn get_support_for_update_lock(&self) -> bool {
        matches!(self.dialect, Dialect::Mysql | Dialect::Mariadb)
    }
    fn get_support_for_spatial_attributes(&self) -> bool {
        matches!(
            self.dialect,
            Dialect::Mysql | Dialect::Mariadb | Dialect::Postgres
        )
    }
    fn get_support_for_vectors(&self) -> bool {
        matches!(self.dialect, Dialect::Postgres)
    }
    fn get_support_for_trigram_index(&self) -> bool {
        matches!(self.dialect, Dialect::Postgres)
    }
    fn get_max_index_length(&self) -> i64 {
        match self.dialect {
            Dialect::Postgres | Dialect::Sqlite => 0,
            Dialect::Mysql | Dialect::Mariadb => 768,
        }
    }
    fn get_max_varchar_length(&self) -> i64 {
        match self.dialect {
            Dialect::Sqlite => 1_000_000,
            Dialect::Postgres => 16_383,
            Dialect::Mysql | Dialect::Mariadb => 16_381,
        }
    }
    fn get_limit_for_attributes(&self) -> i64 {
        match self.dialect {
            Dialect::Sqlite => 2000,
            Dialect::Postgres => 1600,
            Dialect::Mysql | Dialect::Mariadb => 1012,
        }
    }
    fn get_driver(&self) -> AttrValue {
        AttrValue::from(match self.dialect {
            Dialect::Mysql => "mysql",
            Dialect::Mariadb => "mariadb",
            Dialect::Postgres => "postgres",
            Dialect::Sqlite => "sqlite",
        })
    }
}

/// Delegate [`Adapter`] to an inner [`SqlAdapter`], overriding only the driver name.
#[macro_export]
macro_rules! impl_sql_engine {
    ($ty:ty, $driver:expr) => {
        impl $crate::adapter::Adapter for $ty {
            fn state(&self) -> &$crate::adapter::AdapterState {
                self.inner.state()
            }
            fn state_mut(&mut self) -> &mut $crate::adapter::AdapterState {
                self.inner.state_mut()
            }
            fn ping(&mut self) -> bool {
                self.inner.ping()
            }
            fn reconnect(&mut self) -> $crate::error::Result<()> {
                self.inner.reconnect()
            }
            fn start_transaction(&mut self) -> $crate::error::Result<bool> {
                self.inner.start_transaction()
            }
            fn commit_transaction(&mut self) -> $crate::error::Result<bool> {
                self.inner.commit_transaction()
            }
            fn rollback_transaction(&mut self) -> $crate::error::Result<bool> {
                self.inner.rollback_transaction()
            }
            fn create(&mut self, name: &str) -> $crate::error::Result<bool> {
                self.inner.create(name)
            }
            fn exists(
                &mut self,
                database: &str,
                collection: Option<&str>,
            ) -> $crate::error::Result<bool> {
                self.inner.exists(database, collection)
            }
            fn delete(&mut self, name: &str) -> $crate::error::Result<bool> {
                self.inner.delete(name)
            }
            fn create_collection(
                &mut self,
                name: &str,
                attributes: &[$crate::document::Document],
                indexes: &[$crate::document::Document],
            ) -> $crate::error::Result<bool> {
                self.inner.create_collection(name, attributes, indexes)
            }
            fn delete_collection(&mut self, id: &str) -> $crate::error::Result<bool> {
                self.inner.delete_collection(id)
            }
            fn create_attribute(
                &mut self,
                collection: &str,
                id: &str,
                type_: &str,
                size: i64,
                signed: bool,
                array: bool,
                required: bool,
            ) -> $crate::error::Result<bool> {
                self.inner
                    .create_attribute(collection, id, type_, size, signed, array, required)
            }
            fn delete_attribute(
                &mut self,
                collection: &str,
                id: &str,
            ) -> $crate::error::Result<bool> {
                self.inner.delete_attribute(collection, id)
            }
            fn rename_attribute(
                &mut self,
                collection: &str,
                old: &str,
                new: &str,
            ) -> $crate::error::Result<bool> {
                self.inner.rename_attribute(collection, old, new)
            }
            fn get_document(
                &mut self,
                collection: &$crate::document::Document,
                id: &str,
                queries: &[$crate::query::Query],
                for_update: bool,
            ) -> $crate::error::Result<$crate::document::Document> {
                self.inner.get_document(collection, id, queries, for_update)
            }
            fn create_document(
                &mut self,
                collection: &$crate::document::Document,
                document: $crate::document::Document,
            ) -> $crate::error::Result<$crate::document::Document> {
                self.inner.create_document(collection, document)
            }
            fn update_document(
                &mut self,
                collection: &$crate::document::Document,
                id: &str,
                document: $crate::document::Document,
                skip_permissions: bool,
            ) -> $crate::error::Result<$crate::document::Document> {
                self.inner
                    .update_document(collection, id, document, skip_permissions)
            }
            fn delete_document(
                &mut self,
                collection: &str,
                id: &str,
            ) -> $crate::error::Result<bool> {
                self.inner.delete_document(collection, id)
            }
            fn find(
                &mut self,
                collection: &$crate::document::Document,
                queries: &[$crate::query::Query],
                limit: Option<i64>,
                offset: Option<i64>,
                order_attributes: &[String],
                order_types: &[String],
                cursor: Option<&$crate::document::Document>,
                cursor_direction: &str,
                for_permission: &str,
            ) -> $crate::error::Result<Vec<$crate::document::Document>> {
                self.inner.find(
                    collection,
                    queries,
                    limit,
                    offset,
                    order_attributes,
                    order_types,
                    cursor,
                    cursor_direction,
                    for_permission,
                )
            }
            fn count(
                &mut self,
                collection: &$crate::document::Document,
                queries: &[$crate::query::Query],
                max: Option<i64>,
            ) -> $crate::error::Result<i64> {
                self.inner.count(collection, queries, max)
            }
            fn get_max_index_length(&self) -> i64 {
                self.inner.get_max_index_length()
            }
            fn get_max_varchar_length(&self) -> i64 {
                self.inner.get_max_varchar_length()
            }
            fn get_limit_for_attributes(&self) -> i64 {
                self.inner.get_limit_for_attributes()
            }
            fn get_support_for_timeouts(&self) -> bool {
                self.inner.get_support_for_timeouts()
            }
            fn get_support_for_hostname(&self) -> bool {
                self.inner.get_support_for_hostname()
            }
            fn get_support_for_update_lock(&self) -> bool {
                self.inner.get_support_for_update_lock()
            }
            fn get_support_for_spatial_attributes(&self) -> bool {
                self.inner.get_support_for_spatial_attributes()
            }
            fn get_support_for_vectors(&self) -> bool {
                self.inner.get_support_for_vectors()
            }
            fn get_support_for_trigram_index(&self) -> bool {
                self.inner.get_support_for_trigram_index()
            }
            fn get_driver(&self) -> $crate::value::AttrValue {
                $crate::value::AttrValue::from($driver)
            }
        }
    };
}

fn row_to_document(mut row: IndexMap<String, AttrValue>) -> Document {
    if let Some(id) = row.shift_remove("_id") {
        row.insert("$sequence".into(), id);
    }
    if let Some(id) = row.shift_remove("_uid") {
        row.insert("$id".into(), id);
    }
    if let Some(v) = row.shift_remove("_tenant") {
        row.insert("$tenant".into(), v);
    }
    if let Some(v) = row.shift_remove("_createdAt") {
        row.insert("$createdAt".into(), v);
    }
    if let Some(v) = row.shift_remove("_updatedAt") {
        row.insert("$updatedAt".into(), v);
    }
    if let Some(v) = row.shift_remove("_permissions") {
        let parsed = v
            .as_str()
            .and_then(|s| serde_json::from_str::<Vec<String>>(s).ok())
            .map_or(v, AttrValue::from);
        row.insert("$permissions".into(), parsed);
    }
    Document::from_map(row).unwrap_or_else(|_| Document::new())
}

fn mysql_type(type_: &str, size: i64, signed: bool, array: bool, _required: bool) -> String {
    if array {
        return "JSON".into();
    }
    match type_ {
        VAR_STRING | VAR_VARCHAR => {
            if size > 16_777_215 {
                "LONGTEXT".into()
            } else if size > 65_535 {
                "MEDIUMTEXT".into()
            } else if size > 16_381 {
                "TEXT".into()
            } else {
                format!("VARCHAR({})", size.max(1))
            }
        }
        VAR_TEXT => "TEXT".into(),
        VAR_MEDIUMTEXT => "MEDIUMTEXT".into(),
        VAR_LONGTEXT => "LONGTEXT".into(),
        VAR_INTEGER => {
            let signed = if signed { "" } else { " UNSIGNED" };
            if size >= 8 {
                format!("BIGINT{signed}")
            } else {
                format!("INT{signed}")
            }
        }
        VAR_FLOAT => {
            let signed = if signed { "" } else { " UNSIGNED" };
            format!("DOUBLE{signed}")
        }
        VAR_BOOLEAN => "TINYINT(1)".into(),
        VAR_DATETIME => "DATETIME(3)".into(),
        VAR_RELATIONSHIP => "VARCHAR(255)".into(),
        VAR_OBJECT => "JSON".into(),
        _ => "TEXT".into(),
    }
}

fn postgres_type(type_: &str, size: i64, _signed: bool, array: bool, _required: bool) -> String {
    if array {
        return "JSONB".into();
    }
    match type_ {
        VAR_STRING | VAR_VARCHAR => {
            if size > 16_383 {
                "TEXT".into()
            } else {
                format!("VARCHAR({})", size.max(1))
            }
        }
        VAR_TEXT | VAR_MEDIUMTEXT | VAR_LONGTEXT => "TEXT".into(),
        VAR_INTEGER => {
            if size >= 8 {
                "BIGINT".into()
            } else {
                "INTEGER".into()
            }
        }
        VAR_FLOAT => "DOUBLE PRECISION".into(),
        VAR_BOOLEAN => "BOOLEAN".into(),
        VAR_DATETIME => "TIMESTAMP(3)".into(),
        VAR_RELATIONSHIP => "VARCHAR(255)".into(),
        VAR_OBJECT => "JSONB".into(),
        _ => "TEXT".into(),
    }
}

fn sqlite_type(type_: &str, size: i64, _signed: bool, array: bool) -> String {
    if array {
        return "TEXT".into();
    }
    match type_ {
        VAR_STRING | VAR_VARCHAR => format!("VARCHAR({})", size.max(1)),
        VAR_INTEGER => "INTEGER".into(),
        VAR_FLOAT => "REAL".into(),
        VAR_BOOLEAN => "INTEGER".into(),
        VAR_DATETIME => "DATETIME".into(),
        _ => "TEXT".into(),
    }
}
