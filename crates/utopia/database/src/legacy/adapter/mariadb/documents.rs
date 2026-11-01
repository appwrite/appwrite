//! Documents: reads by id, creates, updates, upserts, deletes, sequence
//! lookups and atomic increments, with their permission rows.
//!
//! PHP: `SQL::getDocument`, `SQL::createDocuments`, `SQL::updateDocuments`,
//! `SQL::upsertDocuments`, `SQL::getSequences`, `SQL::deleteDocuments`,
//! `MariaDB::createDocument`, `MariaDB::updateDocument`,
//! `MariaDB::getUpsertStatement`, `MariaDB::increaseDocumentAttribute`,
//! `MariaDB::deleteDocument`.

use php_std::sort::SortFlags;

use super::MariaDB;
use super::pdo::{PdoFailure, Statement};
use super::sql::{self, Binds, bind_typed, filter, interpolate, quote};
use crate::change::Change;
use crate::database::Database;
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::operator::Operator;
use crate::query::{Method, Query};
use crate::value::{Array, ArrayKey, Ext, KeyRef, Str, Value};

/// `Query::DEFAULT_ALIAS`.
pub(super) const ALIAS: &str = "main";

/// `$document->getPermissions()`: the `$permissions` values without
/// duplicates (compared as strings), renumbered.
pub(super) fn permission_values(document: &Document) -> Vec<Value> {
    match document.get("$permissions") {
        Some(Value::Array(p)) => {
            let unique = php_std::sort::array_unique(p, SortFlags::String).unwrap_or_else(|_| p.clone());
            unique.values().cloned().collect()
        }
        _ => Vec::new(),
    }
}

/// `$document->getPermissionsByType($type)`: the roles of the permissions
/// of `type`, with the keys `array_unique` leaves them.
pub(super) fn permissions_by_type(document: &Document, kind: &str) -> Vec<(i64, Str)> {
    let mut list: Vec<Str> = Vec::new();
    for permission in permission_values(document) {
        let p = permission.to_php_string().unwrap_or(Str::EMPTY);
        if !p.as_bytes().starts_with(kind.as_bytes()) {
            continue;
        }
        let mut bytes = p.as_bytes().to_vec();
        for needle in [format!("{kind}(").into_bytes(), b")".to_vec(), b"\"".to_vec(), b" ".to_vec()] {
            bytes = php_std::string::str_replace(&needle, b"", &bytes).0.into_owned();
        }
        list.push(Str::from(bytes));
    }
    let mut out: Vec<(i64, Str)> = Vec::new();
    for (i, role) in list.into_iter().enumerate() {
        if !out.iter().any(|(_, r)| r.as_bytes() == role.as_bytes()) {
            out.push((i as i64, role));
        }
    }
    out
}

/// `array_diff($a, $b)` of role lists (string comparison), keys kept.
fn diff(a: &[(i64, Str)], b: &[(i64, Str)]) -> Vec<(i64, Str)> {
    a.iter().filter(|(_, x)| !b.iter().any(|(_, y)| y.as_bytes() == x.as_bytes())).cloned().collect()
}

/// A role as it is written into SQL: the bytes PHP interpolates (Rust's
/// statements are strings, so a role that is not UTF-8 is converted
/// lossily, as the Postgres adapter records).
fn text(s: &Str) -> String {
    String::from_utf8_lossy(s.as_bytes()).into_owned()
}

/// Whether a value is an operator (`Operator::isOperator`).
pub(super) fn as_operator(value: &Value) -> Option<&Operator> {
    match value {
        Value::Ext(Ext::Operator(o)) => Some(o),
        _ => None,
    }
}

/// `in_array($key, $spatialAttributes)` for an array key.
fn is_spatial(key: KeyRef<'_>, spatial: &[String]) -> bool {
    match key {
        KeyRef::Str(s) => spatial.iter().any(|a| a.as_bytes() == s),
        KeyRef::Int(_) => false,
    }
}

fn key_text(key: KeyRef<'_>) -> String {
    match key {
        KeyRef::Int(i) => i.to_string(),
        KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
    }
}

/// `(\is_bool($value)) ? (int)$value : $value` (integer booleans).
fn int_bool(value: Value) -> Value {
    match value {
        Value::Bool(b) => Value::Int(i64::from(b)),
        other => other,
    }
}

impl MariaDB {
    /// `getSpatialGeomFromText($placeholder, $srid)`: `ST_GeomFromText(:x,
    /// 4326)`, with MySQL's long-lat axis order.
    pub(super) fn geom_from_text(&self, placeholder: &str, srid: Option<i64>) -> String {
        let srid = srid.unwrap_or(Database::DEFAULT_SRID);
        if self.mysql() {
            format!("ST_GeomFromText({placeholder}, {srid}, 'axis-order=long-lat')")
        } else {
            format!("ST_GeomFromText({placeholder}, {srid})")
        }
    }

    /// `getDocument($collection, $id, $queries, $forUpdate)`.
    pub(super) async fn get_document_impl(
        &self,
        collection: &Document,
        id: &Value,
        queries: &[Query],
        for_update: bool,
    ) -> Result<Document> {
        let collection = collection.id().to_owned();
        let name = filter(&collection);
        let selections = selections(queries);
        let for_update = if for_update { "FOR UPDATE" } else { "" };
        let mut sql = format!(
            "\n\t\t    SELECT {}\n            FROM {} AS {}\n            WHERE {}.{} = :_uid \n            {}\n\t\t",
            projection(&selections, ALIAS)?,
            self.sql_table(&name),
            quote(ALIAS),
            quote(ALIAS),
            quote("_uid"),
            self.tenant_query_with(&collection, ALIAS, 0, "AND"),
        );
        sql.push_str(&format!(" {for_update}"));
        let sql = self.trigger_sql(Database::EVENT_DOCUMENT_READ, sql);
        let mut stmt = Statement::new(sql);
        stmt.bind_str(":_uid", id.clone());
        if self.shared() {
            stmt.bind_str(":_tenant", self.tenant_of_state());
        }
        let result = self.run(&stmt).await.map_err(|e| self.processed(e))?;
        match result.rows.into_iter().next() {
            None => Ok(Document::empty()),
            Some(row) => sql::row_to_document(row, false),
        }
    }

    /// `createDocument($collection, $document)` (MariaDB): the row, its
    /// `$sequence` from `lastInsertId()`, then its permission rows (clearing
    /// orphaned ones a failed delete left once).
    pub(super) async fn create_document_impl(&self, collection: &Document, mut document: Document) -> Result<Document> {
        let spatial = sql::spatial_attributes(collection);
        let collection = collection.id().to_owned();
        let shared = self.shared();
        let mut attributes = document.attributes();
        attributes.set(ArrayKey::from("_createdAt"), sql::string_attribute(&document, "$createdAt", "getCreatedAt")?);
        attributes.set(ArrayKey::from("_updatedAt"), sql::string_attribute(&document, "$updatedAt", "getUpdatedAt")?);
        attributes.set(
            ArrayKey::from("_permissions"),
            sql::json_encode(&Value::Array(permission_values(&document).into_iter().collect())),
        );
        if shared {
            attributes.set(ArrayKey::from("_tenant"), sql::tenant_value(&document));
        }
        let name = filter(&collection);
        let mut columns = String::new();
        let mut column_names = String::new();
        for (i, (key, _)) in attributes.iter().enumerate() {
            columns.push_str(&format!("`{}`, ", filter(&key_text(key))));
            if is_spatial(key, &spatial) {
                column_names.push_str(&format!("{}, ", self.geom_from_text(&format!(":key_{i}"), None)));
            } else {
                column_names.push_str(&format!(":key_{i}, "));
            }
        }
        let sequence = sql::sequence(&document)?;
        let has_sequence = sequence.as_ref().is_some_and(Value::truthy);
        if has_sequence {
            columns.push_str("_id, ");
            column_names.push_str(":_id, ");
        }
        let sql = format!(
            "\n\t\t\t    INSERT INTO {} ({columns} _uid)\n\t\t\t    VALUES ({column_names} :_uid)\n\t\t\t",
            self.sql_table(&name)
        );
        let sql = self.trigger_sql(Database::EVENT_DOCUMENT_CREATE, sql);
        let mut stmt = Statement::new(sql);
        stmt.bind_str(":_uid", sql::id_value(&document));
        if has_sequence {
            stmt.bind_str(":_id", sequence.clone().unwrap_or(Value::Null));
        }
        for (i, (_, value)) in attributes.iter().enumerate() {
            let value = if matches!(value, Value::Array(_)) { sql::json_encode(value) } else { value.clone() };
            bind_typed(&mut stmt, &format!(":key_{i}"), int_bool(value))?;
        }

        let mut permissions = Vec::new();
        for kind in Database::PERMISSIONS {
            for (_, permission) in permissions_by_type(&document, kind) {
                let tenant = if shared { ", :_tenant" } else { "" };
                let permission = text(&permission).replace('"', "");
                permissions.push(format!("('{kind}', '{permission}', :_uid {tenant})"));
            }
        }
        let mut stmt_permissions = None;
        if !permissions.is_empty() {
            let tenant = if shared { ", _tenant" } else { "" };
            let mut s = Statement::new(format!(
                "\n                    INSERT INTO {} (_type, _permission, _document {tenant})\n                    VALUES {};\n                ",
                self.sql_table(&format!("{name}_perms")),
                permissions.join(", ")
            ));
            s.bind_str(":_uid", sql::id_value(&document));
            if shared {
                s.bind_str(":_tenant", sql::tenant_value(&document));
            }
            stmt_permissions = Some(s);
        }

        self.run(&stmt).await.map_err(|e| self.processed(e))?;
        let last = self.pdo.lock().await.last_insert_id();
        document.set("$sequence", last.clone());
        if !last.truthy() {
            return Err(Error::database("Error creating document empty \"$sequence\""));
        }
        if let Some(s) = stmt_permissions {
            match self.run(&s).await {
                Ok(_) => {}
                Err(PdoFailure::Pdo(e))
                    if e.sqlstate == "23000" && e.native == Some(1062) && e.message.contains("_index1") =>
                {
                    let sql = format!(
                        "DELETE FROM {} WHERE _document = :_uid {}",
                        self.sql_table(&format!("{name}_perms")),
                        self.tenant_sql(&collection)
                    );
                    let mut cleanup = Statement::new(sql);
                    cleanup.bind_str(":_uid", sql::id_value(&document));
                    if shared {
                        cleanup.bind_str(":_tenant", sql::tenant_value(&document));
                    }
                    self.run(&cleanup).await.map_err(|e| self.processed(e))?;
                    self.run(&s).await.map_err(|e| self.processed(e))?;
                }
                Err(e) => return Err(self.processed(e)),
            }
        }
        Ok(document)
    }

    /// `createDocuments($collection, $documents)` (`SQL::createDocuments`):
    /// one insert for all rows (`INSERT IGNORE` when skipping duplicates),
    /// one for all permissions.
    pub(super) async fn create_documents_impl(
        &self,
        collection: &Document,
        documents: Vec<Document>,
    ) -> Result<Vec<Document>> {
        if documents.is_empty() {
            return Ok(documents);
        }
        let spatial = sql::spatial_attributes(collection);
        let collection = collection.id().to_owned();
        let name = filter(&collection);
        let shared = self.shared();
        let insert = if self.state.lock().skip_duplicates { "INSERT IGNORE INTO" } else { "INSERT INTO" };

        let mut keys: Vec<Value> = Database::INTERNAL_ATTRIBUTE_KEYS.iter().map(|k| Value::Str((*k).into())).collect();
        let mut has_sequence: Option<bool> = None;
        for document in &documents {
            for (k, _) in document.attributes().iter() {
                keys.push(match k {
                    KeyRef::Int(i) => Value::Int(i),
                    KeyRef::Str(s) => Value::Str(Str::from(s.to_vec())),
                });
            }
            let has = sql::has_sequence(document)?;
            match has_sequence {
                None => has_sequence = Some(has),
                Some(h) if h != has => {
                    return Err(Error::database("All documents must have an sequence if one is set"));
                }
                _ => {}
            }
        }
        let unique = php_std::sort::array_unique(&keys.into_iter().collect::<Array>(), SortFlags::String)
            .map_err(Error::from)?;
        let mut keys: Vec<ArrayKey> = unique
            .values()
            .map(|v| match v {
                Value::Int(i) => ArrayKey::Int(*i),
                other => ArrayKey::normalize(other.to_php_string().unwrap_or(Str::EMPTY)),
            })
            .collect();
        if has_sequence == Some(true) {
            keys.push(ArrayKey::from("_id"));
        }
        if shared {
            keys.push(ArrayKey::from("_tenant"));
        }
        let columns =
            format!("({})", keys.iter().map(|k| quote(&filter(&key_text(k.as_ref())))).collect::<Vec<_>>().join(", "));

        let mut bind_index = 0usize;
        let mut batch_keys = Vec::new();
        let mut bind_values: Vec<(String, Value)> = Vec::new();
        let mut permissions = Vec::new();
        let mut bind_permissions = Binds::default();
        for (index, document) in documents.iter().enumerate() {
            let mut attributes = document.attributes();
            attributes.set(ArrayKey::from("_uid"), sql::id_value(document));
            attributes
                .set(ArrayKey::from("_createdAt"), sql::string_attribute(document, "$createdAt", "getCreatedAt")?);
            attributes
                .set(ArrayKey::from("_updatedAt"), sql::string_attribute(document, "$updatedAt", "getUpdatedAt")?);
            attributes.set(
                ArrayKey::from("_permissions"),
                sql::json_encode(&Value::Array(permission_values(document).into_iter().collect())),
            );
            if sql::has_sequence(document)? {
                attributes.set(ArrayKey::from("_id"), sql::sequence(document)?.unwrap_or(Value::Null));
            }
            if shared {
                attributes.set(ArrayKey::from("_tenant"), sql::tenant_value(document));
            }
            let mut bind_keys = Vec::new();
            for key in &keys {
                let mut value = attributes.get(key).cloned().unwrap_or(Value::Null);
                if matches!(value, Value::Array(_)) {
                    value = sql::json_encode(&value);
                }
                let bind_key = format!("key_{bind_index}");
                if is_spatial(key.as_ref(), &spatial) {
                    bind_keys.push(self.geom_from_text(&format!(":{bind_key}"), None));
                } else {
                    value = int_bool(value);
                    bind_keys.push(format!(":{bind_key}"));
                }
                bind_values.push((bind_key, value));
                bind_index += 1;
            }
            batch_keys.push(format!("({})", bind_keys.join(", ")));
            for kind in Database::PERMISSIONS {
                for (_, permission) in permissions_by_type(document, kind) {
                    let tenant_bind = if shared { format!(", :_tenant_{index}") } else { String::new() };
                    let permission = text(&permission).replace('"', "");
                    permissions.push(format!("('{kind}', '{permission}', :_uid_{index} {tenant_bind})"));
                    bind_permissions.set(format!(":_uid_{index}"), sql::id_value(document));
                    if shared {
                        bind_permissions.set(format!(":_tenant_{index}"), sql::tenant_value(document));
                    }
                }
            }
        }
        let mut stmt = Statement::new(format!(
            "\n                {insert} {} {columns}\n                VALUES {}\n                \n            ",
            self.sql_table(&name),
            batch_keys.join(", ")
        ));
        for (k, v) in bind_values {
            bind_typed(&mut stmt, &k, v)?;
        }
        self.run(&stmt).await.map_err(|e| self.processed(e))?;
        if !permissions.is_empty() {
            let tenant_column = if shared { ", _tenant" } else { "" };
            let mut stmt = Statement::new(format!(
                "\n                    {insert} {} (_type, _permission, _document {tenant_column})\n                    VALUES {}\n                    \n                ",
                self.sql_table(&format!("{name}_perms")),
                permissions.join(", ")
            ));
            for (k, v) in bind_permissions.0 {
                bind_typed(&mut stmt, &k, v)?;
            }
            self.run(&stmt).await.map_err(|e| self.processed(e))?;
        }
        Ok(documents)
    }

    /// `updateDocument($collection, $id, $document, $skipPermissions)`
    /// (MariaDB).
    pub(super) async fn update_document_impl(
        &self,
        collection: &Document,
        id: &str,
        document: Document,
        skip_permissions: bool,
    ) -> Result<Document> {
        let spatial = sql::spatial_attributes(collection);
        let collection = collection.id().to_owned();
        let shared = self.shared();
        let mut attributes = document.attributes();
        attributes.set(ArrayKey::from("_createdAt"), sql::string_attribute(&document, "$createdAt", "getCreatedAt")?);
        attributes.set(ArrayKey::from("_updatedAt"), sql::string_attribute(&document, "$updatedAt", "getUpdatedAt")?);
        attributes.set(
            ArrayKey::from("_permissions"),
            sql::json_encode(&Value::Array(permission_values(&document).into_iter().collect())),
        );
        attributes.set(ArrayKey::from("_uid"), sql::id_value(&document));
        let name = filter(&collection);

        let mut stmt_remove = None;
        let mut stmt_add = None;
        if !skip_permissions {
            let new_uid = if document.has("$id") { sql::id_value(&document) } else { Value::Str(id.into()) };
            let sql = format!(
                "\n\t\t\t    DELETE FROM {}\n\t\t\t    WHERE _document = :_uid\n\t\t\t    {}\n\t\t\t    ",
                self.sql_table(&format!("{name}_perms")),
                self.tenant_sql(&collection)
            );
            let sql = self.trigger_sql(Database::EVENT_PERMISSIONS_DELETE, sql);
            let mut s = Statement::new(sql);
            s.bind_str(":_uid", Value::Str(id.into()));
            if shared {
                s.bind_str(":_tenant", sql::tenant_value(&document));
            }
            stmt_remove = Some(s);

            let mut values = Vec::new();
            let mut binds = Binds::default();
            for kind in Database::PERMISSIONS {
                for (i, permission) in permissions_by_type(&document, kind) {
                    let tenant = if shared { ", :_tenant" } else { "" };
                    values.push(format!("( :_uid, '{kind}', :_add_{kind}_{i} {tenant})"));
                    binds.set(format!(":_add_{kind}_{i}"), Value::Str(permission));
                }
            }
            if !values.is_empty() {
                let tenant = if shared { ", _tenant" } else { "" };
                let sql = format!(
                    "\n\t\t\t\t    INSERT INTO {} (_document, _type, _permission {tenant})\n\t\t\t\t    VALUES {}",
                    self.sql_table(&format!("{name}_perms")),
                    values.join(", ")
                );
                let sql = self.trigger_sql(Database::EVENT_PERMISSIONS_CREATE, sql);
                let mut s = Statement::new(sql);
                s.bind_str(":_uid", new_uid);
                if shared {
                    s.bind_str(":_tenant", sql::tenant_value(&document));
                }
                for (k, v) in binds.0 {
                    s.bind_str(&k, v);
                }
                stmt_add = Some(s);
            }
        }

        let mut key_index = 0usize;
        let mut operator_binds = Binds::default();
        let mut columns = String::new();
        for (key, value) in attributes.iter() {
            let column = filter(&key_text(key));
            if let Some(op) = as_operator(value) {
                columns.push_str(&self.operator_sql(&column, op, &mut operator_binds)?);
                columns.push(',');
            } else {
                if is_spatial(key, &spatial) {
                    columns
                        .push_str(&format!("`{column}`={},", self.geom_from_text(&format!(":key_{key_index}"), None)));
                } else {
                    columns.push_str(&format!("`{column}`=:key_{key_index},"));
                }
                key_index += 1;
            }
        }
        let sql = format!(
            "\n                UPDATE {}\n                SET {}\n                WHERE _id=:_sequence\n                {}\n\t\t\t",
            self.sql_table(&name),
            columns.trim_end_matches(','),
            self.tenant_sql(&collection)
        );
        let sql = self.trigger_sql(Database::EVENT_DOCUMENT_UPDATE, sql);
        let mut stmt = Statement::new(sql);
        stmt.bind_str(":_sequence", sql::sequence(&document)?.unwrap_or(Value::Null));
        if shared {
            stmt.bind_str(":_tenant", self.tenant_of_state());
        }
        let mut key_index = 0usize;
        for (key, value) in attributes.iter() {
            if as_operator(value).is_some() {
                continue;
            }
            let value = if is_spatial(key, &spatial) {
                match value {
                    Value::Array(a) => Value::Str(super::spatial::array_to_wkt(a)?.into()),
                    other => other.clone(),
                }
            } else if matches!(value, Value::Array(_)) {
                sql::json_encode(value)
            } else {
                value.clone()
            };
            bind_typed(&mut stmt, &format!(":key_{key_index}"), int_bool(value))?;
            key_index += 1;
        }
        for (k, v) in operator_binds.0 {
            bind_typed(&mut stmt, &k, v)?;
        }
        self.run(&stmt).await.map_err(|e| self.processed(e))?;
        if let Some(s) = stmt_remove {
            self.run(&s).await.map_err(|e| self.processed(e))?;
        }
        if let Some(s) = stmt_add {
            self.run(&s).await.map_err(|e| self.processed(e))?;
        }
        Ok(document)
    }

    /// `updateDocuments($collection, $updates, $documents)`
    /// (`SQL::updateDocuments`): one update for every document, then their
    /// permission changes.
    pub(super) async fn update_documents_impl(
        &self,
        collection: &Document,
        updates: &Document,
        documents: &[Document],
    ) -> Result<i64> {
        if documents.is_empty() {
            return Ok(0);
        }
        let spatial = sql::spatial_attributes(collection);
        let collection = collection.id().to_owned();
        let shared = self.shared();
        let mut attributes = updates.attributes();
        let updated_at = sql::string_attribute(updates, "$updatedAt", "getUpdatedAt")?;
        if updated_at.truthy() {
            attributes.set(ArrayKey::from("_updatedAt"), updated_at);
        }
        let created_at = sql::string_attribute(updates, "$createdAt", "getCreatedAt")?;
        if created_at.truthy() {
            attributes.set(ArrayKey::from("_createdAt"), created_at);
        }
        let has_permissions = updates.has("$permissions");
        if has_permissions {
            attributes.set(
                ArrayKey::from("_permissions"),
                sql::json_encode(&Value::Array(permission_values(updates).into_iter().collect())),
            );
        }
        if attributes.is_empty() {
            return Ok(0);
        }
        let mut key_index = 0usize;
        let mut operator_binds = Binds::default();
        let mut columns = String::new();
        let count = attributes.len();
        for (n, (key, value)) in attributes.iter().enumerate() {
            let column = filter(&key_text(key));
            if let Some(op) = as_operator(value) {
                columns.push_str(&self.operator_sql(&column, op, &mut operator_binds)?);
            } else if is_spatial(key, &spatial) {
                columns.push_str(&format!(
                    "{} = {}",
                    quote(&column),
                    self.geom_from_text(&format!(":key_{key_index}"), None)
                ));
                key_index += 1;
            } else {
                columns.push_str(&format!("{} = :key_{key_index}", quote(&column)));
                key_index += 1;
            }
            if n + 1 != count {
                columns.push(',');
            }
        }
        let columns = columns.trim_end_matches(',').to_owned();
        if columns.is_empty() {
            return Ok(0);
        }
        let name = filter(&collection);
        let sequences: Vec<Value> =
            documents.iter().map(|d| sql::sequence(d).map(|s| s.unwrap_or(Value::Null))).collect::<Result<_>>()?;
        let placeholders = (0..sequences.len()).map(|i| format!(":_id_{i}")).collect::<Vec<_>>().join(", ");
        let sql = format!(
            "\n            UPDATE {}\n            SET {columns}\n            WHERE _id IN ({placeholders})\n            {}\n        ",
            self.sql_table(&name),
            self.tenant_sql(&collection)
        );
        let sql = self.trigger_sql(Database::EVENT_DOCUMENTS_UPDATE, sql);
        let mut stmt = Statement::new(sql);
        if shared {
            stmt.bind_str(":_tenant", self.tenant_of_state());
        }
        for (i, v) in sequences.iter().enumerate() {
            stmt.bind_str(&format!(":_id_{i}"), v.clone());
        }
        let mut key_index = 0usize;
        for (key, value) in attributes.iter() {
            if as_operator(value).is_some() {
                continue;
            }
            let value = if is_spatial(key, &spatial) {
                match value {
                    Value::Array(a) => Value::Str(super::spatial::array_to_wkt(a)?.into()),
                    other => other.clone(),
                }
            } else if matches!(value, Value::Array(_)) {
                sql::json_encode(value)
            } else {
                value.clone()
            };
            bind_typed(&mut stmt, &format!(":key_{key_index}"), int_bool(value))?;
            key_index += 1;
        }
        for (k, v) in operator_binds.0 {
            bind_typed(&mut stmt, &k, v)?;
        }
        let affected = self.run(&stmt).await.map_err(|e| self.processed(e))?.count;

        if has_permissions {
            let perms_table = self.sql_table(&format!("{name}_perms"));
            let mut remove_queries: Vec<String> = Vec::new();
            let mut remove_binds = Binds::default();
            let mut add_query = String::new();
            let mut add_binds = Binds::default();
            let last_index = documents.len() - 1;
            for (index, document) in documents.iter().enumerate() {
                if document.get("$skipPermissionsUpdate").is_some_and(Value::truthy) {
                    continue;
                }
                let sql = format!(
                    "\n                    SELECT _type, _permission\n                    FROM {perms_table}\n                    WHERE _document = :_uid\n                    {}\n                ",
                    self.tenant_sql(&collection)
                );
                let sql = self.trigger_sql(Database::EVENT_PERMISSIONS_READ, sql);
                let mut s = Statement::new(sql);
                s.bind_str(":_uid", sql::id_value(document));
                if shared {
                    s.bind_str(":_tenant", self.tenant_of_state());
                }
                let rows = self.run(&s).await.map_err(sql::raw)?.rows;
                let mut current: Vec<(String, Vec<(i64, Str)>)> =
                    Database::PERMISSIONS.iter().map(|t| ((*t).to_owned(), Vec::new())).collect();
                for row in rows {
                    let t = row.get_str("_type").map(interpolate).unwrap_or_default();
                    let p = match row.get_str("_permission") {
                        Some(Value::Str(s)) => s.clone(),
                        _ => Str::EMPTY,
                    };
                    match current.iter_mut().find(|(k, _)| *k == t) {
                        Some((_, list)) => {
                            let n = list.len() as i64;
                            list.push((n, p));
                        }
                        None => current.push((t, vec![(0, p)])),
                    }
                }
                let current_of =
                    |t: &str| current.iter().find(|(k, _)| k == t).map(|(_, l)| l.clone()).unwrap_or_default();

                let mut removals: Vec<(&str, Vec<(i64, Str)>)> = Vec::new();
                for kind in Database::PERMISSIONS {
                    let d = diff(&current_of(kind), &permissions_by_type(updates, kind));
                    if !d.is_empty() {
                        removals.push((kind, d));
                    }
                }
                for (kind, to_remove) in &removals {
                    remove_binds.set(format!("_uid_{index}"), sql::id_value(document));
                    let placeholders = to_remove
                        .iter()
                        .map(|(i, p)| {
                            let key = format!("remove_{kind}_{index}_{i}");
                            remove_binds.set(key.clone(), Value::Str(p.clone()));
                            format!(":{key}")
                        })
                        .collect::<Vec<_>>()
                        .join(", ");
                    remove_queries.push(format!(
                        "(\n                            _document = :_uid_{index}\n                            {}\n                            AND _type = '{kind}'\n                            AND _permission IN ({placeholders})\n                        )",
                        self.tenant_sql(&collection)
                    ));
                }

                let mut additions: Vec<(&str, Vec<(i64, Str)>)> = Vec::new();
                for kind in Database::PERMISSIONS {
                    let d = diff(&permissions_by_type(updates, kind), &current_of(kind));
                    if !d.is_empty() {
                        additions.push((kind, d));
                    }
                }
                if !additions.is_empty() {
                    let last_type = additions.len() - 1;
                    for (t, (kind, to_add)) in additions.iter().enumerate() {
                        let last_i = to_add.len() - 1;
                        for (n, (i, permission)) in to_add.iter().enumerate() {
                            add_binds.set(format!("_uid_{index}"), sql::id_value(document));
                            let key = format!("add_{kind}_{index}_{i}");
                            add_binds.set(key.clone(), Value::Str(permission.clone()));
                            add_query.push_str(&format!("(:_uid_{index}, '{kind}', :{key}"));
                            add_query.push_str(if shared { ", :_tenant)" } else { ")" });
                            if n != last_i || t != last_type {
                                add_query.push_str(", ");
                            }
                        }
                    }
                    if index != last_index {
                        add_query.push_str(", ");
                    }
                }
            }
            if !remove_queries.is_empty() {
                let mut s = Statement::new(format!(
                    "\n                    DELETE\n                    FROM {perms_table}\n                    WHERE ({})\n                ",
                    remove_queries.join(" OR ")
                ));
                for (k, v) in remove_binds.0 {
                    bind_typed(&mut s, &k, v)?;
                }
                if shared {
                    s.bind_str(":_tenant", self.tenant_of_state());
                }
                self.run(&s).await.map_err(sql::raw)?;
            }
            if !add_query.is_empty() {
                let mut sql = format!(
                    "\n                    INSERT INTO {perms_table} (_document, _type, _permission\n                "
                );
                sql.push_str(if shared { ", _tenant)" } else { ")" });
                sql.push_str(&format!(" VALUES {add_query}"));
                let mut s = Statement::new(sql);
                for (k, v) in add_binds.0 {
                    bind_typed(&mut s, &k, v)?;
                }
                if shared {
                    s.bind_str(":_tenant", self.tenant_of_state());
                }
                self.run(&s).await.map_err(sql::raw)?;
            }
        }
        Ok(affected)
    }

    /// `upsertDocuments($collection, $attribute, $changes)`.
    pub(super) async fn upsert_documents_impl(
        &self,
        collection: &Document,
        attribute: &str,
        changes: Vec<Change>,
    ) -> Result<Vec<Document>> {
        if changes.is_empty() {
            return Ok(Vec::new());
        }
        let result = self.upsert_inner(collection, attribute, &changes).await;
        // Operator::extractOperators() names each operator after its key, on
        // the documents' own operator objects: the returned documents show it.
        let mut changes = changes;
        for change in &mut changes {
            for (key, value) in change.new.attributes().iter() {
                if let Some(op) = as_operator(value)
                    && op.attribute().is_empty()
                {
                    let mut op = op.clone();
                    let key = key_text(key);
                    op.set_attribute(key.clone());
                    change.new.set(key.as_str(), Value::Ext(Ext::Operator(Box::new(op))));
                }
            }
        }
        match result {
            Ok(()) => Ok(changes.into_iter().map(|c| c.new).collect()),
            Err(PdoFailure::Pdo(e)) => Err(self.process(e)),
            Err(PdoFailure::Other(e)) => Err(e),
        }
    }

    async fn upsert_inner(&self, collection: &Document, attribute: &str, changes: &[Change]) -> Result<(), PdoFailure> {
        let spatial = sql::spatial_attributes(collection);
        let mut defaults: Vec<(ArrayKey, Value)> = Vec::new();
        if let Some(Value::Array(attrs)) = collection.get("attributes") {
            for attr in attrs.values() {
                let (id, default) = match attr {
                    Value::Ext(Ext::Document(d)) => (d.raw("$id").cloned(), d.raw("default").cloned()),
                    Value::Array(a) => (a.get_str("$id").cloned(), a.get_str("default").cloned()),
                    _ => (None, None),
                };
                let key =
                    ArrayKey::normalize(id.map(|v| v.to_php_string().unwrap_or(Str::EMPTY)).unwrap_or(Str::EMPTY));
                let default = default.unwrap_or(Value::Null);
                match defaults.iter_mut().find(|(k, _)| *k == key) {
                    Some(slot) => slot.1 = default,
                    None => defaults.push((key, default)),
                }
            }
        }
        let collection_id = collection.id().to_owned();
        let name = filter(&collection_id);
        let shared = self.shared();

        let operators_of = |d: &Document| -> Vec<(ArrayKey, Operator)> {
            d.attributes()
                .iter()
                .filter_map(|(k, v)| {
                    as_operator(v).map(|o| {
                        let mut o = o.clone();
                        if o.attribute().is_empty() {
                            o.set_attribute(key_text(k));
                        }
                        (k.to_owned(), o)
                    })
                })
                .collect()
        };
        let has_operators = changes.iter().any(|c| !operators_of(&c.new).is_empty());

        // Groups of changes sharing the same operators.
        #[allow(clippy::type_complexity)]
        let mut groups: Vec<(String, Vec<&Change>, Vec<(ArrayKey, Operator)>)> = Vec::new();
        if !has_operators {
            groups.push((String::new(), changes.iter().collect(), Vec::new()));
        } else {
            for change in changes {
                let operators = operators_of(&change.new);
                let signature = if operators.is_empty() {
                    "no_ops".to_owned()
                } else {
                    let mut parts: Array = operators
                        .iter()
                        .map(|(k, o)| {
                            Value::Str(Str::from(format!(
                                "{}:{}:{}",
                                key_text(k.as_ref()),
                                o.method().as_str(),
                                sql::json_text(&Value::Array(o.values().iter().cloned().collect()))
                            )))
                        })
                        .collect();
                    php_std::sort::sort(&mut parts, SortFlags::Regular).map_err(|e| PdoFailure::Other(e.into()))?;
                    parts.values().map(interpolate).collect::<Vec<_>>().join("|")
                };
                match groups.iter_mut().find(|(s, _, _)| *s == signature) {
                    Some((_, docs, _)) => docs.push(change),
                    None => groups.push((signature, vec![change], operators)),
                }
            }
        }

        for (_, group, operators) in &groups {
            let mut bind_index = 0usize;
            let mut batch_keys = Vec::new();
            let mut bind_values: Vec<(String, Value)> = Vec::new();
            let mut column_names: Vec<ArrayKey> = Vec::new();
            let mut data: Vec<Array> = Vec::new();
            for change in group {
                let document = &change.new;
                let mut regular = Array::new();
                for (k, v) in document.attributes().iter() {
                    if !has_operators || as_operator(v).is_none() {
                        regular.set(k.to_owned(), v.clone());
                    }
                }
                let extracted = operators_of(document);
                if has_operators && change.old.is_empty() && !extracted.is_empty() {
                    for (key, op) in &extracted {
                        let default =
                            defaults.iter().find(|(k, _)| k == key).map(|(_, v)| v.clone()).unwrap_or(Value::Null);
                        regular.set(key.clone(), super::operators::apply(op, default).map_err(PdoFailure::Other)?);
                    }
                }
                regular.set(ArrayKey::from("_uid"), sql::id_value(document));
                let created =
                    sql::string_attribute(document, "$createdAt", "getCreatedAt").map_err(PdoFailure::Other)?;
                let updated =
                    sql::string_attribute(document, "$updatedAt", "getUpdatedAt").map_err(PdoFailure::Other)?;
                let (created, updated) = if has_operators {
                    (
                        if created.truthy() { created } else { Value::Null },
                        if updated.truthy() { updated } else { Value::Null },
                    )
                } else {
                    (
                        if created.truthy() {
                            Value::Str(set_timezone(&created).map_err(PdoFailure::Other)?)
                        } else {
                            Value::Null
                        },
                        if updated.truthy() {
                            Value::Str(set_timezone(&updated).map_err(PdoFailure::Other)?)
                        } else {
                            Value::Null
                        },
                    )
                };
                regular.set(ArrayKey::from("_createdAt"), created);
                regular.set(ArrayKey::from("_updatedAt"), updated);
                regular.set(
                    ArrayKey::from("_permissions"),
                    sql::json_encode(&Value::Array(permission_values(document).into_iter().collect())),
                );
                if sql::has_sequence(document).map_err(PdoFailure::Other)? {
                    regular.set(
                        ArrayKey::from("_id"),
                        sql::sequence(document).map_err(PdoFailure::Other)?.unwrap_or(Value::Null),
                    );
                }
                if shared {
                    regular.set(ArrayKey::from("_tenant"), sql::tenant_value(document));
                }
                for (k, _) in regular.iter() {
                    let k = k.to_owned();
                    if !column_names.contains(&k) {
                        column_names.push(k);
                    }
                }
                data.push(regular);
            }
            for (k, _) in operators {
                if !column_names.contains(k) {
                    column_names.push(k.clone());
                }
            }
            // sort($allColumnNames)
            let mut sortable: Array = column_names
                .iter()
                .map(|k| match k {
                    ArrayKey::Int(i) => Value::Int(*i),
                    ArrayKey::Str(s) => Value::Str(s.clone()),
                })
                .collect();
            php_std::sort::sort(&mut sortable, SortFlags::Regular).map_err(|e| PdoFailure::Other(e.into()))?;
            let column_names: Vec<ArrayKey> = sortable
                .values()
                .map(|v| match v {
                    Value::Int(i) => ArrayKey::Int(*i),
                    Value::Str(s) => ArrayKey::Str(s.clone()),
                    _ => ArrayKey::Int(0),
                })
                .collect();
            let columns = format!(
                "({})",
                column_names.iter().map(|k| quote(&filter(&key_text(k.as_ref())))).collect::<Vec<_>>().join(", ")
            );
            for regular in &data {
                let mut bind_keys = Vec::new();
                for key in &column_names {
                    let mut value = regular.get(key).cloned().unwrap_or(Value::Null);
                    if matches!(value, Value::Array(_)) {
                        value = sql::json_encode(&value);
                    }
                    let bind_key = format!("key_{bind_index}");
                    if is_spatial(key.as_ref(), &spatial) && !matches!(value, Value::Null) {
                        bind_keys.push(self.geom_from_text(&format!(":{bind_key}"), None));
                    } else {
                        value = int_bool(value);
                        bind_keys.push(format!(":{bind_key}"));
                    }
                    bind_values.push((bind_key, value));
                    bind_index += 1;
                }
                batch_keys.push(format!("({})", bind_keys.join(", ")));
            }
            let stmt = self
                .upsert_statement(
                    &name,
                    &columns,
                    &batch_keys,
                    &column_names,
                    bind_values,
                    if has_operators { "" } else { attribute },
                    operators,
                )
                .map_err(PdoFailure::Other)?;
            self.run(&stmt).await?;
        }

        // Permissions.
        let perms_table = self.sql_table(&format!("{name}_perms"));
        let mut remove_queries = Vec::new();
        let mut remove_binds = Binds::default();
        let mut add_queries = Vec::new();
        let mut add_binds = Binds::default();
        for (index, change) in changes.iter().enumerate() {
            let document = &change.new;
            for kind in Database::PERMISSIONS {
                let to_remove = diff(&permissions_by_type(&change.old, kind), &permissions_by_type(document, kind));
                if !to_remove.is_empty() {
                    let placeholders = to_remove
                        .iter()
                        .map(|(i, _)| format!(":remove_{kind}_{index}_{i}"))
                        .collect::<Vec<_>>()
                        .join(",");
                    let tenant = if shared { format!(" AND _tenant = :_tenant_{index}") } else { String::new() };
                    remove_queries.push(format!(
                        "(\n                            _document = :_uid_{index}\n                            {tenant}\n                            AND _type = '{kind}'\n                            AND _permission IN ({placeholders})\n                        )"
                    ));
                    remove_binds.set(format!(":_uid_{index}"), sql::id_value(document));
                    if shared {
                        remove_binds.set(format!(":_tenant_{index}"), sql::tenant_value(document));
                    }
                    for (i, p) in &to_remove {
                        remove_binds.set(format!(":remove_{kind}_{index}_{i}"), Value::Str(p.clone()));
                    }
                }
            }
            for kind in Database::PERMISSIONS {
                let to_add = diff(&permissions_by_type(document, kind), &permissions_by_type(&change.old, kind));
                for (i, permission) in to_add {
                    let mut q = format!("(:_uid_{index}, '{kind}', :add_{kind}_{index}_{i}");
                    if shared {
                        q.push_str(&format!(", :_tenant_{index}"));
                    }
                    q.push(')');
                    add_queries.push(q);
                    add_binds.set(format!(":_uid_{index}"), sql::id_value(document));
                    add_binds.set(format!(":add_{kind}_{index}_{i}"), Value::Str(permission));
                    if shared {
                        add_binds.set(format!(":_tenant_{index}"), sql::tenant_value(document));
                    }
                }
            }
        }
        if !remove_queries.is_empty() {
            let mut s = Statement::new(format!("DELETE FROM {perms_table} WHERE {}", remove_queries.join(" OR ")));
            for (k, v) in remove_binds.0 {
                bind_typed(&mut s, &k, v).map_err(PdoFailure::Other)?;
            }
            self.run(&s).await?;
        }
        if !add_queries.is_empty() {
            let mut sql = format!("INSERT INTO {perms_table} (_document, _type, _permission");
            if shared {
                sql.push_str(", _tenant");
            }
            sql.push_str(&format!(") VALUES {}", add_queries.join(", ")));
            let mut s = Statement::new(sql);
            for (k, v) in add_binds.0 {
                bind_typed(&mut s, &k, v).map_err(PdoFailure::Other)?;
            }
            self.run(&s).await?;
        }
        Ok(())
    }

    /// `getUpsertStatement(...)` (MariaDB): `INSERT ... ON DUPLICATE KEY
    /// UPDATE`, incrementing `attribute` when one is given, applying the
    /// operators otherwise; with shared tables a row of another tenant is
    /// left as it is.
    #[allow(clippy::too_many_arguments)]
    fn upsert_statement(
        &self,
        table: &str,
        columns: &str,
        batch_keys: &[String],
        attributes: &[ArrayKey],
        bind_values: Vec<(String, Value)>,
        attribute: &str,
        operators: &[(ArrayKey, Operator)],
    ) -> Result<Statement> {
        let shared = self.shared();
        let clause = |attribute: &str, increment: bool| -> String {
            let a = quote(&filter(attribute));
            let new = if increment { format!("{a} + VALUES({a})") } else { format!("VALUES({a})") };
            if shared { format!("{a} = IF(_tenant = VALUES(_tenant), {new}, {a})") } else { format!("{a} = {new}") }
        };
        let mut operator_binds = Binds::default();
        let mut update_columns = Vec::new();
        if !attribute.is_empty() && attribute != "0" {
            update_columns.push(clause(attribute, true));
            update_columns.push(clause("_updatedAt", false));
        } else {
            for key in attributes {
                let attr = key_text(key.as_ref());
                let filtered = filter(&attr);
                if let Some((_, op)) = operators.iter().find(|(k, _)| k == key) {
                    update_columns.push(self.operator_sql(&filtered, op, &mut operator_binds)?);
                } else if !matches!(key, ArrayKey::Str(_))
                    || !["_uid", "_id", "_createdAt", "_tenant"].contains(&attr.as_str())
                {
                    update_columns.push(clause(&filtered, false));
                }
            }
        }
        let mut stmt = Statement::new(format!(
            "\n            INSERT INTO {} {columns}\n            VALUES {}\n            ON DUPLICATE KEY UPDATE\n                {}",
            self.sql_table(table),
            batch_keys.join(", "),
            update_columns.join(", ")
        ));
        for (k, v) in bind_values {
            bind_typed(&mut stmt, &k, v)?;
        }
        for (k, v) in operator_binds.0 {
            bind_typed(&mut stmt, &k, v)?;
        }
        Ok(stmt)
    }

    /// `getSequences($collection, $documents)`: fills `$sequence` of the
    /// documents without one from their rows.
    pub(super) async fn get_sequences_impl(
        &self,
        collection: &str,
        mut documents: Vec<Document>,
    ) -> Result<Vec<Document>> {
        let shared = self.shared();
        let mut keys = Vec::new();
        let mut binds = Binds::default();
        let mut tenants: Vec<Value> = Vec::new();
        for (i, document) in documents.iter().enumerate() {
            if !sql::has_sequence(document)? {
                let key = format!(":uid_{i}");
                binds.set(key.clone(), sql::id_value(document));
                keys.push(key);
                if shared {
                    let tenant = sql::tenant_value(document);
                    if !tenants.iter().any(|t| t.strict_eq(&tenant)) {
                        binds.set(format!(":_tenant_{}", tenants.len()), tenant.clone());
                        tenants.push(tenant);
                    }
                }
            }
        }
        if keys.is_empty() {
            return Ok(documents);
        }
        let sql = format!(
            "\n            SELECT _uid, _id\n            FROM {}\n            WHERE {} IN ({})\n            {}\n            ",
            self.sql_table(collection),
            quote("_uid"),
            keys.join(","),
            self.tenant_query_with(collection, "", tenants.len(), "AND")
        );
        let mut stmt = Statement::new(sql);
        for (k, v) in binds.0 {
            stmt.bind_str(&k, v);
        }
        let rows = self.run(&stmt).await.map_err(sql::raw)?.rows;
        let mut pairs = Array::new();
        for row in rows {
            let mut values = row.values();
            let uid = values.next().cloned().unwrap_or(Value::Null);
            let seq = values.next().cloned().unwrap_or(Value::Null);
            pairs.set(ArrayKey::normalize(uid.to_php_string().unwrap_or(Str::EMPTY)), seq);
        }
        for document in &mut documents {
            let id = ArrayKey::normalize(sql::id_value(document).to_php_string().unwrap_or(Str::EMPTY));
            if let Some(seq) = pairs.get(&id).filter(|v| !matches!(v, Value::Null)) {
                document.set("$sequence", seq.clone());
            }
        }
        Ok(documents)
    }

    /// `deleteDocument($collection, $id)` (MariaDB): the row and its
    /// permissions.
    pub(super) async fn delete_document_impl(&self, collection: &str, id: &str) -> Result<bool> {
        let name = filter(collection);
        let shared = self.shared();
        let sql = format!(
            "\n                DELETE FROM {} \n                WHERE _uid = :_uid\n                {}\n\t\t    ",
            self.sql_table(&name),
            self.tenant_sql(collection)
        );
        let sql = self.trigger_sql(Database::EVENT_DOCUMENT_DELETE, sql);
        let mut stmt = Statement::new(sql);
        stmt.bind_str(":_uid", Value::Str(id.into()));
        if shared {
            stmt.bind_str(":_tenant", self.tenant_of_state());
        }
        let sql = format!(
            "\n\t\t\t    DELETE FROM {} \n\t\t        WHERE _document = :_uid\n\t\t        {}\n\t\t    ",
            self.sql_table(&format!("{name}_perms")),
            self.tenant_sql(collection)
        );
        let sql = self.trigger_sql(Database::EVENT_PERMISSIONS_DELETE, sql);
        let mut perms = Statement::new(sql);
        perms.bind_str(":_uid", Value::Str(id.into()));
        if shared {
            perms.bind_str(":_tenant", self.tenant_of_state());
        }
        let wrap = |e: Error| Error::database(e.message().to_owned());
        let r = self.run(&stmt).await.map_err(sql::raw).map_err(wrap)?;
        if !r.ok {
            return Err(Error::database("Failed to delete document"));
        }
        let deleted = r.count;
        let p = self.run(&perms).await.map_err(sql::raw).map_err(wrap)?;
        if !p.ok {
            return Err(Error::database("Failed to delete permissions"));
        }
        Ok(deleted != 0)
    }

    /// `deleteDocuments($collection, $sequences, $permissionIds)`.
    pub(super) async fn delete_documents_impl(
        &self,
        collection: &str,
        sequences: &[Value],
        permission_ids: &[String],
    ) -> Result<i64> {
        if sequences.is_empty() {
            return Ok(0);
        }
        let wrap = |e: Error| Error::database(e.message().to_owned());
        let name = filter(collection);
        let shared = self.shared();
        let placeholders = (0..sequences.len()).map(|i| format!(":_id_{i}")).collect::<Vec<_>>().join(", ");
        let sql = format!(
            "\n            DELETE FROM {} \n            WHERE _id IN ({placeholders})\n            {}\n            ",
            self.sql_table(&name),
            self.tenant_sql(collection)
        );
        let sql = self.trigger_sql(Database::EVENT_DOCUMENTS_DELETE, sql);
        let mut stmt = Statement::new(sql);
        for (i, v) in sequences.iter().enumerate() {
            stmt.bind_str(&format!(":_id_{i}"), v.clone());
        }
        if shared {
            stmt.bind_str(":_tenant", self.tenant_of_state());
        }
        let r = self.run(&stmt).await.map_err(sql::raw).map_err(wrap)?;
        if !r.ok {
            return Err(Error::database("Failed to delete documents"));
        }
        if !permission_ids.is_empty() {
            let placeholders = (0..permission_ids.len()).map(|i| format!(":_id_{i}")).collect::<Vec<_>>().join(", ");
            let sql = format!(
                "\n                DELETE FROM {} \n                WHERE _document IN ({placeholders})\n                {}\n                ",
                self.sql_table(&format!("{name}_perms")),
                self.tenant_sql(collection)
            );
            let sql = self.trigger_sql(Database::EVENT_PERMISSIONS_DELETE, sql);
            let mut perms = Statement::new(sql);
            for (i, v) in permission_ids.iter().enumerate() {
                perms.bind_str(&format!(":_id_{i}"), Value::Str(v.as_str().into()));
            }
            if shared {
                perms.bind_str(":_tenant", self.tenant_of_state());
            }
            let p = self.run(&perms).await.map_err(sql::raw).map_err(wrap)?;
            if !p.ok {
                return Err(Error::database("Failed to delete permissions"));
            }
        }
        Ok(r.count)
    }

    /// `increaseDocumentAttribute(...)` (MariaDB): adds `value`, within
    /// `min`/`max`.
    #[allow(clippy::too_many_arguments)]
    pub(super) async fn increase_impl(
        &self,
        collection: &str,
        id: &str,
        attribute: &str,
        value: &Value,
        updated_at: &str,
        min: Option<&Value>,
        max: Option<&Value>,
    ) -> Result<bool> {
        let name = filter(collection);
        let attribute = filter(attribute);
        let sql_max = if max.is_some() { format!(" AND `{attribute}` <= :max") } else { String::new() };
        let sql_min = if min.is_some() { format!(" AND `{attribute}` >= :min") } else { String::new() };
        let mut sql = format!(
            "\n\t\t\tUPDATE {}\n\t\t\tSET\n\t\t\t    `{attribute}` = `{attribute}` + :val,\n\t\t\t    `_updatedAt` = :updatedAt\n\t\t\tWHERE _uid = :_uid\n\t\t\t{}\n\t\t",
            self.sql_table(&name),
            self.tenant_sql(collection)
        );
        sql.push_str(&sql_max);
        sql.push_str(&sql_min);
        let sql = self.trigger_sql(Database::EVENT_DOCUMENT_UPDATE, sql);
        let mut stmt = Statement::new(sql);
        stmt.bind_str(":_uid", Value::Str(id.into()));
        stmt.bind_str(":val", value.clone());
        stmt.bind_str(":updatedAt", Value::Str(updated_at.into()));
        if let Some(m) = max {
            stmt.bind_str(":max", m.clone());
        }
        if let Some(m) = min {
            stmt.bind_str(":min", m.clone());
        }
        if self.shared() {
            stmt.bind_str(":_tenant", self.tenant_of_state());
        }
        self.run(&stmt).await.map_err(|e| self.processed(e))?;
        Ok(true)
    }
}

/// `getAttributeSelections($queries)`: the values of `select` queries.
pub(super) fn selections(queries: &[Query]) -> Vec<Value> {
    let mut out = Vec::new();
    for q in queries {
        if *q.method() == Method::Select {
            out.extend(q.values().iter().cloned());
        }
    }
    out
}

/// `getAttributeProjection($selections, $prefix)`: `in_array` compares
/// loosely, `array_diff` as strings, and each selection goes through
/// `filter(string $value)` (a `TypeError` for `null`, arrays and objects).
pub(super) fn projection(selections: &[Value], prefix: &str) -> Result<String> {
    let has = |needle: &str| selections.iter().any(|s| s.loose_eq(&Value::Str(needle.into())));
    if selections.is_empty() || has("*") {
        return Ok(format!("{}.*", quote(prefix)));
    }
    const INTERNAL: [&str; 5] = ["$id", "$sequence", "$permissions", "$createdAt", "$updatedAt"];
    let has_deleted_at = has("$deletedAt");
    let mut list: Vec<Value> = Vec::new();
    for s in selections {
        let text = s.to_php_string().map_err(Error::from)?;
        let excluded =
            INTERNAL.iter().chain(["$deletedAt", "$collection"].iter()).any(|i| i.as_bytes() == text.as_bytes());
        if !excluded {
            list.push(s.clone());
        }
    }
    for key in INTERNAL {
        list.push(Value::Str(sql::internal_key(key).into()));
    }
    if has_deleted_at {
        list.push(Value::Str(sql::internal_key("$deletedAt").into()));
    }
    let mut out = Vec::with_capacity(list.len());
    for s in &list {
        let text = match s {
            Value::Str(_) | Value::Int(_) | Value::Float(_) | Value::Bool(_) => interpolate(s),
            other => {
                return Err(Error::new(
                    Kind::TypeError,
                    format!(
                        "Utopia\\Database\\Adapter::filter(): Argument #1 ($value) must be of type string, {} given, called in /usr/src/code/vendor/utopia-php/database/src/Database/Adapter/SQL.php on line 2369",
                        sql::value_name(other)
                    ),
                ));
            }
        };
        out.push(format!("{}.{}", quote(prefix), quote(&filter(&text))));
    }
    Ok(out.join(","))
}

/// `DateTime::setTimezone($datetime)`: the date in the default time zone
/// (UTC), as `Y-m-d H:i:s.v`.
pub(super) fn set_timezone(value: &Value) -> Result<Str> {
    let text = value.to_php_string().unwrap_or(Str::EMPTY);
    let tz = php_std::datetime::TzInfo::utc();
    match php_std::datetime::DateTime::new(text.as_bytes(), None, &tz) {
        Ok(mut d) => {
            d.set_timezone(&php_std::datetime::TimeZone::utc());
            Ok(Str::from(d.format(b"Y-m-d H:i:s.v")))
        }
        Err(e) => Err(Error::new(Kind::Database, e.message().into_owned())),
    }
}
