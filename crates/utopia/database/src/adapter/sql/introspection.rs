//! What the engine holds for a collection.
//!
//! PHP: `getSchemaAttributes()`, `getSchemaIndexes()`,
//! `getSchemaIndexType()`, `getColumnNames()` of `SQL` and its subclasses;
//! Postgres `isTrue()`; SQLite `getIndexColumns()`,
//! `getFulltextSchemaIndexes()`, `getCharacterLength()`.

use php_std::pcre;
use utopia_query::schema::IndexType;

use super::pdo::ParamType;
use super::{Engine, Sql};
use crate::adapter::Adapter;
use crate::database;
use crate::error::{Error, Result};
use crate::event::Event;
use crate::schema;
use crate::value::{Array, Value, string};

/// The groups `pattern` matches in `subject` (`preg_match()` with
/// `$matches`), `None` when it does not match.
fn groups(pattern: &[u8], subject: &[u8]) -> Option<Vec<Vec<u8>>> {
    let result = pcre::preg_match(pattern, subject, 0, 0).ok()?;
    if !matches!(result.value.result, pcre::Value::Int(1)) {
        return None;
    }
    let matches = result.value.matches?;
    Some(
        (0..matches.len() as i64)
            .map(|i| match matches.get(&pcre::Key::Int(i)) {
                Some(pcre::Value::Str(s)) => s.clone(),
                _ => Vec::new(),
            })
            .collect(),
    )
}

/// `$row[$key]`.
fn field<'a>(row: &'a Array, key: &str) -> Option<&'a Value> {
    row.get_str(key)
}

/// `is_string($row[$key]) ? $row[$key] : null`.
fn text(row: &Array, key: &str) -> Option<String> {
    match field(row, key) {
        Some(Value::Str(s)) => Some(String::from_utf8_lossy(s.as_bytes()).into_owned()),
        _ => None,
    }
}

/// `is_scalar($row[$key]) ? (string) $row[$key] : null`.
fn scalar(row: &Array, key: &str) -> Option<String> {
    match field(row, key) {
        Some(v @ (Value::Str(_) | Value::Int(_) | Value::Float(_) | Value::Bool(_))) => {
            Some(crate::value::to_text(v))
        }
        _ => None,
    }
}

/// `is_numeric($value) ? (int) $value : null`.
fn numeric(value: Option<&Value>) -> Option<i64> {
    match value {
        Some(Value::Int(i)) => Some(*i),
        Some(Value::Float(f)) => Some(*f as i64),
        Some(v @ Value::Str(s)) if php_std::value::numeric_bytes_ex(s.as_bytes()).is_some() => {
            Some(crate::value::to_long(v))
        }
        _ => None,
    }
}

/// Postgres `isTrue()`.
fn is_true(value: Option<&Value>) -> bool {
    match value {
        Some(Value::Bool(b)) => *b,
        Some(Value::Int(i)) => *i == 1,
        Some(Value::Str(s)) => s.as_bytes() == b"t" || s.as_bytes() == b"1",
        _ => false,
    }
}

impl Sql {
    /// Runs a prepared statement and reads every row (`FETCH_ASSOC`).
    async fn catalog_rows(&self, prepared: &mut super::Prepared) -> Result<Vec<Array>> {
        self.execute(prepared).await?;
        let rows = prepared.statement.fetch_all_assoc();
        prepared.statement.close_cursor();
        Ok(rows)
    }

    /// `getSchemaAttributes()`
    pub(crate) async fn schema_attributes(&self, collection: &str) -> Result<Vec<schema::Column>> {
        match self.engine {
            Engine::Postgres => {
                let mut prepared = self
                    .prepare_statement(
                        "SELECT a.attname AS name,\n                pg_catalog.format_type(a.atttypid, a.atttypmod) AS type,\n                CASE WHEN a.atttypid IN (1042, 1043) AND a.atttypmod > 4 THEN a.atttypmod - 4 END AS length,\n                NOT a.attnotnull AS nullable\n            FROM pg_catalog.pg_attribute a\n            WHERE a.attrelid = to_regclass(?) AND a.attnum > 0 AND NOT a.attisdropped\n            ORDER BY a.attnum",
                        Some(Event::CollectionRead),
                    )
                    .await?;
                prepared.bind_value(1, string(self.table(collection)), ParamType::Str)?;
                let rows = self.catalog_rows(&mut prepared).await.map_err(|e| self.process_exception(e))?;
                Ok(rows
                    .iter()
                    .filter_map(|row| {
                        let name = text(row, "name")?;
                        Some(schema::Column {
                            name,
                            kind: self.canonical_column_type(&text(row, "type").unwrap_or_default()),
                            length: numeric(field(row, "length")),
                            nullable: is_true(field(row, "nullable")),
                        })
                    })
                    .collect())
            }
            Engine::MariaDb | Engine::MySql => {
                let table = format!("{}_{}", self.namespace(), self.filter(collection));
                let rows = async {
                    let mut prepared = self
                        .prepare_statement(
                            "\n                SELECT\n                    COLUMN_NAME AS name,\n                    COLUMN_TYPE AS type,\n                    CHARACTER_MAXIMUM_LENGTH AS length,\n                    IS_NULLABLE AS nullable\n                FROM INFORMATION_SCHEMA.COLUMNS\n                WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table\n                ORDER BY ORDINAL_POSITION\n            ",
                            Some(Event::CollectionRead),
                        )
                        .await?;
                    prepared.bind_value(":schema", string(self.database()), ParamType::Str)?;
                    prepared.bind_value(":table", string(table), ParamType::Str)?;
                    self.catalog_rows(&mut prepared).await
                }
                .await
                .map_err(|e| {
                    let code = e.code();
                    Error::database("Failed to get schema attributes").with_code(code).with_previous(e)
                })?;
                Ok(rows
                    .iter()
                    .filter_map(|row| {
                        let name = text(row, "name")?;
                        Some(schema::Column {
                            name,
                            kind: self.canonical_column_type(&text(row, "type").unwrap_or_default()),
                            length: numeric(field(row, "length")),
                            nullable: text(row, "nullable").as_deref() == Some("YES"),
                        })
                    })
                    .collect())
            }
            Engine::Sqlite => {
                let table = format!("{}_{}", self.namespace(), self.filter(collection));
                let mut prepared =
                    self.sqlite_prepare(&format!("PRAGMA table_info(`{table}`)"), Some(Event::CollectionRead)).await?;
                let rows = self.catalog_rows(&mut prepared).await?;
                Ok(rows
                    .iter()
                    .filter_map(|row| {
                        let name = scalar(row, "name")?;
                        let kind = scalar(row, "type").unwrap_or_default();
                        Some(schema::Column {
                            name,
                            kind: self.canonical_column_type(&kind),
                            length: self.character_length(&kind),
                            nullable: !field(row, "notnull").is_some_and(Value::truthy),
                        })
                    })
                    .collect())
            }
        }
    }

    /// SQLite `getCharacterLength()`: the size of a `VARCHAR` or `CHAR`,
    /// and under MySQL emulation the byte ceilings MariaDB reports for the
    /// `TEXT` family.
    fn character_length(&self, declaration: &str) -> Option<i64> {
        if let Some(groups) = groups(br"/^\s*(VARCHAR|CHAR)\s*\(\s*(\d+)\s*\)/i", declaration.as_bytes()) {
            return Some(crate::value::to_long(&string(String::from_utf8_lossy(&groups[2]).into_owned())));
        }
        if !self.engine_state().emulate_mysql {
            return None;
        }
        let trimmed = php_std::string::trim(declaration.as_bytes(), b" \n\r\t\x0B\0").to_ascii_lowercase();
        match trimmed.as_slice() {
            b"text" => Some(database::MAX_TEXT_BYTES),
            b"mediumtext" => Some(database::MAX_MEDIUMTEXT_BYTES),
            b"longtext" | b"json" => Some(database::MAX_LONGTEXT_BYTES),
            _ => None,
        }
    }

    /// `getSchemaIndexes()`
    pub(crate) async fn schema_indexes(&self, collection: &str) -> Result<Vec<schema::Index>> {
        match self.engine {
            Engine::Postgres => self.postgres_schema_indexes(collection).await,
            Engine::MariaDb | Engine::MySql => self.mariadb_schema_indexes(collection).await,
            Engine::Sqlite => self.sqlite_schema_indexes(collection).await,
        }
    }

    /// Postgres `getSchemaIndexes()`: the current tenant's indexes under
    /// their keys, any other under its physical name.
    async fn postgres_schema_indexes(&self, collection: &str) -> Result<Vec<schema::Index>> {
        let mut prepared = self
            .prepare_statement(
                "SELECT i.relname AS name,\n                x.indisunique AS \"unique\",\n                am.amname AS method,\n                (SELECT o.opcname FROM pg_catalog.pg_opclass o WHERE o.oid = x.indclass[k.position - 1]) AS operator,\n                pg_catalog.pg_get_indexdef(x.indexrelid, k.position, true) AS \"column\"\n            FROM pg_catalog.pg_index x\n            JOIN pg_catalog.pg_class i ON i.oid = x.indexrelid\n            JOIN pg_catalog.pg_am am ON am.oid = i.relam\n            CROSS JOIN LATERAL pg_catalog.generate_series(1, x.indnkeyatts) AS k(position)\n            WHERE x.indrelid = to_regclass(?)\n            ORDER BY i.relname, k.position",
                Some(Event::CollectionRead),
            )
            .await?;
        prepared.bind_value(1, string(self.table(collection)), ParamType::Str)?;
        let rows = self.catalog_rows(&mut prepared).await.map_err(|e| self.process_exception(e))?;
        let prefix = format!(
            "{}_{}_{}_",
            self.namespace(),
            super::attribute::interpolate(&self.current_tenant()),
            self.filter(collection)
        );
        let mut grouped: Vec<(String, IndexType, Vec<String>)> = Vec::new();
        for row in &rows {
            let Some(name) = text(row, "name") else {
                continue;
            };
            if !grouped.iter().any(|(n, _, _)| *n == name) {
                let operator = text(row, "operator").unwrap_or_default();
                let method = text(row, "method").unwrap_or_default();
                let kind = if is_true(field(row, "unique")) {
                    IndexType::Unique
                } else if operator == "gin_trgm_ops" {
                    IndexType::Trigram
                } else if operator == "vector_l2_ops" {
                    IndexType::HnswEuclidean
                } else if operator == "vector_cosine_ops" {
                    IndexType::HnswCosine
                } else if operator == "vector_ip_ops" {
                    IndexType::HnswDot
                } else if method == "gin" {
                    IndexType::Object
                } else if method == "gist" {
                    IndexType::Spatial
                } else {
                    IndexType::Key
                };
                grouped.push((name.clone(), kind, Vec::new()));
            }
            let mut column = text(row, "column").unwrap_or_default();
            if let Some(groups) = groups(br#"/^"(.*)"$/s"#, column.as_bytes()) {
                column = String::from_utf8_lossy(&groups[1]).replace("\"\"", "\"");
            }
            if let Some(entry) = grouped.iter_mut().find(|(n, _, _)| *n == name) {
                entry.2.push(column);
            }
        }
        Ok(grouped
            .into_iter()
            .map(|(name, kind, columns)| schema::Index {
                name: name.strip_prefix(prefix.as_str()).map_or_else(|| name.clone(), str::to_owned),
                kind,
                lengths: vec![None; columns.len()],
                columns,
            })
            .collect())
    }

    /// MariaDB `getSchemaIndexes()`.
    async fn mariadb_schema_indexes(&self, collection: &str) -> Result<Vec<schema::Index>> {
        let table = format!("{}_{}", self.namespace(), self.filter(collection));
        let rows = async {
            let mut prepared = self
                .prepare_statement(
                    "\n                SELECT\n                    INDEX_NAME AS name,\n                    COLUMN_NAME AS columnName,\n                    NON_UNIQUE AS nonUnique,\n                    INDEX_TYPE AS indexType,\n                    SUB_PART AS subPart\n                FROM INFORMATION_SCHEMA.STATISTICS\n                WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table\n                ORDER BY INDEX_NAME, SEQ_IN_INDEX\n            ",
                    Some(Event::CollectionRead),
                )
                .await?;
            prepared.bind_value(":schema", string(self.database()), ParamType::Str)?;
            prepared.bind_value(":table", string(table), ParamType::Str)?;
            self.catalog_rows(&mut prepared).await
        }
        .await
        .map_err(|e| {
            let code = e.code();
            Error::database("Failed to get schema indexes").with_code(code).with_previous(e)
        })?;
        let mut grouped: Vec<schema::Index> = Vec::new();
        for row in &rows {
            let Some(name) = text(row, "name").filter(|n| !n.is_empty()) else {
                continue;
            };
            if !grouped.iter().any(|i| i.name == name) {
                let index_type = text(row, "indexType").unwrap_or_default().to_ascii_uppercase();
                let non_unique = numeric(field(row, "nonUnique")).unwrap_or(1);
                let kind = if index_type == "FULLTEXT" {
                    IndexType::Fulltext
                } else if index_type == "SPATIAL" {
                    IndexType::Spatial
                } else if non_unique == 0 {
                    IndexType::Unique
                } else {
                    IndexType::Key
                };
                grouped.push(schema::Index { name: name.clone(), kind, columns: Vec::new(), lengths: Vec::new() });
            }
            if let Some(index) = grouped.iter_mut().find(|i| i.name == name) {
                index.columns.push(text(row, "columnName").unwrap_or_default());
                index.lengths.push(numeric(field(row, "subPart")));
            }
        }
        Ok(grouped)
    }

    /// SQLite `getSchemaIndexes()`: index names are global, so each carries
    /// the namespace, tenant and collection; the current tenant's copy
    /// wins. The FTS5 tables of fulltext indexes are added.
    async fn sqlite_schema_indexes(&self, collection: &str) -> Result<Vec<schema::Index>> {
        let filtered = self.filter(collection);
        let namespace = self.namespace();
        let table = format!("{namespace}_{filtered}");
        let own = format!("{namespace}_{}_{filtered}_", self.tenant_segment());
        let mut any_tenant = b"/^".to_vec();
        any_tenant.extend(pcre::preg_quote(namespace.as_bytes(), Some(b"/")));
        any_tenant.extend_from_slice(b"_[A-Za-z0-9_-]*?_");
        any_tenant.extend(pcre::preg_quote(filtered.as_bytes(), Some(b"/")));
        any_tenant.extend_from_slice(b"_(.+)$/");
        let mut prepared =
            self.sqlite_prepare(&format!("PRAGMA index_list(`{table}`)"), Some(Event::CollectionRead)).await?;
        let rows = self.catalog_rows(&mut prepared).await?;
        let mut indexes: Vec<schema::Index> = Vec::new();
        for row in &rows {
            let name = scalar(row, "name").unwrap_or_default();
            let owned = name.starts_with(&own);
            let key = if owned {
                name[own.len()..].to_owned()
            } else if let Some(groups) = groups(&any_tenant, name.as_bytes()) {
                String::from_utf8_lossy(&groups[1]).into_owned()
            } else {
                name.clone()
            };
            let position = indexes.iter().position(|i| i.name == key);
            if !owned && position.is_some() {
                continue;
            }
            let columns = self.sqlite_index_columns(&name).await?;
            let index = schema::Index {
                name: key,
                kind: if field(row, "unique").is_some_and(Value::truthy) { IndexType::Unique } else { IndexType::Key },
                lengths: vec![None; columns.len()],
                columns,
            };
            match position {
                Some(p) => indexes[p] = index,
                None => indexes.push(index),
            }
        }
        indexes.extend(self.sqlite_fulltext_schema_indexes(collection).await?);
        Ok(indexes)
    }

    /// SQLite `getIndexColumns()`.
    async fn sqlite_index_columns(&self, index: &str) -> Result<Vec<String>> {
        let mut prepared =
            self.sqlite_prepare(&format!("PRAGMA index_info(`{index}`)"), Some(Event::CollectionRead)).await?;
        let rows = self.catalog_rows(&mut prepared).await?;
        let mut columns: Vec<(i64, String)> = Vec::new();
        for row in &rows {
            let position = match field(row, "seqno") {
                Some(v @ (Value::Str(_) | Value::Int(_) | Value::Float(_) | Value::Bool(_))) => {
                    crate::value::to_long(v)
                }
                _ => columns.len() as i64,
            };
            let name = scalar(row, "name").unwrap_or_default();
            match columns.iter_mut().find(|(p, _)| *p == position) {
                Some(entry) => entry.1 = name,
                None => columns.push((position, name)),
            }
        }
        columns.sort_by_key(|(p, _)| *p);
        Ok(columns.into_iter().map(|(_, c)| c).collect())
    }

    /// SQLite `getFulltextSchemaIndexes()`: the FTS5 tables of the
    /// collection, each under the key of the index it serves when known.
    async fn sqlite_fulltext_schema_indexes(&self, collection: &str) -> Result<Vec<schema::Index>> {
        let tables = self.find_fulltext_tables(collection).await?;
        if tables.is_empty() {
            return Ok(Vec::new());
        }
        let by_id = self.fulltext_tables_by_index_id(collection).await?;
        let mut indexes = Vec::with_capacity(tables.len());
        for table in tables {
            let mut prepared = self.sqlite_prepare(&format!("PRAGMA table_info(`{table}`)"), None).await?;
            {
                let mut pdo = self.connection.lock().await;
                prepared.statement.execute(&mut pdo).await?;
            }
            let rows = prepared.statement.fetch_all_assoc();
            prepared.statement.close_cursor();
            let columns: Vec<String> = rows
                .iter()
                .filter_map(|row| scalar(row, "name"))
                .filter(|name| !name.is_empty())
                .collect();
            // array_flip(): the last index naming a table wins.
            let name = by_id.iter().rev().find(|(_, t)| *t == table).map_or_else(|| table.clone(), |(k, _)| k.clone());
            indexes.push(schema::Index {
                name,
                kind: IndexType::Fulltext,
                lengths: vec![None; columns.len()],
                columns,
            });
        }
        Ok(indexes)
    }

    /// `getSchemaIndexType()`: Postgres keeps a fulltext index as a plain
    /// btree index, which its catalog reports as a key.
    pub(crate) fn schema_index_type(&self, kind: IndexType) -> IndexType {
        if self.engine == Engine::Postgres && kind == IndexType::Fulltext { IndexType::Key } else { kind }
    }

    /// `getColumnNames()`: the physical column names of a collection's
    /// table, empty when the table does not exist.
    pub(crate) async fn column_names(&self, collection: &str) -> Result<Vec<String>> {
        if self.engine != Engine::Postgres {
            return Ok(self.schema_attributes(collection).await?.into_iter().map(|c| c.name).collect());
        }
        let mut prepared = self
            .prepare_statement(
                "SELECT a.attname FROM pg_attribute a WHERE a.attrelid = to_regclass(?) AND a.attnum > 0 AND NOT a.attisdropped",
                Some(Event::CollectionRead),
            )
            .await?;
        prepared.bind_value(1, string(self.table(collection)), ParamType::Str)?;
        let columns = async {
            self.execute(&mut prepared).await?;
            let columns = prepared.statement.fetch_all_column(0)?;
            prepared.statement.close_cursor();
            Ok::<_, Error>(columns)
        }
        .await
        .map_err(|e| self.process_exception(e))?;
        Ok(columns.iter().map(crate::value::to_text).collect())
    }
}
