//! Upserts.
//!
//! PHP: `upsertDocument()`, `upsertDocuments()`, `executeUpsertBatch()` of `SQL` and its subclasses,
//! `getConflictTenantExpression()`, `getConflictIncrementExpression()`,
//! `getConflictTenantIncrementExpression()`, `insertRequiresAlias()`.

use crate::adapter::Adapter;
use crate::builder::SqlBuilder;
use crate::capability::Capability;
use crate::change::Change;
use crate::collection::Collection;
use crate::document::{self, Document};
use crate::error::{Error, Result};
use crate::event::Event;
use crate::operator::Operator;
use crate::storage;
use crate::validator::big_int::BigInt;
use crate::value::{Array, ArrayKey, Str, Value};

use super::documents::{empty_value, json_encode_permissions, key_string};
use super::operator::json_encode;
use super::{Engine, Sql};

/// The operators of an update, by attribute, in order.
type Operators = Vec<(String, Operator)>;

/// `Operator::extractOperators($attributes)` as (updates, operators by attribute).
fn extract(attributes: Array) -> (Array, Operators) {
    let extracted = Operator::extract_operators(attributes);
    let operators = extracted.operators.into_iter().map(|(key, op)| (key_string(&key), op)).collect();
    (extracted.updates, operators)
}

/// `$row[$key] = $value` with a string key.
fn put(row: &mut Array, key: &str, value: Value) {
    row.set(ArrayKey::normalize(Str::from(key)), value);
}

/// The query values of a list of database values.
fn query_values(values: &[Value]) -> utopia_query::Array {
    utopia_query::Array::from_list(values.iter().map(crate::value::to_query).collect())
}

impl Sql {
    /// `insertRequiresAlias()`: PostgreSQL inserts `AS target` so its
    /// conflict update can read the stored row.
    fn insert_requires_alias(&self) -> bool {
        self.engine == Engine::Postgres
    }

    /// `getConflictTenantExpression()`: the column's new value only when
    /// the stored row is the same tenant's.
    pub(crate) fn conflict_tenant_expression(&self, column: &str) -> String {
        let quoted = self.quote(&self.filter(column));
        let tenant = storage::TENANT;
        match self.engine {
            Engine::Postgres => {
                format!("CASE WHEN target.{tenant} = EXCLUDED.{tenant} THEN EXCLUDED.{quoted} ELSE target.{quoted} END")
            }
            Engine::Sqlite => {
                format!("CASE WHEN {tenant} = excluded.{tenant} THEN excluded.{quoted} ELSE {quoted} END")
            }
            Engine::MariaDb | Engine::MySql => {
                format!("IF({tenant} = VALUES({tenant}), VALUES({quoted}), {quoted})")
            }
        }
    }

    /// `getConflictIncrementExpression()`: the stored value plus the
    /// incoming one.
    pub(crate) fn conflict_increment_expression(&self, column: &str) -> String {
        let quoted = self.quote(&self.filter(column));
        match self.engine {
            Engine::Postgres => format!("target.{quoted} + EXCLUDED.{quoted}"),
            Engine::Sqlite => format!("{quoted} + excluded.{quoted}"),
            Engine::MariaDb | Engine::MySql => format!("{quoted} + VALUES({quoted})"),
        }
    }

    /// `getConflictTenantIncrementExpression()`.
    pub(crate) fn conflict_tenant_increment_expression(&self, column: &str) -> String {
        let quoted = self.quote(&self.filter(column));
        let tenant = storage::TENANT;
        match self.engine {
            Engine::Postgres => format!(
                "CASE WHEN target.{tenant} = EXCLUDED.{tenant} THEN target.{quoted} + EXCLUDED.{quoted} ELSE target.{quoted} END"
            ),
            Engine::Sqlite => {
                format!("CASE WHEN {tenant} = excluded.{tenant} THEN {quoted} + excluded.{quoted} ELSE {quoted} END")
            }
            Engine::MariaDb | Engine::MySql => {
                format!("IF({tenant} = VALUES({tenant}), {quoted} + VALUES({quoted}), {quoted})")
            }
        }
    }

    /// `upsertDocument()`.
    pub(crate) async fn upsert_document(&self, collection: &Collection, change: Change) -> Result<Document> {
        let mut documents = self.upsert_documents(collection, vec![change], None).await?;
        Ok(documents.remove(0))
    }

    /// `upsertDocuments()`: one statement for the batch, or one per group of
    /// documents carrying the same operators.
    pub(crate) async fn upsert_documents(
        &self,
        collection: &Collection,
        changes: Vec<Change>,
        increase: Option<&str>,
    ) -> Result<Vec<Document>> {
        if changes.is_empty() {
            return Ok(Vec::new());
        }
        self.sync_write_hooks();
        let run = async {
            let spatial = self.spatial_attributes(collection)?;
            let mut defaults: Vec<(String, Value)> = Vec::new();
            for declared in collection.attributes()? {
                defaults.retain(|(k, _)| k != &declared.key);
                defaults.push((declared.key.clone(), declared.default.clone()));
            }
            let name = self.filter(collection.id());

            let has_operators = changes.iter().any(|change| !extract(change.new.attributes()).1.is_empty());

            if !has_operators {
                self.execute_upsert_batch(
                    &name,
                    &changes,
                    &spatial,
                    increase.unwrap_or(""),
                    &Vec::new(),
                    &defaults,
                    false,
                )
                .await?;
            } else {
                let mut groups: Vec<(String, Vec<Change>, Operators)> = Vec::new();
                for change in &changes {
                    let (_, operators) = extract(change.new.attributes());
                    let signature = if operators.is_empty() {
                        "no_ops".to_owned()
                    } else {
                        let mut parts: Array = Array::new();
                        for (attribute, operation) in &operators {
                            let values = json_encode(&Value::Array(operation.values().into_owned()));
                            let values = crate::value::to_text(&values);
                            let _ = parts.push(Value::Str(Str::from(format!(
                                "{attribute}:{}:{values}",
                                operation.method().as_str()
                            ))));
                        }
                        php_std::sort::sort(&mut parts, php_std::sort::SortFlags::Regular)
                            .map_err(|e| Error::new(crate::Kind::Error, e.to_string()))?;
                        parts.values().map(crate::value::to_text).collect::<Vec<_>>().join("|")
                    };
                    match groups.iter_mut().find(|(s, _, _)| *s == signature) {
                        Some((_, documents, _)) => documents.push(change.clone()),
                        None => groups.push((signature, vec![change.clone()], operators)),
                    }
                }
                for (_, documents, operators) in &groups {
                    self.execute_upsert_batch(&name, documents, &spatial, "", operators, &defaults, true).await?;
                }
            }

            let context = self.write_context(&[]);
            for hook in self.write_hooks() {
                hook.after_document_upsert(&name, &changes, &context).await?;
            }
            Ok::<_, Error>(())
        };
        run.await.map_err(|e| self.process_exception(e))?;
        Ok(changes.into_iter().map(|change| change.new).collect())
    }

    /// The rows of an upsert batch: each document's columns, decorated by
    /// the write hooks, and the sorted union of their columns and the
    /// operators' (the shared part of `executeUpsertBatch()`).
    #[allow(clippy::type_complexity)]
    fn upsert_rows(
        &self,
        changes: &[Change],
        operators: &Operators,
        defaults: &[(String, Value)],
        has_operators: bool,
    ) -> Result<(Vec<Array>, Vec<Value>)> {
        let mut all_columns = Array::new();
        let mut rows = Vec::with_capacity(changes.len());
        for change in changes {
            let document = &change.new;
            let mut row;
            let created = document.get(document::CREATED_AT).cloned().unwrap_or(Value::Null);
            let updated = document.get(document::UPDATED_AT).cloned().unwrap_or(Value::Null);
            if has_operators {
                let (updates, extracted) = extract(document.attributes());
                row = updates;
                if change.old.is_empty() && !extracted.is_empty() {
                    for (key, operator) in &extracted {
                        let default =
                            defaults.iter().find(|(k, _)| k == key).map(|(_, v)| v.clone()).unwrap_or(Value::Null);
                        let value = self.apply_operator_to_value(operator, default)?;
                        if self.engine == Engine::Sqlite
                            && operator.method().is_numeric()
                            && matches!(&value, Value::Str(s) if !BigInt::fits_php_int(s.as_bytes(), true))
                        {
                            return Err(Error::limit("Value out of range"));
                        }
                        put(&mut row, key, value);
                    }
                }
                put(&mut row, storage::UID, Value::Str(Str::from(document.id())));
                put(&mut row, storage::CREATED_AT, if created.truthy() { created } else { Value::Null });
                put(&mut row, storage::UPDATED_AT, if updated.truthy() { updated } else { Value::Null });
            } else {
                row = document.attributes();
                put(&mut row, storage::UID, Value::Str(Str::from(document.id())));
                let zoned = |value: Value| -> Result<Value> {
                    if !value.truthy() {
                        return Ok(Value::Null);
                    }
                    let text = value.to_php_string().unwrap_or(Str::EMPTY);
                    Ok(Value::Str(Str::from(crate::datetime::set_timezone(text.as_bytes())?)))
                };
                put(&mut row, storage::CREATED_AT, zoned(created)?);
                put(&mut row, storage::UPDATED_AT, zoned(updated)?);
            }
            put(&mut row, storage::PERMISSIONS, json_encode_permissions(document)?);
            if let Some(sequence) = document.get(document::SEQUENCE)
                && !empty_value(sequence)
            {
                put(&mut row, storage::SEQUENCE, sequence.clone());
            }
            let row = self.decorate_row(row, document);
            for (key, _) in row.iter() {
                all_columns.set(key.to_owned(), Value::Bool(true));
            }
            rows.push(row);
        }
        for (column, _) in operators {
            all_columns.set(ArrayKey::normalize(Str::from(column.as_str())), Value::Bool(true));
        }
        let mut columns: Array = Array::from_list(
            all_columns
                .keys()
                .map(|k| match k {
                    crate::value::KeyRef::Int(i) => Value::Int(i),
                    crate::value::KeyRef::Str(s) => Value::Str(Str::from(s.to_vec())),
                })
                .collect(),
        );
        php_std::sort::sort(&mut columns, php_std::sort::SortFlags::Regular)
            .map_err(|e| Error::new(crate::Kind::Error, e.to_string()))?;
        Ok((rows, columns.values().cloned().collect()))
    }

    /// `executeUpsertBatch()`: `INSERT ... ON DUPLICATE KEY UPDATE` /
    /// `ON CONFLICT ... DO UPDATE` through the builder (SQLite writes its
    /// own statement).
    #[allow(clippy::too_many_arguments)]
    async fn execute_upsert_batch(
        &self,
        name: &str,
        changes: &[Change],
        spatial: &[String],
        attribute: &str,
        operators: &Operators,
        defaults: &[(String, Value)],
        has_operators: bool,
    ) -> Result<()> {
        if self.engine == Engine::Sqlite {
            return self
                .sqlite_execute_upsert_batch(name, changes, spatial, attribute, operators, defaults, has_operators)
                .await;
        }
        let mut builder = self.dialect_builder();
        SqlBuilder::into(&mut builder, &self.table_raw(name));
        for column in spatial {
            let expression = self.spatial_geometry_from_text("?");
            crate::each_builder!(&mut builder, b => {
                b.insert_column_expression(column.as_str(), expression, utopia_query::Array::new());
            });
        }
        if self.insert_requires_alias() {
            builder.insert_as("target");
        }

        let (rows, columns) = self.upsert_rows(changes, operators, defaults, has_operators)?;
        let integer_booleans = self.supports(Capability::IntegerBooleans);
        for values in &rows {
            let mut row = Array::new();
            for key in &columns {
                let array_key = column_key(key);
                let mut value = values.get(&array_key).cloned().unwrap_or(Value::Null);
                let is_spatial = spatial.iter().any(|s| *s == key_text(key));
                if is_spatial {
                    value = self.encode_spatial_write_value(value)?;
                } else if matches!(value, Value::Array(_)) {
                    value = json_encode(&value);
                }
                if integer_booleans
                    && !is_spatial
                    && let Value::Bool(b) = value
                {
                    value = Value::Int(i64::from(b));
                }
                row.set(array_key, value);
            }
            builder.set(crate::value::to_query_array(&row)).map_err(crate::query::query_error)?;
        }

        let conflict_keys: Vec<String> = if self.shared_tables() {
            vec![storage::UID.to_owned(), storage::TENANT.to_owned()]
        } else {
            vec![storage::UID.to_owned()]
        };
        let skip = [storage::UID, storage::SEQUENCE, storage::CREATED_AT, storage::TENANT];
        let skipped = |column: &Value| skip.iter().any(|s| column.loose_eq(&Value::Str(Str::from_static(s))));
        let increment = !empty_value(&Value::Str(Str::from(attribute)));
        let update_columns: Vec<String> = if increment {
            vec![self.filter(attribute), storage::UPDATED_AT.to_owned()]
        } else {
            columns.iter().filter(|c| !skipped(c)).map(key_text).collect()
        };
        builder.on_conflict(conflict_keys, update_columns.clone());

        if increment {
            let column = self.filter(attribute);
            if self.shared_tables() {
                let expression = self.conflict_tenant_increment_expression(&column);
                conflict_set_raw(&mut builder, &column, expression, Vec::new());
                let expression = self.conflict_tenant_expression(storage::UPDATED_AT);
                conflict_set_raw(&mut builder, storage::UPDATED_AT, expression, Vec::new());
            } else {
                let expression = self.conflict_increment_expression(&column);
                conflict_set_raw(&mut builder, &column, expression, Vec::new());
            }
        } else if !operators.is_empty() {
            for column in &columns {
                if skipped(column) {
                    continue;
                }
                let text = key_text(column);
                if let Some((_, operator)) = operators.iter().find(|(k, _)| *k == text) {
                    let expression = self.operator_upsert_expression(&self.filter(&text), operator).await?;
                    conflict_set_raw(&mut builder, &text, expression.sql, expression.bindings);
                } else if self.shared_tables() {
                    let expression = self.conflict_tenant_expression(&text);
                    conflict_set_raw(&mut builder, &text, expression, Vec::new());
                }
            }
        } else if self.shared_tables() {
            for column in &update_columns {
                let expression = self.conflict_tenant_expression(column);
                conflict_set_raw(&mut builder, column, expression, Vec::new());
            }
        }

        let result = crate::each_builder!(&mut builder, b => b.upsert()).map_err(crate::query::query_error)?;
        let mut statement = self.execute_result(&result, Some(Event::DocumentsUpsert), "").await?;
        self.execute(&mut statement).await?;
        statement.statement.close_cursor();
        Ok(())
    }

    /// `SQLite::executeUpsertBatch()`: `INSERT ... ON CONFLICT (...) DO
    /// UPDATE SET ...` with named bindings.
    #[allow(clippy::too_many_arguments)]
    async fn sqlite_execute_upsert_batch(
        &self,
        name: &str,
        changes: &[Change],
        spatial: &[String],
        attribute: &str,
        operators: &Operators,
        defaults: &[(String, Value)],
        has_operators: bool,
    ) -> Result<()> {
        let (rows, columns) = self.upsert_rows(changes, operators, defaults, has_operators)?;
        let quoted: Vec<String> = columns.iter().map(|c| self.quote(&self.filter(&key_text(c)))).collect();
        let column_list = format!("({})", quoted.join(", "));
        let integer_booleans = self.supports(Capability::IntegerBooleans);

        let mut bind_index = 0usize;
        let mut batch_keys = Vec::with_capacity(rows.len());
        let mut bind_values: Vec<(String, Value)> = Vec::new();
        for values in &rows {
            let mut keys = Vec::with_capacity(columns.len());
            for key in &columns {
                let mut value = values.get(&column_key(key)).cloned().unwrap_or(Value::Null);
                if matches!(value, Value::Array(_)) {
                    value = json_encode(&value);
                }
                let bind_key = format!("key_{bind_index}");
                if spatial.iter().any(|s| *s == key_text(key)) && !matches!(value, Value::Null) {
                    keys.push(self.spatial_geometry_from_text(&format!(":{bind_key}")));
                } else {
                    if integer_booleans && let Value::Bool(b) = value {
                        value = Value::Int(i64::from(b));
                    }
                    keys.push(format!(":{bind_key}"));
                }
                bind_values.push((bind_key, value));
                bind_index += 1;
            }
            batch_keys.push(format!("({})", keys.join(", ")));
        }

        let update_clause = |column: &str, increment: bool| -> String {
            let column = self.quote(&self.filter(column));
            let new = if increment { format!("{column} + excluded.{column}") } else { format!("excluded.{column}") };
            if self.shared_tables() {
                let tenant = storage::TENANT;
                format!("{column} = CASE WHEN {tenant} = excluded.{tenant} THEN {new} ELSE {column} END")
            } else {
                format!("{column} = {new}")
            }
        };

        let mut update_columns = Vec::new();
        let mut bind_index = 0usize;
        let skip = [storage::UID, storage::SEQUENCE, storage::CREATED_AT, storage::TENANT];
        if !empty_value(&Value::Str(Str::from(attribute))) {
            update_columns.push(update_clause(attribute, true));
            update_columns.push(update_clause(storage::UPDATED_AT, false));
        } else {
            for column in &columns {
                let text = key_text(column);
                let filtered = self.filter(&text);
                if let Some((_, operator)) = operators.iter().find(|(k, _)| *k == text) {
                    let math = self.operator_math_functions(operator).await;
                    if let Some(sql) = self.operator_sql(&filtered, operator, &mut bind_index, false, math)? {
                        update_columns.push(sql);
                    }
                } else if !skip.iter().any(|s| column.loose_eq(&Value::Str(Str::from_static(s)))) {
                    update_columns.push(update_clause(&filtered, false));
                }
            }
        }

        let conflict_keys = if self.shared_tables() {
            format!("({}, {})", storage::TENANT, storage::UID)
        } else {
            format!("({})", storage::UID)
        };
        let sql = format!(
            "INSERT INTO {} {column_list}\n            VALUES {}\n            ON CONFLICT {conflict_keys} DO UPDATE\n                SET {}",
            self.table(name),
            batch_keys.join(", "),
            update_columns.join(", ")
        );
        let mut statement = self.prepare_statement(&sql, Some(Event::DocumentsUpsert)).await?;
        for (key, value) in bind_values {
            let ty = self.pdo_type(&value)?;
            statement.bind_value(key, value, ty)?;
        }
        let mut op_index = 0usize;
        for column in &columns {
            let text = key_text(column);
            if let Some((_, operator)) = operators.iter().find(|(k, _)| *k == text) {
                self.bind_operator_parameters(&mut statement, operator, &mut op_index)?;
            }
        }
        self.execute(&mut statement).await?;
        statement.statement.close_cursor();
        Ok(())
    }
}

/// `conflictSetRaw($column, $expression, $bindings)`.
fn conflict_set_raw(builder: &mut SqlBuilder, column: &str, expression: String, bindings: Vec<Value>) {
    let bindings = query_values(&bindings);
    crate::each_builder!(builder, b => {
        b.conflict_set_raw(column, expression, bindings);
    });
}

/// A sorted column name (an array key) as the key it was.
fn column_key(key: &Value) -> ArrayKey {
    match key {
        Value::Int(i) => ArrayKey::Int(*i),
        other => ArrayKey::normalize(other.to_php_string().unwrap_or(Str::EMPTY)),
    }
}

/// A sorted column name as text.
fn key_text(key: &Value) -> String {
    crate::value::to_text(key)
}
