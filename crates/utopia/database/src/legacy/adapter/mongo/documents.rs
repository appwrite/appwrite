//! Documents: reads, creates, updates (operators as aggregation
//! pipelines), upserts, increments, deletes, finds, counts and sums.
//!
//! PHP: `Mongo::getDocument`, `createDocument`, `createDocuments`,
//! `insertDocument`, `updateDocument`, `updateDocuments`,
//! `buildOperatorPipeline`, `updateWithPipeline`, `executeUpsert`,
//! `getOperatorExpression`, `getArrayFilterCondition`, `upsertDocuments`,
//! `getUpsertAttributeRemovals`, `getSequences`,
//! `increaseDocumentAttribute`, `deleteDocument`, `deleteDocuments`,
//! `find`, `count`, `sum`.

use utopia_mongo::bson::{Bson, Document as BsonDocument, doc};
use utopia_mongo::{Options, Upsert};

use super::filters::{pair, remove_null_keys, replace_chars, replace_internal_ids_keys, set};
use super::{Mongo, bson, casting};
use crate::adapter::{Adapter, FindOptions};
use crate::change::Change;
use crate::database::Database;
use crate::document::Document;
use crate::error::{Error, Result};
use crate::operator::{Operator, OperatorMethod};
use crate::query::{Method, Query};
use crate::value::{Array, ArrayKey, Ext, KeyRef, Str, Value};

/// The batch size of finds and their `getMore`s.
const BATCH_SIZE: i64 = 1000;

/// `isset($values[$i])`.
fn at(values: &[Value], i: usize) -> Option<&Value> {
    values.get(i).filter(|v| !super::null(v))
}

/// A list value.
fn list(items: Vec<Value>) -> Value {
    Value::Array(Array::from_list(items))
}

/// A string value.
fn s(text: &str) -> Value {
    Value::Str(Str::from(text))
}

/// `['$ifNull' => [$ref, $default]]`.
fn if_null(field: &str, default: Value) -> Value {
    pair("$ifNull", list(vec![s(field), default]))
}

/// `['$literal' => $value]`.
fn literal(value: Value) -> Value {
    pair("$literal", value)
}

/// The cursor's first (or next) batch and id of a find or getMore reply.
fn batch(reply: &BsonDocument, key: &str) -> (Vec<Bson>, i64) {
    let cursor = reply.get_document("cursor").ok();
    let items = cursor.and_then(|c| c.get_array(key).ok()).cloned().unwrap_or_default();
    let id = match cursor.and_then(|c| c.get("id")) {
        Some(Bson::Int64(i)) => *i,
        Some(Bson::Int32(i)) => i64::from(*i),
        _ => 0,
    };
    (items, id)
}

/// `(array) $result`, renamed by `replaceChars('_', '$', ...)` and with
/// its nested objects as arrays: a document of a find.
fn found(result: &Bson) -> Result<Document> {
    let array = match bson::decode(result) {
        Value::Object(a) | Value::Array(a) => a,
        other => Array::from_list(vec![other]),
    };
    let record = replace_chars("_", "$", array);
    match bson::std_class_to_array(Value::Array(record)) {
        Value::Array(a) => Document::new(a),
        _ => Ok(Document::empty()),
    }
}

impl Mongo {
    /// `getDocument($collection, $id, $queries, $forUpdate)`.
    pub(crate) async fn get_document_impl(
        &self,
        collection: &Document,
        id: &str,
        queries: &[Query],
        _for_update: bool,
    ) -> Result<Document> {
        let name = self.name(collection.id())?;
        let mut filters = Array::new();
        set(&mut filters, "_uid", s(id));
        if self.shared_tables() {
            set(&mut filters, "_tenant", self.tenant_filters(collection.id(), &[]));
        }
        let mut options = BsonDocument::new();
        let selections = Self::attribute_selections(queries);
        let projection = !selections.is_empty() && !selections.iter().any(|v| v.loose_eq(&s("*")));
        if projection {
            options.insert("projection", bson::document(&Self::attribute_projection(&selections)?)?);
        }
        let reply = self
            .client
            .find(&name, bson::document(&filters)?, self.options(options))
            .await
            .map_err(|e| self.process_exception(e))?;
        let (results, _) = batch(&reply, "firstBatch");
        let Some(first) = results.first() else { return Ok(Document::empty()) };
        let array = bson::to_array(&bson::decode(first));
        let mut document = Document::new(replace_chars("_", "$", array))?;
        document = self.casting_after_impl(collection, document)?;
        if !projection {
            Self::ensure_relationship_defaults(collection, &mut document);
        }
        Ok(document)
    }

    /// `createDocument($collection, $document)`.
    pub(crate) async fn create_document_impl(&self, collection: &Document, mut document: Document) -> Result<Document> {
        let name = self.name(collection.id())?;
        let sequence = document.sequence().cloned();
        document.remove("$sequence");
        if self.shared_tables() {
            document.set("$tenant", self.tenant());
        }
        let mut record = replace_chars("$", "_", document.as_array().clone());
        if let Some(sequence) = sequence.filter(Value::truthy) {
            set(&mut record, "_id", sequence);
        }
        let result = self.insert_document(&name, remove_null_keys(record)).await?;
        for (key, value) in replace_chars("_", "$", result).into_iter() {
            let key = match &key {
                ArrayKey::Int(i) => i.to_string(),
                ArrayKey::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
            };
            document.set(key.as_str(), value);
        }
        Ok(document)
    }

    /// `insertDocument($name, $document, $options)`: inserts the record and
    /// reads it back.
    async fn insert_document(&self, name: &str, record: Array) -> Result<Array> {
        let options = self.options(BsonDocument::new());
        self.client
            .insert(name, bson::document(&record)?, options.clone())
            .await
            .map_err(|e| self.process_exception(e))?;
        let mut filters = Array::new();
        set(&mut filters, "_uid", record.get_str("_uid").cloned().unwrap_or(Value::Null));
        if self.shared_tables() {
            set(&mut filters, "_tenant", self.tenant_filters(name, &[]));
        }
        let mut fields = doc! { "limit": 1 };
        fields.extend(options.fields);
        let reply = self
            .client
            .find(name, bson::document(&filters)?, Options { session: options.session, fields })
            .await
            .map_err(|e| self.process_exception(e))?;
        let (results, _) = batch(&reply, "firstBatch");
        // PHP reads `firstBatch[0]`: a missing one is null, which toArray() keeps.
        Ok(results.first().map(|r| bson::to_array(&bson::decode(r))).unwrap_or_default())
    }

    /// `createDocuments($collection, $documents)`.
    pub(crate) async fn create_documents_impl(
        &self,
        collection: &Document,
        documents: Vec<Document>,
    ) -> Result<Vec<Document>> {
        let name = self.name(collection.id())?;
        let options = self.options(BsonDocument::new());
        let mut records = Vec::with_capacity(documents.len());
        let mut has_sequence: Option<bool> = None;
        for document in &documents {
            let sequence = document.sequence().cloned().filter(Value::truthy);
            match has_sequence {
                None => has_sequence = Some(sequence.is_some()),
                Some(has) if has == sequence.is_none() => {
                    return Err(Error::database("All documents must have an sequence if one is set"));
                }
                _ => {}
            }
            let mut record = replace_chars("$", "_", document.as_array().clone());
            if let Some(sequence) = sequence {
                set(&mut record, "_id", sequence);
            }
            records.push(record);
        }
        if self.skip_duplicates() {
            if records.is_empty() {
                return Ok(Vec::new());
            }
            let mut operations = Vec::new();
            for record in &records {
                let mut filter = Array::new();
                set(&mut filter, "_uid", record.get_str("_uid").cloned().filter(|v| !super::null(v)).unwrap_or(s("")));
                if self.shared_tables() {
                    let tenant =
                        record.get_str("_tenant").cloned().filter(|v| !super::null(v)).unwrap_or(self.tenant());
                    set(&mut filter, "_tenant", tenant);
                }
                let mut insert = record.clone();
                insert.remove(&ArrayKey::Str(Str::from("_uid")));
                insert.remove(&ArrayKey::Str(Str::from("_tenant")));
                if insert.is_empty() {
                    continue;
                }
                operations.push(Upsert {
                    filter: bson::document(&filter)?,
                    update: doc! { "$setOnInsert": bson::document(&insert)? },
                    multi: false,
                });
            }
            self.client.upsert(&name, operations, options).await.map_err(|e| self.process_exception(e))?;
            return Ok(documents);
        }
        let records: Vec<BsonDocument> = records.iter().map(bson::document).collect::<Result<_>>()?;
        let inserted = self.client.insert_many(&name, records, options).await.map_err(|e| self.process_exception(e))?;
        inserted
            .into_iter()
            .map(|d| {
                let array = bson::to_array(&bson::decode(&Bson::Document(d)));
                Document::new(replace_chars("_", "$", array))
            })
            .collect()
    }

    /// The tenant filter of `collection` added to `filters`, in shared tables.
    fn with_tenant(&self, filters: &mut Array, collection: &str) {
        if self.shared_tables() {
            set(filters, "_tenant", self.tenant_filters(collection, &[]));
        }
    }

    /// `updateDocument($collection, $id, $document, $skipPermissions)`.
    pub(crate) async fn update_document_impl(
        &self,
        collection: &Document,
        id: &str,
        document: Document,
    ) -> Result<Document> {
        let name = self.name(collection.id())?;
        let mut record = replace_chars("$", "_", document.array_copy(&[], &[]));
        let mut filters = Array::new();
        set(&mut filters, "_uid", s(id));
        self.with_tenant(&mut filters, collection.id());
        record.remove(&ArrayKey::Str(Str::from("_id")));
        let options = self.options(BsonDocument::new());
        match self.operator_pipeline(&record)? {
            Some(pipeline) => {
                self.update_with_pipeline(&name, &filters, pipeline, &options, false).await?;
            }
            None => {
                self.client
                    .update(&name, bson::document(&filters)?, doc! { "$set": bson::document(&record)? }, options, false)
                    .await
                    .map_err(|e| self.process_exception(e))?;
            }
        }
        Ok(document)
    }

    /// `updateDocuments($collection, $updates, $documents)`: the documents
    /// matched.
    pub(crate) async fn update_documents_impl(
        &self,
        collection: &Document,
        updates: &Document,
        documents: &[Document],
    ) -> Result<i64> {
        let name = self.name(collection.id())?;
        let options = self.options(BsonDocument::new());
        let sequences: Vec<Value> = documents.iter().map(|d| d.sequence().cloned().unwrap_or(Value::Null)).collect();
        let mut filters = self.build_filters(&[Query::new(Method::Equal, "$sequence", sequences)], "$and")?;
        self.with_tenant(&mut filters, collection.id());
        let record = replace_chars("$", "_", updates.array_copy(&[], &[]));
        if let Some(pipeline) = self.operator_pipeline(&record)? {
            return self.update_with_pipeline(&name, &filters, pipeline, &options, true).await;
        }
        self.client
            .update(&name, bson::document(&filters)?, doc! { "$set": bson::document(&record)? }, options, true)
            .await
            .map_err(|e| self.process_exception(e))
    }

    /// `buildOperatorPipeline($record)`: an aggregation pipeline `$set` of
    /// the operators' expressions and the other values as literals; `None`
    /// without operators.
    fn operator_pipeline(&self, record: &Array) -> Result<Option<Vec<Bson>>> {
        if !record.values().any(Operator::is_operator) {
            return Ok(None);
        }
        let mut stage = BsonDocument::new();
        for (key, value) in record.iter() {
            let key = key_text(key);
            let expression = match value {
                Value::Ext(Ext::Operator(o)) => bson::value(&self.operator_expression(o, &key)?)?,
                other => bson::value(&literal(other.clone()))?,
            };
            stage.insert(key, expression);
        }
        Ok(Some(vec![Bson::Document(doc! { "$set": stage })]))
    }

    /// `updateWithPipeline(...)`: an update whose update is a pipeline;
    /// the documents matched.
    async fn update_with_pipeline(
        &self,
        collection: &str,
        filters: &Array,
        pipeline: Vec<Bson>,
        options: &Options,
        multi: bool,
    ) -> Result<i64> {
        let command = doc! {
            "update": collection,
            "updates": [{ "q": bson::document(filters)?, "u": pipeline, "multi": multi, "upsert": false }],
        };
        let reply = self
            .client
            .query_in(options.session.as_ref(), command, None)
            .await
            .map_err(|e| self.process_exception(e))?;
        Ok(reply.count().unwrap_or(0))
    }

    /// `executeUpsert(...)`: upserts whose updates may be pipelines.
    async fn execute_upsert(
        &self,
        collection: &str,
        operations: Vec<(BsonDocument, Bson)>,
        options: &Options,
    ) -> Result<i64> {
        let updates: Vec<Bson> = operations
            .into_iter()
            .map(|(filter, update)| Bson::Document(doc! { "q": filter, "u": update, "upsert": true, "multi": false }))
            .collect();
        let mut command = doc! { "update": collection, "updates": updates };
        command.extend(options.fields.clone());
        let reply = self
            .client
            .query_in(options.session.as_ref(), command, None)
            .await
            .map_err(|e| self.process_exception(e))?;
        Ok(reply.count().unwrap_or(0))
    }

    /// `getOperatorExpression($operator, $field)`: the aggregation
    /// expression an operator sets its field to.
    fn operator_expression(&self, operator: &Operator, field: &str) -> Result<Value> {
        let reference = format!("${field}");
        let values = operator.values();
        let current = |default: Value| if_null(&reference, default);
        let first_or = |default: Value| at(values, 0).cloned().unwrap_or(default);
        let bounded = |expr: Value, op: &str| -> Value {
            match at(values, 1) {
                Some(limit) => pair(
                    "$cond",
                    list(vec![pair(op, list(vec![expr.clone(), limit.clone()])), expr, current(Value::Int(0))]),
                ),
                None => expr,
            }
        };
        let int_of = |i: usize| -> i64 { at(values, i).map_or(0, cast_int) };
        Ok(match operator.method() {
            OperatorMethod::Increment => {
                bounded(pair("$add", list(vec![current(Value::Int(0)), first_or(Value::Int(1))])), "$lte")
            }
            OperatorMethod::Decrement => {
                bounded(pair("$subtract", list(vec![current(Value::Int(0)), first_or(Value::Int(1))])), "$gte")
            }
            OperatorMethod::Multiply => {
                bounded(pair("$multiply", list(vec![current(Value::Int(0)), first_or(Value::Int(1))])), "$lte")
            }
            OperatorMethod::Divide => bounded(
                pair("$divide", list(vec![current(Value::Int(0)), values.first().cloned().unwrap_or(Value::Null)])),
                "$gte",
            ),
            OperatorMethod::Modulo => {
                pair("$mod", list(vec![current(Value::Int(0)), values.first().cloned().unwrap_or(Value::Null)]))
            }
            OperatorMethod::Power => {
                let base = current(Value::Int(0));
                let exponent = values.first().cloned().unwrap_or(Value::Null);
                let mut expr = pair("$pow", list(vec![base.clone(), exponent.clone()]));
                if let Some(limit) = at(values, 1) {
                    expr = pair(
                        "$cond",
                        list(vec![pair("$lte", list(vec![expr.clone(), limit.clone()])), expr, base.clone()]),
                    );
                    let mut guards = Vec::new();
                    if exponent.cmp_php(&Value::Int(0)) == std::cmp::Ordering::Less {
                        guards.push(pair("$eq", list(vec![base.clone(), Value::Int(0)])));
                    }
                    let e = exponent.to_double();
                    if !Value::Float(e.floor()).loose_eq(&exponent) {
                        guards.push(pair("$lt", list(vec![base.clone(), Value::Int(0)])));
                    }
                    if !guards.is_empty() {
                        let undefined = if guards.len() == 1 { guards.remove(0) } else { pair("$or", list(guards)) };
                        expr = pair("$cond", list(vec![undefined, base, expr]));
                    }
                }
                expr
            }
            OperatorMethod::StringConcat => pair("$concat", list(vec![current(s("")), literal(first_or(s("")))])),
            OperatorMethod::StringReplace => {
                let search = at(values, 0).cloned().unwrap_or(s(""));
                if search.strict_eq(&s("")) {
                    return Ok(current(s("")));
                }
                let mut replace = Array::new();
                set(&mut replace, "input", current(s("")));
                set(&mut replace, "find", literal(search));
                set(&mut replace, "replacement", literal(at(values, 1).cloned().unwrap_or(s(""))));
                pair("$replaceAll", Value::Array(replace))
            }
            OperatorMethod::Toggle => pair("$not", list(vec![current(Value::Bool(false))])),
            OperatorMethod::ArrayAppend => {
                pair("$concatArrays", list(vec![current(list(Vec::new())), literal(list(values.to_vec()))]))
            }
            OperatorMethod::ArrayPrepend => {
                pair("$concatArrays", list(vec![literal(list(values.to_vec())), current(list(Vec::new()))]))
            }
            OperatorMethod::ArrayInsert => {
                let index = Value::Int(int_of(0));
                let value = at(values, 1).cloned().unwrap_or(Value::Null);
                let size = pair("$size", s("$$arr"));
                let before = pair(
                    "$cond",
                    list(vec![
                        pair("$lte", list(vec![index.clone(), Value::Int(0)])),
                        list(Vec::new()),
                        pair("$slice", list(vec![s("$$arr"), index.clone()])),
                    ]),
                );
                let after = pair(
                    "$cond",
                    list(vec![
                        pair("$gte", list(vec![index.clone(), size.clone()])),
                        list(Vec::new()),
                        pair("$slice", list(vec![s("$$arr"), pair("$subtract", list(vec![index, size]))])),
                    ]),
                );
                let mut inner = Array::new();
                set(&mut inner, "vars", pair("arr", current(list(Vec::new()))));
                set(&mut inner, "in", pair("$concatArrays", list(vec![before, literal(list(vec![value])), after])));
                pair("$let", Value::Array(inner))
            }
            OperatorMethod::ArrayRemove => filter_of(
                current(list(Vec::new())),
                pair("$ne", list(vec![s("$$this"), literal(at(values, 0).cloned().unwrap_or(Value::Null))])),
            ),
            OperatorMethod::ArrayUnique => {
                let mut reduce = Array::new();
                set(&mut reduce, "input", current(list(Vec::new())));
                set(&mut reduce, "initialValue", list(Vec::new()));
                set(
                    &mut reduce,
                    "in",
                    pair(
                        "$cond",
                        list(vec![
                            pair("$in", list(vec![s("$$this"), s("$$value")])),
                            s("$$value"),
                            pair("$concatArrays", list(vec![s("$$value"), list(vec![s("$$this")])])),
                        ]),
                    ),
                );
                pair("$reduce", Value::Array(reduce))
            }
            OperatorMethod::ArrayIntersect => filter_of(
                current(list(Vec::new())),
                pair("$in", list(vec![s("$$this"), literal(list(values.to_vec()))])),
            ),
            OperatorMethod::ArrayDiff => filter_of(
                current(list(Vec::new())),
                pair("$not", list(vec![pair("$in", list(vec![s("$$this"), literal(list(values.to_vec()))]))])),
            ),
            OperatorMethod::ArrayFilter => {
                let condition = at(values, 0).map(crate::value::to_text).unwrap_or_default();
                filter_of(current(list(Vec::new())), array_filter_condition(&condition, at(values, 1).cloned()))
            }
            OperatorMethod::DateAddDays | OperatorMethod::DateSubDays => {
                let name = if *operator.method() == OperatorMethod::DateAddDays { "$dateAdd" } else { "$dateSubtract" };
                let mut date = Array::new();
                set(&mut date, "startDate", current(s("$$NOW")));
                set(&mut date, "unit", s("day"));
                set(&mut date, "amount", Value::Int(int_of(0)));
                pair(name, Value::Array(date))
            }
            OperatorMethod::DateSetNow => s("$$NOW"),
            other => return Err(Error::database(format!("Unsupported operator: {}", other.as_str()))),
        })
    }

    /// `upsertDocuments($collection, $attribute, $changes)`.
    pub(crate) async fn upsert_documents_impl(
        &self,
        collection: &Document,
        attribute: &str,
        changes: Vec<Change>,
    ) -> Result<Vec<Document>> {
        if changes.is_empty() {
            return Ok(Vec::new());
        }
        let name = self.name(collection.id())?;
        let attribute = self.filter(attribute)?;
        let mut classic: Vec<Upsert> = Vec::new();
        let mut raw: Vec<(BsonDocument, Bson)> = Vec::new();
        let mut has_pipeline = false;
        for change in &changes {
            let document = &change.new;
            let mut attributes = document.attributes();
            set(&mut attributes, "_uid", s(document.id()));
            set(&mut attributes, "_createdAt", document.raw("$createdAt").cloned().unwrap_or(Value::Null));
            set(&mut attributes, "_updatedAt", document.raw("$updatedAt").cloned().unwrap_or(Value::Null));
            set(
                &mut attributes,
                "_permissions",
                list(document.permissions().into_iter().map(|p| Value::Str(Str::from(p))).collect()),
            );
            if let Some(sequence) = document.sequence().filter(|v| v.truthy()) {
                set(&mut attributes, "_id", sequence.clone());
            }
            if self.shared_tables() {
                set(&mut attributes, "_tenant", document.tenant().unwrap_or(Value::Null));
            }
            let mut record = replace_chars("$", "_", attributes);
            let mut filters = Array::new();
            set(&mut filters, "_uid", s(document.id()));
            self.with_tenant(&mut filters, collection.id());
            record.remove(&ArrayKey::Str(Str::from("_id")));
            let mut unset = self.upsert_removals(&change.old, document, &record);
            let no_sequence = !document.sequence().is_some_and(|v| v.truthy());
            let update: Bson = if !(attribute.is_empty() || attribute == "0") {
                let key = ArrayKey::normalize(Str::from(attribute.as_str()));
                let value = record.get(&key).filter(|v| !super::null(v)).cloned().unwrap_or(Value::Int(0));
                record.remove(&key);
                unset.retain(|k| *k != attribute);
                let mut update = BsonDocument::new();
                let mut inc = BsonDocument::new();
                inc.insert(attribute.clone(), bson::value(&value)?);
                update.insert("$inc", inc);
                update.insert("$set", bson::document(&record)?);
                if !unset.is_empty() {
                    update.insert("$unset", unset_document(&unset));
                }
                Bson::Document(update)
            } else if let Some(pipeline) = self.operator_pipeline(&record)? {
                let mut set_stage = match pipeline.into_iter().next() {
                    Some(Bson::Document(mut stage)) => match stage.remove("$set") {
                        Some(Bson::Document(set)) => set,
                        _ => BsonDocument::new(),
                    },
                    _ => BsonDocument::new(),
                };
                if no_sequence {
                    set_stage.insert("_id", doc! { "$ifNull": ["$_id", self.client.create_uuid()] });
                }
                let mut stages = vec![Bson::Document(doc! { "$set": set_stage })];
                if !unset.is_empty() {
                    stages.push(Bson::Document(
                        doc! { "$unset": unset.iter().map(|k| Bson::from(k.as_str())).collect::<Vec<_>>() },
                    ));
                }
                has_pipeline = true;
                Bson::Array(stages)
            } else {
                let mut update = doc! { "$set": bson::document(&record)? };
                if !unset.is_empty() {
                    update.insert("$unset", unset_document(&unset));
                }
                if no_sequence {
                    update.insert("$setOnInsert", doc! { "_id": self.client.create_uuid() });
                }
                Bson::Document(update)
            };
            let filter = bson::document(&filters)?;
            match &update {
                Bson::Document(d) => classic.push(Upsert { filter: filter.clone(), update: d.clone(), multi: false }),
                _ => classic.push(Upsert { filter: filter.clone(), update: BsonDocument::new(), multi: false }),
            }
            raw.push((filter, update));
        }
        let options = self.options(BsonDocument::new());
        if has_pipeline {
            self.execute_upsert(&name, raw, &options).await?;
        } else {
            self.client.upsert(&name, classic, options).await.map_err(|e| self.process_exception(e))?;
        }
        Ok(changes.into_iter().map(|c| c.new).collect())
    }

    /// `getUpsertAttributeRemovals($old, $new, $record)`: in schemaless
    /// mode, the stored fields of attributes the new document dropped.
    fn upsert_removals(&self, old: &Document, new: &Document, record: &Array) -> Vec<String> {
        let mut out: Vec<String> = Vec::new();
        if self.support_for_attributes() || old.is_empty() {
            return out;
        }
        const PROTECTED: [&str; 6] = ["_uid", "_id", "_createdAt", "_updatedAt", "_permissions", "_tenant"];
        let current = new.attributes();
        for (key, value) in old.attributes().iter() {
            let name = key_text(key);
            if PROTECTED.contains(&name.as_str()) || current.contains_key(&key.to_owned()) {
                continue;
            }
            let mut single = Array::new();
            single.set(key.to_owned(), value.clone());
            let transformed = replace_chars("$", "_", single);
            let Some(db_key) = transformed.keys().next().map(key_text) else { continue };
            let skip = db_key.is_empty()
                || db_key == "0"
                || record.contains_key(&ArrayKey::normalize(Str::from(db_key.as_str())))
                || PROTECTED.contains(&db_key.as_str())
                || out.contains(&db_key);
            if !skip {
                out.push(db_key);
            }
        }
        out
    }

    /// `getSequences($collection, $documents)`: the documents without a
    /// sequence given theirs (`_id`), read by id.
    pub(crate) async fn get_sequences_impl(
        &self,
        collection: &str,
        mut documents: Vec<Document>,
    ) -> Result<Vec<Document>> {
        let mut ids = Vec::new();
        let mut tenants = Vec::new();
        for document in &documents {
            if !document.sequence().is_some_and(|v| v.truthy()) {
                ids.push(s(document.id()));
                if self.shared_tables() {
                    tenants.push(document.tenant().unwrap_or(Value::Null));
                }
            }
        }
        if ids.is_empty() {
            return Ok(documents);
        }
        let name = self.name(collection)?;
        let mut filters = Array::new();
        set(&mut filters, "_uid", pair("$in", list(ids)));
        if self.shared_tables() {
            set(&mut filters, "_tenant", self.tenant_filters(collection, &tenants));
        }
        let options = self.options(doc! { "projection": { "_uid": 1, "_id": 1 }, "batchSize": BATCH_SIZE });
        let mut sequences: Vec<(String, String)> = Vec::new();
        let mut record = |result: &Bson| {
            if let Bson::Document(d) = result {
                let uid = d.get("_uid").map(|v| crate::value::to_text(&bson::decode(v))).unwrap_or_default();
                let id = d
                    .get("_id")
                    .map(|v| String::from_utf8_lossy(bson::php_string(&bson::decode(v)).as_bytes()).into_owned())
                    .unwrap_or_default();
                match sequences.iter_mut().find(|(u, _)| *u == uid) {
                    Some(entry) => entry.1 = id,
                    None => sequences.push((uid, id)),
                }
            }
        };
        let reply =
            self.client.find(&name, bson::document(&filters)?, options).await.map_err(|e| self.process_exception(e))?;
        let (results, mut cursor) = batch(&reply, "firstBatch");
        results.iter().for_each(&mut record);
        while cursor != 0 {
            let more = self.client.get_more(cursor, &name, BATCH_SIZE).await.map_err(|e| self.process_exception(e))?;
            let (results, next) = batch(&more, "nextBatch");
            if results.is_empty() {
                break;
            }
            results.iter().for_each(&mut record);
            cursor = next;
        }
        for document in &mut documents {
            if let Some((_, sequence)) = sequences.iter().find(|(u, _)| u == document.id()) {
                let sequence = sequence.clone();
                document.set("$sequence", s(&sequence));
            }
        }
        Ok(documents)
    }

    /// `increaseDocumentAttribute(...)`: `$inc` within the bounds, and the
    /// update time.
    #[allow(clippy::too_many_arguments)]
    pub(crate) async fn increase_document_attribute_impl(
        &self,
        collection: &str,
        id: &str,
        attribute: &str,
        value: &Value,
        updated_at: &str,
        min: Option<&Value>,
        max: Option<&Value>,
    ) -> Result<bool> {
        let attribute = self.filter(attribute)?;
        let mut filters = Array::new();
        set(&mut filters, "_uid", s(id));
        self.with_tenant(&mut filters, collection);
        if max.is_some() || min.is_some() {
            let mut bounds = Array::new();
            if let Some(max) = max {
                set(&mut bounds, "$lte", max.clone());
            }
            if let Some(min) = min {
                set(&mut bounds, "$gte", min.clone());
            }
            set(&mut filters, &attribute, Value::Array(bounds));
        }
        let mut inc = BsonDocument::new();
        inc.insert(attribute.clone(), bson::value(value)?);
        let updated = bson::value(&casting::utc_datetime(updated_at.as_bytes())?)?;
        let update = doc! { "$inc": inc, "$set": { "_updatedAt": updated } };
        let name = self.name(collection)?;
        self.client
            .update(&name, bson::document(&filters)?, update, self.options(BsonDocument::new()), false)
            .await
            .map_err(|e| self.process_exception(e))?;
        Ok(true)
    }

    /// `deleteDocument($collection, $id)`.
    pub(crate) async fn delete_document_impl(&self, collection: &str, id: &str) -> Result<bool> {
        let name = self.name(collection)?;
        let mut filters = Array::new();
        set(&mut filters, "_uid", s(id));
        self.with_tenant(&mut filters, collection);
        let deleted = self
            .client
            .delete(&name, bson::document(&filters)?, 1, BsonDocument::new(), self.options(BsonDocument::new()))
            .await
            .map_err(super::from_client)?;
        Ok(deleted != 0)
    }

    /// `deleteDocuments($collection, $sequences, $permissionIds)`: the
    /// documents deleted.
    pub(crate) async fn delete_documents_impl(
        &self,
        collection: &str,
        sequences: &[Value],
        _permission_ids: &[String],
    ) -> Result<i64> {
        let name = self.name(collection)?;
        let mut filters = self.build_filters(&[Query::new(Method::Equal, "_id", sequences.to_vec())], "$and")?;
        self.with_tenant(&mut filters, collection);
        let filters = replace_internal_ids_keys(&filters, "$", "_");
        self.client
            .delete(&name, bson::document(&filters)?, 0, BsonDocument::new(), self.options(BsonDocument::new()))
            .await
            .map_err(|e| self.process_exception(e))
    }

    /// `find($collection, $queries, ...)`.
    pub(crate) async fn find_impl(
        &self,
        collection: &Document,
        queries: &[Query],
        options: FindOptions<'_>,
    ) -> Result<Vec<Document>> {
        let name = self.name(collection.id())?;
        let mut queries: Vec<Query> = queries.to_vec();
        Self::escape_query_attributes(collection, &mut queries);
        let mut filters = self.build_filters(&queries, "$and")?;
        self.with_tenant(&mut filters, collection.id());
        if self.authorization().status() {
            let strings = self.permission_strings(options.for_permission);
            let permissions = super::filters::entry_of(&mut filters, "_permissions");
            set(permissions, "$in", list(strings.into_iter().map(|p| Value::Str(Str::from(p))).collect()));
        }
        let mut fields = BsonDocument::new();
        if let Some(limit) = options.limit {
            fields.insert("limit", utopia_mongo::client::int(limit));
        }
        if let Some(offset) = options.offset {
            fields.insert("skip", utopia_mongo::client::int(offset));
        }
        let timeout = self.timeout();
        if timeout != 0 {
            fields.insert("maxTimeMS", utopia_mongo::client::int(timeout));
        }
        let selections = Self::attribute_selections(&queries);
        let projection = !selections.is_empty() && !selections.iter().any(|v| v.loose_eq(&s("*")));
        if projection {
            fields.insert("projection", bson::document(&Self::attribute_projection(&selections)?)?);
        }
        let mut sort = BsonDocument::new();
        let mut ors: Vec<Value> = Vec::new();
        for (i, original) in options.order_attributes.iter().enumerate() {
            let attribute = self.filter(Self::internal_key(original))?;
            let order = self.filter(options.order_types.get(i).map_or(Database::ORDER_ASC, String::as_str))?;
            let mut direction = order.clone();
            if options.cursor_direction == Database::CURSOR_BEFORE {
                direction = if direction == Database::ORDER_ASC { Database::ORDER_DESC } else { Database::ORDER_ASC }
                    .to_owned();
            }
            sort.insert(attribute.clone(), Self::order(&direction)?);
            let method = if options.cursor_direction == Database::CURSOR_AFTER {
                if order == Database::ORDER_DESC { Method::LessThan } else { Method::GreaterThan }
            } else if order == Database::ORDER_DESC {
                Method::GreaterThan
            } else {
                Method::LessThan
            };
            let operator = Self::query_operator(&method)?;
            if !options.cursor.is_empty() {
                let mut conditions = Vec::new();
                for previous in options.order_attributes.iter().take(i) {
                    let previous_attribute = self.filter(Self::internal_key(previous))?;
                    let value = options.cursor.get_str(previous).cloned().unwrap_or(Value::Null);
                    conditions.push(pair(&previous_attribute, value));
                }
                let value = options.cursor.get_str(original).cloned().unwrap_or(Value::Null);
                if original == "$sequence" && options.order_attributes.len() == 1 {
                    set(&mut filters, &attribute, pair(operator, value));
                    break;
                }
                conditions.push(pair(&attribute, pair(operator, value)));
                ors.push(pair("$and", list(conditions)));
            }
        }
        if !sort.is_empty() {
            fields.insert("sort", sort);
        }
        if !ors.is_empty() {
            set(&mut filters, "$or", list(ors));
        }
        let filters = replace_internal_ids_keys(&filters, "$", "_");
        fields.insert("batchSize", BATCH_SIZE);
        let mut found_documents = Vec::new();
        let reply = self
            .client
            .find(&name, bson::document(&filters)?, self.options(fields))
            .await
            .map_err(|e| self.process_exception(e))?;
        let (results, mut cursor) = batch(&reply, "firstBatch");
        for result in &results {
            found_documents.push(found(result)?);
        }
        let mut failure = None;
        while cursor != 0 {
            match self.client.get_more(cursor, &name, BATCH_SIZE).await {
                Ok(more) => {
                    let (results, next) = batch(&more, "nextBatch");
                    if results.is_empty() {
                        break;
                    }
                    for result in &results {
                        found_documents.push(found(result)?);
                    }
                    cursor = next;
                }
                Err(e) => {
                    failure = Some(self.process_exception(e));
                    break;
                }
            }
        }
        if cursor != 0 {
            let _ = self.client.query(doc! { "killCursors": &name, "cursors": [cursor] }, None).await;
        }
        if let Some(e) = failure {
            return Err(e);
        }
        if options.cursor_direction == Database::CURSOR_BEFORE {
            found_documents.reverse();
        }
        if !projection {
            for document in &mut found_documents {
                Self::ensure_relationship_defaults(collection, document);
            }
        }
        Ok(found_documents)
    }

    /// `count($collection, $queries, $max)`: an aggregation counting the
    /// matches (`0` when it fails, but on a timeout).
    pub(crate) async fn count_impl(&self, collection: &Document, queries: &[Query], max: Option<i64>) -> Result<i64> {
        let name = self.name(collection.id())?;
        let mut queries: Vec<Query> = queries.to_vec();
        Self::escape_query_attributes(collection, &mut queries);
        let mut filters = self.build_filters(&queries, "$and")?;
        self.with_tenant(&mut filters, collection.id());
        if self.authorization().status() {
            let strings = self.permission_strings(Database::PERMISSION_READ);
            let permissions = super::filters::entry_of(&mut filters, "_permissions");
            set(permissions, "$in", list(strings.into_iter().map(|p| Value::Str(Str::from(p))).collect()));
        }
        let mut fields = BsonDocument::new();
        let timeout = self.timeout();
        if timeout != 0 {
            fields.insert("maxTimeMS", utopia_mongo::client::int(timeout));
        }
        let mut pipeline: Vec<Bson> = Vec::new();
        if !filters.is_empty() {
            pipeline.push(Bson::Document(doc! { "$match": bson::document(&filters)? }));
        }
        let limited = max.is_some_and(|m| m > 0);
        if let Some(max) = max.filter(|m| *m > 0) {
            pipeline.push(Bson::Document(doc! { "$limit": utopia_mongo::client::int(max) }));
        }
        if limited {
            pipeline.push(Bson::Document(doc! { "$group": { "_id": Bson::Null, "total": { "$sum": 1 } } }));
        } else {
            pipeline.push(Bson::Document(doc! { "$count": "total" }));
        }
        match self.client.aggregate(&name, pipeline, self.options(fields)).await {
            Ok(reply) => {
                let (results, _) = batch(&reply, "firstBatch");
                Ok(match results.first() {
                    Some(Bson::Document(first)) => match first.get("total") {
                        Some(total) if !matches!(total, Bson::Null) => cast_int(&bson::decode(total)),
                        _ => 0,
                    },
                    _ => 0,
                })
            }
            Err(e) => {
                let processed = self.process_exception(e);
                if processed.is_a(crate::error::Kind::Timeout) {
                    return Err(processed);
                }
                Ok(0)
            }
        }
    }

    /// `sum($collection, $attribute, $queries, $max)`: an aggregation
    /// summing the attribute over the matches.
    pub(crate) async fn sum_impl(
        &self,
        collection: &Document,
        attribute: &str,
        queries: &[Query],
        max: Option<i64>,
    ) -> Result<Value> {
        let name = self.name(collection.id())?;
        let mut filters = self.build_filters(queries, "$and")?;
        self.with_tenant(&mut filters, collection.id());
        if self.authorization().status() {
            let strings = self.permission_strings(Database::PERMISSION_READ);
            let permissions = super::filters::entry_of(&mut filters, "_permissions");
            set(permissions, "$in", list(strings.into_iter().map(|p| Value::Str(Str::from(p))).collect()));
        }
        let mut pipeline: Vec<Bson> = Vec::new();
        if !filters.is_empty() {
            pipeline.push(Bson::Document(doc! { "$match": bson::array(&filters)? }));
        }
        if let Some(max) = max.filter(|m| *m != 0) {
            pipeline.push(Bson::Document(doc! { "$limit": utopia_mongo::client::int(max) }));
        }
        pipeline.push(Bson::Document(
            doc! { "$group": { "_id": Bson::Null, "total": { "$sum": format!("${attribute}") } } },
        ));
        let mut fields = BsonDocument::new();
        let timeout = self.timeout();
        if timeout != 0 {
            fields.insert("maxTimeMS", utopia_mongo::client::int(timeout));
        }
        let reply = self
            .client
            .aggregate(&name, pipeline, self.options(fields))
            .await
            .map_err(|e| self.process_exception(e))?;
        let (results, _) = batch(&reply, "firstBatch");
        Ok(match results.first() {
            Some(Bson::Document(first)) => match first.get("total") {
                Some(total) if !matches!(total, Bson::Null) => bson::decode(total),
                _ => Value::Int(0),
            },
            _ => Value::Int(0),
        })
    }
}

/// `(int) $value`.
fn cast_int(value: &Value) -> i64 {
    match value {
        Value::Null => 0,
        Value::Bool(b) => i64::from(*b),
        Value::Int(i) => *i,
        Value::Float(f) => php_std::format::Arg::Float(*f).to_int(),
        Value::Str(s) => php_std::format::Arg::Str(s.as_bytes()).to_int(),
        Value::Array(a) => i64::from(!a.is_empty()),
        Value::Object(_) | Value::Ext(_) => 1,
    }
}

/// A key's text.
fn key_text(k: KeyRef<'_>) -> String {
    match k {
        KeyRef::Int(i) => i.to_string(),
        KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
    }
}

/// `['$unset' => [$field => '', ...]]`'s document.
fn unset_document(fields: &[String]) -> BsonDocument {
    fields.iter().map(|f| (f.clone(), Bson::from(""))).collect()
}

/// `['$filter' => ['input' => $input, 'cond' => $cond]]`.
fn filter_of(input: Value, condition: Value) -> Value {
    let mut filter = Array::new();
    set(&mut filter, "input", input);
    set(&mut filter, "cond", condition);
    pair("$filter", Value::Array(filter))
}

/// `getArrayFilterCondition($condition, $compare)`.
fn array_filter_condition(condition: &str, compare: Option<Value>) -> Value {
    let value = literal(compare.unwrap_or(Value::Null));
    let this = || s("$$this");
    match condition {
        "equal" => pair("$eq", list(vec![this(), value])),
        "notEqual" => pair("$ne", list(vec![this(), value])),
        "greaterThan" => pair("$gt", list(vec![this(), value])),
        "greaterThanEqual" => pair("$gte", list(vec![this(), value])),
        "lessThan" => pair("$lt", list(vec![this(), value])),
        "lessThanEqual" => pair("$lte", list(vec![this(), value])),
        "isNull" => pair("$eq", list(vec![this(), Value::Null])),
        "isNotNull" => pair("$ne", list(vec![this(), Value::Null])),
        _ => literal(Value::Bool(true)),
    }
}
