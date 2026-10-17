//! Document reads by id and document writes.
//!
//! PHP: `getDocument()`, `createDocument(s)()`, `updateDocument(s)()`, `increaseDocumentAttribute()`,
//! `deleteDocument(s)()`, `getSequences()`, `rawQuery()`, `rawMutation()` of `SQL` and its subclasses,
//! with `buildDocumentRow()`, `getSpatialAttributes()`, `encodeSpatialWriteValue()`,
//! `getSpatialGeometryFromText()` and the batch insert helpers of `createDocuments()`.

use crate::adapter::Adapter;
use crate::builder::SqlBuilder;
use crate::capability::Capability;
use crate::collection::Collection;
use crate::database;
use crate::document::{self, Document};
use crate::error::{Error, Kind, Result};
use crate::event::Event;
use crate::query::{DEFAULT_ALIAS, Query};
use crate::storage;
use crate::value::{Array, ArrayKey, Ext, KeyRef, Str, Value};

use super::operator::json_encode;
use super::{Engine, Sql};

pub(crate) use super::find::queries_have_joins;

/// PHP's `empty($value)`.
pub(crate) fn empty_value(value: &Value) -> bool {
    !value.truthy()
}

/// An array key as PHP prints it.
pub(crate) fn key_string(key: &ArrayKey) -> String {
    match key.as_ref() {
        KeyRef::Int(i) => i.to_string(),
        KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
    }
}

fn key(name: &str) -> ArrayKey {
    ArrayKey::normalize(Str::from(name))
}

/// `json_encode($document->getPermissions())`.
pub(crate) fn json_encode_permissions(document: &Document) -> Result<Value> {
    let permissions = document.permissions()?;
    Ok(json_encode(&Value::Array(Array::from_list(
        permissions.into_iter().map(|p| Value::Str(Str::from(p))).collect(),
    ))))
}

/// The query values of a list of database values.
fn query_values(values: &[Value]) -> utopia_query::Array {
    utopia_query::Array::from_list(values.iter().map(crate::value::to_query).collect())
}

/// `setRaw($column, $expression, $bindings)`.
fn set_raw(builder: &mut SqlBuilder, column: &str, expression: &str, bindings: &[Value]) {
    let bindings = query_values(bindings);
    crate::each_builder!(builder, b => {
        b.set_raw(column, expression, bindings);
    });
}

/// `insertColumnExpression($column, $expression)`.
fn insert_column_expression(builder: &mut SqlBuilder, column: &str, expression: &str) {
    crate::each_builder!(builder, b => {
        b.insert_column_expression(column, expression, utopia_query::Array::new());
    });
}

/// `(is_bool($value)) ? (int) $value : $value`.
fn int_bool(value: Value) -> Value {
    match value {
        Value::Bool(b) => Value::Int(i64::from(b)),
        other => other,
    }
}

/// A driver error, or any other, as the `DatabaseException` PHP wraps a
/// `Throwable` in: same message and code, the error as its previous.
fn wrap(e: Error) -> Error {
    let message = e.message().to_owned();
    let wrapped = Error::new(Kind::Database, message);
    let wrapped = match e.state() {
        Some(state) => wrapped.with_string_code(state.as_bytes()),
        None => wrapped.with_code(e.code()),
    };
    wrapped.with_previous(e)
}

/// The tenant and id `createDocuments()` keys a document by.
type DocumentKey = (String, String);

impl Sql {
    /// `getSpatialAttributes()`: the keys of the collection's spatial
    /// attributes.
    pub(crate) fn spatial_attributes(&self, collection: &Collection) -> Result<Vec<String>> {
        Ok(collection.attributes()?.iter().filter(|a| a.is_spatial()).map(|a| a.key.clone()).collect())
    }

    /// `encodeSpatialWriteValue()`: an array geometry as WKT.
    pub(crate) fn encode_spatial_write_value(&self, value: Value) -> Result<Value> {
        match &value {
            Value::Array(geometry) => Ok(Value::Str(Str::from(Sql::convert_array_to_wkt(geometry)?))),
            _ => Ok(value),
        }
    }

    /// `buildDocumentRow()`: the row a document writes in a batch insert,
    /// over `keys`.
    fn build_document_row(
        &self,
        document: &Document,
        keys: &[String],
        spatial: &[String],
        int_bools: bool,
    ) -> Result<Array> {
        let attributes = document.attributes();
        let mut row = Array::new();
        row.set(key(storage::UID), Value::Str(Str::from(document.id())));
        row.set(key(storage::CREATED_AT), document.get(document::CREATED_AT).cloned().unwrap_or(Value::Null));
        row.set(key(storage::UPDATED_AT), document.get(document::UPDATED_AT).cloned().unwrap_or(Value::Null));
        row.set(key(storage::PERMISSIONS), json_encode_permissions(document)?);
        if let Some(sequence) = document.get(document::SEQUENCE)
            && !empty_value(sequence)
        {
            row.set(key(storage::SEQUENCE), sequence.clone());
        }
        for name in keys {
            let k = key(name);
            if row.get(&k).is_some_and(|v| !matches!(v, Value::Null)) {
                continue;
            }
            let mut value = attributes.get(&k).cloned().unwrap_or(Value::Null);
            let is_spatial = spatial.contains(name);
            if is_spatial {
                value = self.encode_spatial_write_value(value)?;
            } else if matches!(value, Value::Array(_)) {
                value = json_encode(&value);
            }
            if int_bools && !is_spatial {
                value = int_bool(value);
            }
            row.set(k, value);
        }
        Ok(row)
    }

    /// The columns of a single-document write: its attributes, then
    /// `_createdAt`, `_updatedAt` and `_permissions`.
    fn write_attributes(document: &Document) -> Result<Array> {
        let mut attributes = document.attributes();
        attributes.set(key(storage::CREATED_AT), document.get(document::CREATED_AT).cloned().unwrap_or(Value::Null));
        attributes.set(key(storage::UPDATED_AT), document.get(document::UPDATED_AT).cloned().unwrap_or(Value::Null));
        attributes.set(key(storage::PERMISSIONS), json_encode_permissions(document)?);
        Ok(attributes)
    }

    /// `createDocument()` of each engine.
    pub(crate) async fn create_document(&self, collection: &Collection, mut document: Document) -> Result<Document> {
        let run = async {
            self.sync_write_hooks();
            let spatial = if self.engine == Engine::Sqlite { Vec::new() } else { self.spatial_attributes(collection)? };
            let attributes = Self::write_attributes(&document)?;
            let name = self.filter(collection.id());

            let mut builder = self.dialect_builder();
            SqlBuilder::into(&mut builder, &self.table_raw(&name));
            let mut row = Array::new();
            row.set(key(storage::UID), Value::Str(Str::from(document.id())));
            if let Some(sequence) = document.get(document::SEQUENCE)
                && !empty_value(sequence)
            {
                row.set(key(storage::SEQUENCE), sequence.clone());
            }
            if self.engine == Engine::Postgres {
                for column in &spatial {
                    insert_column_expression(&mut builder, column, &self.spatial_geometry_from_text("?", None));
                }
            }
            for (attribute, value) in attributes.into_iter().collect::<Vec<_>>() {
                let attribute = key_string(&attribute);
                let column = self.filter(&attribute);
                let value = if spatial.contains(&attribute) {
                    let value = self.encode_spatial_write_value(value)?;
                    insert_column_expression(&mut builder, &column, &self.spatial_geometry_from_text("?", None));
                    if self.engine == Engine::Postgres { value } else { int_bool(value) }
                } else {
                    let value = if matches!(value, Value::Array(_)) { json_encode(&value) } else { value };
                    if self.engine == Engine::Postgres { value } else { int_bool(value) }
                };
                row.set(key(&column), value);
            }
            let row = self.decorate_row(row, &document);
            builder.set(crate::value::to_query_array(&row)).map_err(crate::query::query_error)?;
            let result = builder.insert().map_err(crate::query::query_error)?;
            let mut statement = self.execute_result(&result, Some(Event::DocumentCreate), "").await?;
            self.execute(&mut statement).await?;
            if self.engine == Engine::Postgres {
                // Only a document created without a sequence reads the one
                // the insert drew: the session may never have drawn one.
                if document.get(document::SEQUENCE).is_none_or(empty_value) {
                    let last = self.connection.lock().await.last_insert_id().await?;
                    document.set(document::SEQUENCE, Value::Str(last))?;
                }
            } else {
                let last = self.connection.lock().await.last_insert_id().await?;
                document.set(document::SEQUENCE, Value::Str(last))?;
                if document.get(document::SEQUENCE).is_none_or(empty_value) {
                    return Err(Error::database(format!("Error creating document empty \"{}\"", document::SEQUENCE)));
                }
            }

            let context = self.write_context(&[]);
            let created = std::slice::from_ref(&document);
            let mut outcome = Ok(());
            for hook in self.write_hooks() {
                outcome = hook.after_document_create(&name, created, &context).await;
                if outcome.is_err() {
                    break;
                }
            }
            if let Err(e) = outcome {
                let orphaned = self.engine.is_mariadb_family()
                    && e.kind() == Kind::Pdo
                    && e.state() == Some("23000")
                    && e.driver_code() == Some(1062)
                    && e.message().contains(storage::INDEX_1);
                if !orphaned {
                    return Err(e);
                }
                // Orphaned permissions of an earlier failed delete: removed, then the hooks run again.
                let mut cleanup = self.simple_builder(&storage::permissions_table(&name), "")?;
                cleanup.filter(vec![utopia_query::Query::equal(storage::PERMISSIONS_DOCUMENT, [document.id()])]);
                let result = cleanup.delete().map_err(crate::query::query_error)?;
                let mut statement = self.execute_result(&result, Some(Event::PermissionsDelete), "").await?;
                self.execute(&mut statement).await?;
                for hook in self.write_hooks() {
                    hook.after_document_create(&name, created, &context).await?;
                }
            }
            Ok::<_, Error>(())
        };
        run.await.map_err(|e| self.process_exception(e))?;
        Ok(document)
    }

    /// `createDocuments()`: one insert for the batch; under
    /// `ignoreDuplicates()` only the documents written are returned and
    /// handed to the write hooks.
    pub(crate) async fn create_documents(
        &self,
        collection: &Collection,
        documents: Vec<Document>,
    ) -> Result<Vec<Document>> {
        if documents.is_empty() {
            return Ok(documents);
        }
        self.sync_write_hooks();
        let spatial = self.spatial_attributes(collection)?;
        let name = self.filter(collection.id());
        let run = async {
            let has_sequence = batch_has_sequence(&documents)?;
            let documents = if self.state().ignoring_duplicates().get() {
                let documents = self.first_copies(documents);
                if self.engine == Engine::MySql {
                    self.insert_then_read_back(&name, documents, &spatial, has_sequence).await?
                } else {
                    self.insert_returning(&name, documents, &spatial, has_sequence).await?
                }
            } else {
                let mut builder = self.build_documents_insert(&name, &documents, &spatial, has_sequence)?;
                let insert = builder.insert().map_err(crate::query::query_error)?;
                let mut statement = self.execute_result(&insert, Some(Event::DocumentsCreate), "").await?;
                self.execute(&mut statement).await?;
                documents
            };
            if !documents.is_empty() {
                let context = self.write_context(&[]);
                for hook in self.write_hooks() {
                    hook.after_document_create(&name, &documents, &context).await?;
                }
            }
            Ok::<_, Error>(documents)
        };
        run.await.map_err(|e| self.process_exception(e))
    }

    /// `insertOrIgnore()`: PostgreSQL skips only a stored id (`ON CONFLICT
    /// (keys) DO NOTHING`), the others any unique collision.
    fn insert_or_ignore(&self, builder: &mut SqlBuilder) -> Result<utopia_query::builder::Statement> {
        if self.engine == Engine::Postgres {
            let insert = builder.insert().map_err(crate::query::query_error)?;
            let target: Vec<String> = self.document_key_columns().iter().map(|c| self.quote(c)).collect();
            return Ok(utopia_query::builder::Statement::new(
                format!("{} ON CONFLICT ({}) DO NOTHING", insert.query, target.join(", ")),
                insert.bindings,
            ));
        }
        crate::each_builder!(builder, b => b.insert_or_ignore()).map_err(crate::query::query_error)
    }

    /// `documentKeyColumns()`.
    fn document_key_columns(&self) -> Vec<&'static str> {
        if self.shared_tables() { vec![storage::UID, storage::TENANT] } else { vec![storage::UID] }
    }

    /// `documentTenant()`.
    fn document_tenant(&self, document: &Document) -> Value {
        if !self.shared_tables() {
            return Value::Null;
        }
        document.tenant().unwrap_or_else(|| self.current_tenant())
    }

    /// `documentKey()`.
    fn document_key(&self, document: &Document) -> DocumentKey {
        let tenant = self.document_tenant(document);
        let tenant = if matches!(tenant, Value::Null) { String::new() } else { crate::value::to_text(&tenant) };
        (tenant, document.id().to_owned())
    }

    /// `firstCopies()`: the first document of each id.
    fn first_copies(&self, documents: Vec<Document>) -> Vec<Document> {
        let mut seen: Vec<DocumentKey> = Vec::new();
        let mut out = Vec::new();
        for document in documents {
            let key = self.document_key(&document);
            if seen.contains(&key) {
                continue;
            }
            seen.push(key);
            out.push(document);
        }
        out
    }

    /// `buildDocumentsInsert()`.
    fn build_documents_insert(
        &self,
        name: &str,
        documents: &[Document],
        spatial: &[String],
        has_sequence: bool,
    ) -> Result<SqlBuilder> {
        let mut keys: Vec<String> = database::INTERNAL_ATTRIBUTE_KEYS.iter().map(|k| (*k).to_owned()).collect();
        for document in documents {
            for (k, _) in document.attributes().iter() {
                let k = key_string(&k.to_owned());
                if !keys.contains(&k) {
                    keys.push(k);
                }
            }
        }
        if has_sequence {
            keys.push(storage::SEQUENCE.to_owned());
        }
        let mut builder = self.dialect_builder();
        SqlBuilder::into(&mut builder, &self.table_raw(name));
        for column in spatial {
            insert_column_expression(&mut builder, column, &self.spatial_geometry_from_text("?", None));
        }
        let int_bools = self.supports(Capability::IntegerBooleans);
        for document in documents {
            let row = self.build_document_row(document, &keys, spatial, int_bools)?;
            let row = self.decorate_row(row, document);
            builder.set(crate::value::to_query_array(&row)).map_err(crate::query::query_error)?;
        }
        Ok(builder)
    }

    /// `insertReturning()`: the documents the insert wrote, read from its
    /// `RETURNING` keys.
    async fn insert_returning(
        &self,
        name: &str,
        documents: Vec<Document>,
        spatial: &[String],
        has_sequence: bool,
    ) -> Result<Vec<Document>> {
        let mut builder = self.build_documents_insert(name, &documents, spatial, has_sequence)?;
        let columns = self.document_key_columns();
        let insert = if let SqlBuilder::MariaDb(b) = &mut builder {
            b.returning(columns.iter().copied());
            self.insert_or_ignore(&mut builder)?
        } else {
            let insert = self.insert_or_ignore(&mut builder)?;
            let quoted: Vec<String> = columns.iter().map(|c| self.quote(c)).collect();
            utopia_query::builder::Statement::new(
                format!("{} RETURNING {}", insert.query, quoted.join(", ")),
                insert.bindings,
            )
        };
        let mut statement = self.execute_result(&insert, Some(Event::DocumentsCreate), "").await?;
        self.execute(&mut statement).await?;
        let rows = statement.statement.fetch_all_num();
        statement.statement.close_cursor();
        let written = self.row_keys(&rows);
        Ok(documents.into_iter().filter(|d| written.contains(&self.document_key(d))).collect())
    }

    /// `insertThenReadBack()`: without `RETURNING`, the stored ids are read
    /// before the insert, and the written rows after it when it wrote fewer
    /// than it was sent (a row counts as written only with the document's
    /// own permissions).
    async fn insert_then_read_back(
        &self,
        name: &str,
        documents: Vec<Document>,
        spatial: &[String],
        has_sequence: bool,
    ) -> Result<Vec<Document>> {
        let stored = self.row_keys(&self.read_rows(name, &documents, &self.document_key_columns()).await?);
        let candidates: Vec<Document> =
            documents.into_iter().filter(|d| !stored.contains(&self.document_key(d))).collect();
        if candidates.is_empty() {
            return Ok(Vec::new());
        }
        let mut builder = self.build_documents_insert(name, &candidates, spatial, has_sequence)?;
        let insert = self.insert_or_ignore(&mut builder)?;
        let mut statement = self.execute_result(&insert, Some(Event::DocumentsCreate), "").await?;
        self.execute(&mut statement).await?;
        let written = statement.statement.row_count();
        statement.statement.close_cursor();
        if written == candidates.len() as i64 {
            return Ok(candidates);
        }
        let mut columns = self.document_key_columns();
        columns.push(storage::PERMISSIONS);
        let mut permissions: Vec<(DocumentKey, Value)> = Vec::new();
        for row in self.read_rows(name, &candidates, &columns).await? {
            let last = row.values().last().cloned().unwrap_or(Value::Null);
            let decoded = match &last {
                Value::Str(s) => php_std::json::decode_ext(s.as_bytes(), Some(true), 512, php_std::json::Flags::NONE)
                    .map(crate::value::from_plain)
                    .unwrap_or(Value::Null),
                _ => Value::Null,
            };
            let key = (self.row_tenant(&row), self.row_id(&row));
            permissions.retain(|(k, _)| *k != key);
            permissions.push((key, decoded));
        }
        let mut out = Vec::new();
        for document in candidates {
            let key = self.document_key(&document);
            let own = Value::Array(Array::from_list(
                document.permissions()?.into_iter().map(|p| Value::Str(Str::from(p))).collect(),
            ));
            if permissions.iter().any(|(k, v)| *k == key && v.strict_eq(&own)) {
                out.push(document);
            }
        }
        Ok(out)
    }

    /// `readRows()`: `columns` of the stored rows of the documents' ids.
    async fn read_rows(&self, name: &str, documents: &[Document], columns: &[&str]) -> Result<Vec<Array>> {
        let mut ids: Vec<String> = Vec::new();
        let mut tenants: Vec<Value> = Vec::new();
        for document in documents {
            ids.push(document.id().to_owned());
            let tenant = self.document_tenant(document);
            if self.shared_tables() && self.tenant_per_document() && !tenants.iter().any(|t| t.strict_eq(&tenant)) {
                tenants.push(tenant);
            }
        }
        let mut unique: Vec<String> = Vec::new();
        for id in ids {
            if !unique.contains(&id) {
                unique.push(id);
            }
        }
        let mut builder = self.new_builder(name, "", false, tenants, Vec::new())?;
        builder.select(columns.iter().map(|c| (*c).to_owned()).collect());
        builder.filter(vec![utopia_query::Query::equal(storage::UID, unique_values(&unique))]);
        let result = builder.build().map_err(crate::query::query_error)?;
        let mut statement = self.execute_result(&result, Some(Event::DocumentRead), "").await?;
        self.execute(&mut statement).await?;
        let rows = statement.statement.fetch_exactly(|s| s.fetch_all_num());
        statement.statement.close_cursor();
        Ok(rows)
    }

    /// `rowKeys()`.
    fn row_keys(&self, rows: &[Array]) -> Vec<DocumentKey> {
        rows.iter().map(|row| (self.row_tenant(row), self.row_id(row))).collect()
    }

    /// `rowId()`.
    fn row_id(&self, row: &Array) -> String {
        scalar_text(row.get(&ArrayKey::Int(0)))
    }

    /// `rowTenant()`.
    fn row_tenant(&self, row: &Array) -> String {
        if !self.shared_tables() {
            return String::new();
        }
        scalar_text(row.get(&ArrayKey::Int(1)))
    }

    /// `getDocument()`: the fast path (no projection, no join) with named
    /// bindings; a projection through the builder; the joined read through
    /// the find machinery.
    pub(crate) async fn get_document(
        &self,
        collection: &Collection,
        id: &str,
        queries: Vec<Query>,
        for_update: bool,
    ) -> Result<Document> {
        let name = self.filter(collection.id());
        let (selections, star) =
            super::find::projection::php_selections(&crate::adapter::attribute_selections(&queries), None)?;
        let alias = DEFAULT_ALIAS;
        let joins = queries_have_joins(&queries);

        if selections.is_empty() && !joins {
            let table = self.table(&name);
            let alias = self.quote(alias);
            let uid = self.quote(storage::UID);
            let mut sql = format!(
                "SELECT * FROM {table} AS {alias} WHERE {} = :{}",
                self.collate_document_id(&uid),
                storage::UID
            );
            let mut bindings = Array::new();
            bindings.set(key(&format!(":{}", storage::UID)), Value::Str(Str::from(id)));
            if self.shared_tables() {
                let tenant = format!("{alias}.{}", storage::TENANT);
                if name == database::METADATA || name == storage::permissions_table(database::METADATA) {
                    sql.push_str(&format!(" AND ({tenant} IN (:{}) OR {tenant} IS NULL)", storage::TENANT));
                } else {
                    sql.push_str(&format!(" AND {tenant} IN (:{})", storage::TENANT));
                }
                bindings.set(key(&format!(":{}", storage::TENANT)), self.current_tenant());
            }
            if for_update && self.supports(Capability::UpdateLock) {
                sql.push_str(" FOR UPDATE");
            }
            let read = async {
                let mut statement = self.prepare_statement(&sql, Some(Event::DocumentRead)).await?;
                let outcome = async {
                    for (parameter, value) in bindings.iter() {
                        let (value, ty) = self.bindable(value.clone())?;
                        statement.bind_value(key_string(&parameter.to_owned()), value, ty)?;
                    }
                    self.describe_statement(&mut statement, bindings.clone(), &name);
                    self.execute(&mut statement).await?;
                    Ok::<_, Error>(statement.statement.fetch_exactly(|s| s.fetch_assoc()))
                }
                .await;
                statement.statement.close_cursor();
                outcome
            };
            let row = read.await.map_err(|e| self.process_exception(e))?;
            let Some(mut row) = row.filter(|r| !r.is_empty()) else {
                return Ok(Document::empty());
            };
            super::row::remap_row(&mut row);
            return Document::from_row(row);
        }

        let rows = if joins {
            if for_update {
                return Err(Error::query("Cannot lock a document for update when join queries are present"));
            }
            self.read_joined_document(collection, id, queries).await?
        } else {
            let mut builder = self.simple_builder(&name, alias)?;
            if !star {
                builder.select(self.map_selections_to_columns(&selections, true, &[]));
            }
            builder.filter(vec![utopia_query::Query::equal(storage::UID, [id])]);
            if for_update && self.supports(Capability::UpdateLock) {
                crate::each_builder!(&mut builder, b => {
                    b.for_update();
                });
            }
            self.execute_select(&mut builder, Event::DocumentRead, &name).await?
        };
        let Some(mut row) = rows.into_iter().next() else {
            return Ok(Document::empty());
        };
        super::row::remap_row(&mut row);
        Document::from_row(row)
    }

    /// `updateDocument()` of each engine.
    pub(crate) async fn update_document(
        &self,
        collection: &Collection,
        id: &str,
        document: Document,
        skip_permissions: bool,
    ) -> Result<Document> {
        let run = async {
            self.sync_write_hooks();
            let spatial = self.spatial_attributes(collection)?;
            let attributes = Self::write_attributes(&document)?;
            let name = self.filter(collection.id());

            let mut builder = self.simple_builder(&name, "")?;
            let mut row = Array::new();
            if document.id() != id {
                row.set(key(storage::UID), Value::Str(Str::from(document.id())));
            }
            for (attribute, value) in attributes.into_iter().collect::<Vec<_>>() {
                let attribute = key_string(&attribute);
                let column = self.filter(&attribute);
                if let Value::Ext(Ext::Operator(operator)) = &value {
                    let expression = self.operator_builder_expression(&column, operator).await?;
                    set_raw(&mut builder, &column, &expression.sql, &expression.bindings);
                } else if self.engine != Engine::Sqlite && spatial.contains(&attribute) {
                    let value = self.encode_spatial_write_value(value)?;
                    let value = if self.engine == Engine::Postgres { value } else { int_bool(value) };
                    set_raw(&mut builder, &column, &self.spatial_geometry_from_text("?", None), &[value]);
                } else {
                    let value = if matches!(value, Value::Array(_)) { json_encode(&value) } else { value };
                    let value = if self.engine == Engine::Postgres { value } else { int_bool(value) };
                    row.set(key(&column), value);
                }
            }
            builder.set(crate::value::to_query_array(&row)).map_err(crate::query::query_error)?;
            let filter = if self.engine == Engine::Sqlite {
                utopia_query::Query::equal(storage::UID, [id])
            } else {
                let sequence = document.get(document::SEQUENCE).cloned().unwrap_or(Value::Null);
                utopia_query::Query::equal(storage::SEQUENCE, query_values(&[sequence]))
            };
            builder.filter(vec![filter]);
            let result = builder.update().map_err(crate::query::query_error)?;
            let mut statement = self.execute_result(&result, Some(Event::DocumentUpdate), "").await?;
            self.execute(&mut statement).await?;

            let skipped = if skip_permissions { vec![document.id().to_owned()] } else { Vec::new() };
            let context = self.write_context(&skipped);
            for hook in self.write_hooks() {
                hook.after_document_update(&name, id, &document, &context).await?;
            }
            Ok::<_, Error>(())
        };
        run.await.map_err(|e| self.process_exception(e))?;
        Ok(document)
    }

    /// `updateDocuments()`: one update of the documents' sequences.
    pub(crate) async fn update_documents(
        &self,
        collection: &Collection,
        updates: &Document,
        documents: Vec<Document>,
        skip_permissions: &[String],
    ) -> Result<i64> {
        if documents.is_empty() {
            return Ok(0);
        }
        self.sync_write_hooks();
        let spatial = self.spatial_attributes(collection)?;
        let mut attributes = updates.attributes();
        if let Some(updated) = updates.get(document::UPDATED_AT)
            && !empty_value(updated)
        {
            attributes.set(key(storage::UPDATED_AT), updated.clone());
        }
        if let Some(created) = updates.get(document::CREATED_AT)
            && !empty_value(created)
        {
            attributes.set(key(storage::CREATED_AT), created.clone());
        }
        if updates.as_array().get_str(document::PERMISSIONS).is_some() {
            attributes.set(key(storage::PERMISSIONS), json_encode_permissions(updates)?);
        }
        if attributes.is_empty() {
            return Ok(0);
        }
        let name = self.filter(collection.id());
        let mut builder = self.simple_builder(&name, "")?;
        let int_bools = self.supports(Capability::IntegerBooleans);

        let mut regular = Array::new();
        let mut spatial_rows: Vec<(String, Value)> = Vec::new();
        let mut operators = Vec::new();
        for (attribute, value) in attributes.into_iter().collect::<Vec<_>>() {
            let attribute = key_string(&attribute);
            if let Value::Ext(Ext::Operator(operator)) = value {
                operators.push((attribute, operator));
                continue;
            }
            if spatial.contains(&attribute) {
                let column = self.filter(&attribute);
                let value = self.encode_spatial_write_value(value)?;
                spatial_rows.retain(|(c, _)| *c != column);
                spatial_rows.push((column, value));
                continue;
            }
            let column = self.filter(&attribute);
            let mut value = if matches!(value, Value::Array(_)) { json_encode(&value) } else { value };
            if int_bools {
                value = int_bool(value);
            }
            regular.set(key(&column), value);
        }
        if !regular.is_empty() {
            builder.set(crate::value::to_query_array(&regular)).map_err(crate::query::query_error)?;
        }
        for (column, value) in &spatial_rows {
            set_raw(&mut builder, column, &self.spatial_geometry_from_text("?", None), std::slice::from_ref(value));
        }
        for (attribute, operator) in &operators {
            let column = self.filter(attribute);
            let expression = self.operator_builder_expression(&column, operator).await?;
            set_raw(&mut builder, &column, &expression.sql, &expression.bindings);
        }
        let sequences: Vec<Value> =
            documents.iter().map(|d| d.get(document::SEQUENCE).cloned().unwrap_or(Value::Null)).collect();
        builder.filter(vec![utopia_query::Query::equal(storage::SEQUENCE, query_values(&sequences))]);
        let result = builder.update().map_err(crate::query::query_error)?;
        let mut statement = self.execute_result(&result, Some(Event::DocumentsUpdate), "").await?;
        self.execute(&mut statement).await.map_err(|e| self.process_exception(e))?;
        let affected = statement.statement.row_count();

        let context = self.write_context(skip_permissions);
        for hook in self.write_hooks() {
            hook.after_document_batch_update(&name, updates, &documents, &context).await?;
        }
        Ok(affected)
    }

    /// `increaseDocumentAttribute()`: `col = COALESCE(col, 0) + ?` within
    /// the bounds given.
    #[allow(clippy::too_many_arguments)]
    pub(crate) async fn increase_document_attribute(
        &self,
        collection: &Collection,
        id: &str,
        attribute: &str,
        value: Value,
        updated_at: &str,
        min: Option<Value>,
        max: Option<Value>,
    ) -> Result<bool> {
        let name = self.filter(collection.id());
        let attribute = self.filter(attribute);
        let mut builder = self.simple_builder(&name, "")?;
        set_raw(
            &mut builder,
            &attribute,
            &format!("COALESCE({}, 0) + ?", self.quote(&attribute)),
            std::slice::from_ref(&value),
        );
        let mut row = utopia_query::Array::new();
        row.set(ArrayKey::normalize(Str::from(storage::UPDATED_AT)), utopia_query::Value::Str(Str::from(updated_at)));
        builder.set(row).map_err(crate::query::query_error)?;

        let mut filters = vec![utopia_query::Query::equal(storage::UID, [id])];
        if let Some(max) = max {
            let within = utopia_query::Query::less_than_equal(attribute.as_str(), crate::value::to_query(&max));
            filters.push(if max.to_double() >= 0.0 {
                utopia_query::Query::or([within, utopia_query::Query::is_null(attribute.as_str())])
            } else {
                within
            });
        }
        if let Some(min) = min {
            let within = utopia_query::Query::greater_than_equal(attribute.as_str(), crate::value::to_query(&min));
            filters.push(if min.to_double() <= 0.0 {
                utopia_query::Query::or([within, utopia_query::Query::is_null(attribute.as_str())])
            } else {
                within
            });
        }
        builder.filter(filters);
        let result = builder.update().map_err(crate::query::query_error)?;
        let negative = value.cmp_php(&Value::Int(0)) == std::cmp::Ordering::Less;
        let event = if negative { Event::DocumentDecrease } else { Event::DocumentIncrease };
        let mut statement = self.execute_result(&result, Some(event), "").await?;
        self.execute(&mut statement).await.map_err(|e| self.process_exception(e))?;
        // False when the bounds left the row unchanged (8.3.6).
        Ok(statement.statement.row_count() > 0)
    }

    /// `deleteDocument()`.
    pub(crate) async fn delete_document(&self, collection: &Collection, id: &str) -> Result<bool> {
        let run = async {
            self.sync_write_hooks();
            let name = self.filter(collection.id());
            let mut builder = self.simple_builder(&name, "")?;
            builder.filter(vec![utopia_query::Query::equal(storage::UID, [id])]);
            let result = builder.delete().map_err(crate::query::query_error)?;
            let mut statement = self.execute_result(&result, Some(Event::DocumentDelete), "").await?;
            if !self.execute(&mut statement).await? {
                return Err(Error::database("Failed to delete document"));
            }
            let deleted = statement.statement.row_count();
            let context = self.write_context(&[]);
            for hook in self.write_hooks() {
                hook.after_document_delete(&name, &[id.to_owned()], &context).await?;
            }
            Ok::<_, Error>(deleted)
        };
        let deleted = run.await.map_err(wrap)?;
        Ok(deleted > 0)
    }

    /// `deleteDocuments()`: one delete of the sequences, of the rows that
    /// match every one of `queries` (`deletionFilters()`), and of the
    /// permissions of `permission_ids` but those of a row still stored.
    pub(crate) async fn delete_documents(
        &self,
        collection: &Collection,
        sequences: Vec<Value>,
        permission_ids: Vec<String>,
        queries: Vec<Query>,
    ) -> Result<i64> {
        if sequences.is_empty() {
            return Ok(0);
        }
        self.sync_write_hooks();
        let run = async {
            let name = self.filter(collection.id());
            let filters: Vec<Query> =
                self.main_row_queries(queries, collection)?.into_iter().filter(|q| !q.method().is_vector()).collect();

            let mut builder = self.simple_builder(&name, "")?;
            builder.filter(vec![utopia_query::Query::equal(storage::SEQUENCE, query_values(&sequences))]);
            if !filters.is_empty() {
                self.apply_filters(&mut builder, &filters, &name, &self.unqualified_table(&name)).await?;
            }
            let result = builder.delete().map_err(crate::query::query_error)?;
            let mut statement = self.execute_result(&result, Some(Event::DocumentsDelete), "").await?;
            if !self.execute(&mut statement).await? {
                return Err(Error::database("Failed to delete documents"));
            }

            let deleted = statement.statement.row_count();
            let mut permission_ids = permission_ids;
            if !filters.is_empty() && deleted < sequences.len() as i64 {
                permission_ids = self.without_stored_documents(&name, permission_ids).await?;
            }

            let context = self.write_context(&[]);
            for hook in self.write_hooks() {
                hook.after_document_delete(&name, &permission_ids, &context).await?;
            }
            Ok::<_, Error>(deleted)
        };
        run.await.map_err(wrap)
    }

    /// The name a statement on the collection's table refers to its columns
    /// by when it gives the table no alias (`unqualifiedTable()`).
    fn unqualified_table(&self, name: &str) -> String {
        let table = self.table_raw(name);
        match table.rfind('.') {
            Some(dot) => table[dot + 1..].to_owned(),
            None => table,
        }
    }

    /// The ids of documents no longer stored (`withoutStoredDocuments()`).
    async fn without_stored_documents(&self, name: &str, document_ids: Vec<String>) -> Result<Vec<String>> {
        if document_ids.is_empty() {
            return Ok(Vec::new());
        }
        let mut builder = self.simple_builder(name, "")?;
        builder.select(vec![storage::UID.to_owned()]);
        let ids: Vec<Value> = document_ids.iter().map(|id| Value::Str(Str::from(id.as_str()))).collect();
        builder.filter(vec![utopia_query::Query::equal(storage::UID, query_values(&ids))]);

        let mut stored: Vec<String> = Vec::new();
        for row in self.execute_select(&mut builder, Event::DocumentFind, name).await? {
            if let Some(Value::Str(id)) = row.get_str(storage::UID) {
                stored.push(String::from_utf8_lossy(id.as_bytes()).into_owned());
            }
        }
        Ok(document_ids.into_iter().filter(|id| !stored.contains(id)).collect())
    }

    /// `getSequences()`: the stored sequences of the documents without one.
    pub(crate) async fn get_sequences(
        &self,
        collection: &Collection,
        mut documents: Vec<Document>,
    ) -> Result<Vec<Document>> {
        let keyed = self.shared_tables() && self.tenant_per_document();
        let mut ids: Vec<Value> = Vec::new();
        let mut tenants: Vec<Value> = Vec::new();
        for document in &documents {
            if document.get(document::SEQUENCE).is_none_or(empty_value) {
                ids.push(Value::Str(Str::from(document.id())));
                if keyed {
                    let tenant = document.tenant().unwrap_or(Value::Null);
                    if !tenants.iter().any(|t| t.strict_eq(&tenant)) {
                        tenants.push(tenant);
                    }
                }
            }
        }
        if ids.is_empty() {
            return Ok(documents);
        }
        let mut builder = self.new_builder(collection.id(), "", false, tenants, Vec::new())?;
        builder.select(if keyed {
            vec![storage::UID.to_owned(), storage::SEQUENCE.to_owned(), storage::TENANT.to_owned()]
        } else {
            vec![storage::UID.to_owned(), storage::SEQUENCE.to_owned()]
        });
        builder.filter(vec![utopia_query::Query::equal(storage::UID, query_values(&ids))]);
        let result = builder.build().map_err(crate::query::query_error)?;
        let mut statement = self.execute_result(&result, Some(Event::DocumentRead), "").await?;
        self.execute(&mut statement).await?;

        let sequence_key = |tenant: Option<&Value>, id: Option<&Value>| -> String {
            format!("{}\0{}", scalar_text(tenant), scalar_text(id))
        };
        let mut sequences: Vec<(String, Value)> = Vec::new();
        if keyed {
            for row in statement.statement.fetch_all_assoc() {
                let k = sequence_key(row.get_str(storage::TENANT), row.get_str(storage::UID));
                let v = row.get_str(storage::SEQUENCE).cloned().unwrap_or(Value::Null);
                sequences.retain(|(s, _)| *s != k);
                sequences.push((k, v));
            }
        } else {
            for (k, v) in statement.statement.fetch_all_key_pair()? {
                sequences.push((key_string(&k), v));
            }
        }
        statement.statement.close_cursor();

        for document in &mut documents {
            let k = if keyed {
                sequence_key(document.tenant().as_ref(), Some(&Value::Str(Str::from(document.id()))))
            } else {
                document.id().to_owned()
            };
            if let Some((_, v)) = sequences.iter().find(|(s, _)| *s == k)
                && !matches!(v, Value::Null)
            {
                document.set(document::SEQUENCE, v.clone())?;
            }
        }
        Ok(documents)
    }

    /// `rawQuery()`: a statement of the caller's, with positional bindings;
    /// its rows as documents.
    pub(crate) async fn raw_query(&self, query: &str, bindings: utopia_query::Array) -> Result<Vec<Document>> {
        let mut statement = self.prepare_statement(query, None).await.map_err(|e| self.process_exception(e))?;
        let run = async {
            for (i, value) in bindings.values().enumerate() {
                let value = crate::value::from_query(value);
                let (value, ty) = self.bindable(value)?;
                statement.bind_value(i + 1, value, ty)?;
            }
            self.execute(&mut statement).await
        };
        run.await.map_err(|e| self.process_exception(e))?;
        let rows = statement.statement.fetch_exactly(|s| s.fetch_all())?;
        statement.statement.close_cursor();
        let mut documents = Vec::with_capacity(rows.len());
        for row in rows.values().cloned() {
            let row = match row {
                Value::Array(row) => row,
                _ => Array::new(),
            };
            documents.push(Document::from_row(row)?);
        }
        Ok(documents)
    }

    /// `rawMutation()`: a statement of the caller's; the rows it changed.
    pub(crate) async fn raw_mutation(&self, query: &str, bindings: utopia_query::Array) -> Result<i64> {
        let mut statement = self.prepare_statement(query, None).await.map_err(|e| self.process_exception(e))?;
        let run = async {
            for (i, value) in bindings.values().enumerate() {
                let value = crate::value::from_query(value);
                let (value, ty) = self.bindable(value)?;
                statement.bind_value(i + 1, value, ty)?;
            }
            self.execute(&mut statement).await
        };
        run.await.map_err(|e| self.process_exception(e))?;
        let count = statement.statement.row_count();
        statement.statement.close_cursor();
        Ok(count)
    }
}

/// `batchHasSequence()`: every document has a sequence, or none does.
fn batch_has_sequence(documents: &[Document]) -> Result<bool> {
    let mut has: Option<bool> = None;
    for document in documents {
        let present = document.get(document::SEQUENCE).is_some_and(|s| !empty_value(s));
        match has {
            None => has = Some(present),
            Some(h) if h != present => {
                return Err(Error::database("All documents must have an sequence if one is set"));
            }
            Some(_) => {}
        }
    }
    Ok(has.unwrap_or(false))
}

/// `is_scalar($v) ? (string) $v : ''`.
fn scalar_text(value: Option<&Value>) -> String {
    match value {
        Some(v @ (Value::Bool(_) | Value::Int(_) | Value::Float(_) | Value::Str(_))) => crate::value::to_text(v),
        _ => String::new(),
    }
}

/// Ids as query values.
fn unique_values(ids: &[String]) -> utopia_query::Array {
    utopia_query::Array::from_list(ids.iter().map(|id| utopia_query::Value::Str(Str::from(id.as_str()))).collect())
}
