//! PHP `Utopia\Database\Adapter\Memory`.

use std::cmp::Ordering;

use chrono::NaiveDateTime;
use indexmap::IndexMap;

use crate::adapter::{filter_key, Adapter, AdapterState};
use crate::constants::{
    CURSOR_AFTER, CURSOR_BEFORE, INTERNAL_ATTRIBUTES, INTERNAL_INDEXES, MAX_BIG_INT, METADATA,
    ORDER_ASC, ORDER_DESC, ORDER_RANDOM, PERMISSION_READ, VAR_INTEGER, VAR_RELATIONSHIP,
};
use crate::document::Document;
use crate::error::{DatabaseError, Result};
use crate::operator::{
    Operator, TYPE_ARRAY_APPEND, TYPE_ARRAY_DIFF, TYPE_ARRAY_INSERT, TYPE_ARRAY_PREPEND,
    TYPE_ARRAY_REMOVE, TYPE_ARRAY_UNIQUE, TYPE_DECREMENT, TYPE_INCREMENT, TYPE_STRING_CONCAT,
    TYPE_STRING_REPLACE, TYPE_TOGGLE,
};
use crate::query::{
    Query, TYPE_AND, TYPE_BETWEEN, TYPE_CONTAINS, TYPE_CONTAINS_ALL, TYPE_CONTAINS_ANY,
    TYPE_CURSOR_AFTER, TYPE_CURSOR_BEFORE, TYPE_ENDS_WITH, TYPE_EQUAL, TYPE_GREATER,
    TYPE_GREATER_EQUAL, TYPE_IS_NOT_NULL, TYPE_IS_NULL, TYPE_LESSER, TYPE_LESSER_EQUAL, TYPE_LIMIT,
    TYPE_NOT_BETWEEN, TYPE_NOT_CONTAINS, TYPE_NOT_ENDS_WITH, TYPE_NOT_EQUAL, TYPE_NOT_SEARCH,
    TYPE_NOT_STARTS_WITH, TYPE_OFFSET, TYPE_OR, TYPE_ORDER_ASC, TYPE_ORDER_DESC, TYPE_ORDER_RANDOM,
    TYPE_REGEX, TYPE_SEARCH, TYPE_SELECT, TYPE_STARTS_WITH,
};
use crate::value::{loose_equals, AttrValue};

#[derive(Debug, Clone, Default)]
struct CollectionStore {
    attributes: IndexMap<String, IndexMap<String, AttrValue>>,
    indexes: IndexMap<String, IndexMap<String, AttrValue>>,
    documents: IndexMap<String, IndexMap<String, AttrValue>>,
    sequence: i64,
}

#[derive(Clone, Debug)]
enum Inverse {
    DropDatabase(String),
    RestoreCollection {
        key: String,
        store: CollectionStore,
        database: String,
        slot: String,
    },
    DropCollection {
        key: String,
        database: String,
        slot: String,
    },
    RestoreDocument {
        key: String,
        doc_key: String,
        row: Option<IndexMap<String, AttrValue>>,
        sequence: i64,
    },
}

/// PHP `Utopia\Database\Adapter\Memory`.
#[derive(Debug)]
pub struct Memory {
    state: AdapterState,
    databases: IndexMap<String, IndexMap<String, String>>,
    data: IndexMap<String, CollectionStore>,
    journals: Vec<Vec<Inverse>>,
    support_for_attributes: bool,
}

impl Default for Memory {
    fn default() -> Self {
        Self::new()
    }
}

impl Memory {
    #[must_use]
    pub fn new() -> Self {
        Self {
            state: AdapterState::default(),
            databases: IndexMap::new(),
            data: IndexMap::new(),
            journals: Vec::new(),
            support_for_attributes: true,
        }
    }

    fn key(&self, collection: &str) -> String {
        format!(
            "{}.{}_{}",
            self.state.database,
            self.state.namespace,
            filter_key(collection)
        )
    }

    fn document_key(&self, id: &str, tenant: Option<&AttrValue>) -> String {
        let id = id.to_ascii_lowercase();
        if !self.state.shared_tables {
            return id;
        }
        let tenant = tenant.cloned().or_else(|| self.state.tenant.clone());
        let tenant_s = match tenant {
            Some(AttrValue::String(s)) => s,
            Some(AttrValue::Number(n)) => n.to_string(),
            _ => String::new(),
        };
        format!("{tenant_s}|{id}")
    }

    fn journal(&mut self, inverse: Inverse) {
        if self.state.in_transaction == 0 {
            return;
        }
        if let Some(frame) = self.journals.last_mut() {
            frame.push(inverse);
        }
    }

    fn apply_inverse(&mut self, inverse: Inverse) {
        match inverse {
            Inverse::DropDatabase(name) => {
                self.databases.shift_remove(&name);
            }
            Inverse::RestoreCollection {
                key,
                store,
                database,
                slot,
            } => {
                self.data.insert(key.clone(), store);
                self.databases
                    .entry(database)
                    .or_default()
                    .insert(slot, key);
            }
            Inverse::DropCollection {
                key,
                database,
                slot,
            } => {
                self.data.shift_remove(&key);
                if let Some(db) = self.databases.get_mut(&database) {
                    db.shift_remove(&slot);
                }
            }
            Inverse::RestoreDocument {
                key,
                doc_key,
                row,
                sequence,
            } => {
                if let Some(store) = self.data.get_mut(&key) {
                    store.sequence = sequence;
                    if let Some(row) = row {
                        store.documents.insert(doc_key, row);
                    } else {
                        store.documents.shift_remove(&doc_key);
                    }
                }
            }
        }
    }

    fn document_to_row(&self, document: &Document) -> IndexMap<String, AttrValue> {
        let mut row = IndexMap::new();
        for (attribute, value) in document.get_attributes() {
            row.insert(filter_key(&attribute), value);
        }
        row.insert("_uid".into(), AttrValue::from(document.get_id()));
        row.insert(
            "_createdAt".into(),
            document
                .get_created_at()
                .map_or(AttrValue::Null, AttrValue::from),
        );
        row.insert(
            "_updatedAt".into(),
            document
                .get_updated_at()
                .map_or(AttrValue::Null, AttrValue::from),
        );
        row.insert(
            "_permissions".into(),
            AttrValue::from(document.get_permissions()),
        );
        if self.state.shared_tables {
            row.insert(
                "_tenant".into(),
                document
                    .get_tenant()
                    .or_else(|| self.state.tenant.clone())
                    .unwrap_or(AttrValue::Null),
            );
        }
        row
    }

    fn row_to_document(
        &self,
        row: &IndexMap<String, AttrValue>,
        selections: &[String],
        storage_key: Option<&str>,
    ) -> IndexMap<String, AttrValue> {
        let allowed: Option<Vec<String>> =
            if !selections.is_empty() && !selections.iter().any(|s| s == "*") {
                Some(selections.iter().map(|s| filter_key(s)).collect())
            } else {
                None
            };
        let mut document = IndexMap::new();
        for (key, value) in row {
            match key.as_str() {
                "_id" => {
                    document.insert("$sequence".into(), AttrValue::from(value_to_string(value)));
                }
                "_uid" => {
                    document.insert("$id".into(), value.clone());
                }
                "_tenant" => {
                    document.insert("$tenant".into(), value.clone());
                }
                "_createdAt" => {
                    document.insert("$createdAt".into(), value.clone());
                }
                "_updatedAt" => {
                    document.insert("$updatedAt".into(), value.clone());
                }
                "_permissions" => {
                    document.insert("$permissions".into(), value.clone());
                }
                _ => {
                    if let Some(allowed) = &allowed {
                        if !allowed.contains(key) {
                            continue;
                        }
                    }
                    document.insert(key.clone(), value.clone());
                }
            }
        }
        if let Some(storage_key) = storage_key {
            if let Some(store) = self.data.get(storage_key) {
                for (attribute_id, definition) in &store.attributes {
                    if definition.get("type").and_then(AttrValue::as_str) != Some(VAR_RELATIONSHIP)
                    {
                        continue;
                    }
                    if let Some(allowed) = &allowed {
                        if !allowed.contains(attribute_id) {
                            continue;
                        }
                    }
                    document
                        .entry(attribute_id.clone())
                        .or_insert(AttrValue::Null);
                }
            }
        }
        document
    }

    fn extract_selections(queries: &[Query]) -> Vec<String> {
        let mut selections = Vec::new();
        for query in queries {
            if query.get_method() == TYPE_SELECT {
                for value in query.get_values() {
                    if let Some(s) = value.as_str() {
                        selections.push(s.to_owned());
                    }
                }
            }
        }
        selections
    }

    fn matches(&self, row: &IndexMap<String, AttrValue>, query: &Query) -> Result<bool> {
        let method = query.get_method();
        if method == TYPE_AND {
            for sub in query.get_values() {
                let Some(q) = sub.as_query() else {
                    return Ok(false);
                };
                if !self.matches(row, q)? {
                    return Ok(false);
                }
            }
            return Ok(true);
        }
        if method == TYPE_OR {
            for sub in query.get_values() {
                if let Some(q) = sub.as_query() {
                    if self.matches(row, q)? {
                        return Ok(true);
                    }
                }
            }
            return Ok(false);
        }
        let raw = query.get_attribute();
        let value = resolve_attribute(row, raw);
        let query_values = query.get_values();
        Ok(match method {
            TYPE_EQUAL => query_values.iter().any(|c| loose_equals(&value, c)),
            TYPE_NOT_EQUAL => {
                if value.is_null() {
                    false
                } else {
                    !query_values.iter().any(|c| loose_equals(&value, c))
                }
            }
            TYPE_LESSER => {
                cmp_attr(&value, query_values.first().unwrap_or(&AttrValue::Null))
                    == Some(Ordering::Less)
            }
            TYPE_LESSER_EQUAL => {
                matches!(
                    cmp_attr(&value, query_values.first().unwrap_or(&AttrValue::Null)),
                    Some(Ordering::Less | Ordering::Equal)
                )
            }
            TYPE_GREATER => {
                cmp_attr(&value, query_values.first().unwrap_or(&AttrValue::Null))
                    == Some(Ordering::Greater)
            }
            TYPE_GREATER_EQUAL => {
                matches!(
                    cmp_attr(&value, query_values.first().unwrap_or(&AttrValue::Null)),
                    Some(Ordering::Greater | Ordering::Equal)
                )
            }
            TYPE_IS_NULL => value.is_null(),
            TYPE_IS_NOT_NULL => !value.is_null(),
            TYPE_BETWEEN => {
                let start = query_values.first().unwrap_or(&AttrValue::Null);
                let end = query_values.get(1).unwrap_or(&AttrValue::Null);
                !value.is_null()
                    && matches!(
                        cmp_attr(&value, start),
                        Some(Ordering::Greater | Ordering::Equal)
                    )
                    && matches!(
                        cmp_attr(&value, end),
                        Some(Ordering::Less | Ordering::Equal)
                    )
            }
            TYPE_NOT_BETWEEN => {
                if value.is_null() {
                    false
                } else {
                    let start = query_values.first().unwrap_or(&AttrValue::Null);
                    let end = query_values.get(1).unwrap_or(&AttrValue::Null);
                    cmp_attr(&value, start) == Some(Ordering::Less)
                        || cmp_attr(&value, end) == Some(Ordering::Greater)
                }
            }
            TYPE_STARTS_WITH => match (
                value.as_str(),
                query_values.first().and_then(AttrValue::as_str),
            ) {
                (Some(h), Some(n)) => h.starts_with(n),
                _ => false,
            },
            TYPE_NOT_STARTS_WITH => {
                if value.is_null() {
                    false
                } else {
                    match (
                        value.as_str(),
                        query_values.first().and_then(AttrValue::as_str),
                    ) {
                        (Some(h), Some(n)) => !h.starts_with(n),
                        _ => true,
                    }
                }
            }
            TYPE_ENDS_WITH => match (
                value.as_str(),
                query_values.first().and_then(AttrValue::as_str),
            ) {
                (Some(h), Some(n)) => h.ends_with(n),
                _ => false,
            },
            TYPE_NOT_ENDS_WITH => {
                if value.is_null() {
                    false
                } else {
                    match (
                        value.as_str(),
                        query_values.first().and_then(AttrValue::as_str),
                    ) {
                        (Some(h), Some(n)) => !h.ends_with(n),
                        _ => true,
                    }
                }
            }
            TYPE_CONTAINS | TYPE_CONTAINS_ANY => contains_match(&value, query_values),
            TYPE_NOT_CONTAINS => {
                if value.is_null() {
                    false
                } else {
                    !contains_match(&value, query_values)
                }
            }
            TYPE_CONTAINS_ALL => {
                let Some(haystack) = decode_array(&value) else {
                    return Ok(false);
                };
                query_values
                    .iter()
                    .all(|needle| haystack.iter().any(|item| loose_equals(item, needle)))
            }
            TYPE_SEARCH => match (
                value.as_str(),
                query_values.first().and_then(AttrValue::as_str),
            ) {
                (Some(h), Some(n)) if !n.is_empty() => matches_fulltext(h, n),
                _ => false,
            },
            TYPE_NOT_SEARCH => {
                if value.is_null() {
                    false
                } else {
                    match (
                        value.as_str(),
                        query_values.first().and_then(AttrValue::as_str),
                    ) {
                        (Some(h), Some(n)) if !n.is_empty() => !matches_fulltext(h, n),
                        (Some(_), _) => true,
                        _ => true,
                    }
                }
            }
            TYPE_REGEX => match (
                value.as_str(),
                query_values.first().and_then(AttrValue::as_str),
            ) {
                (Some(h), Some(p)) => regex::Regex::new(p).map(|r| r.is_match(h)).unwrap_or(false),
                _ => false,
            },
            _ => {
                return Err(DatabaseError::database(format!(
                    "Query method not implemented in the Memory adapter: {method}"
                )));
            }
        })
    }

    fn fused_filter(
        &self,
        key: &str,
        collection_id: &str,
        queries: &[Query],
        for_permission: &str,
    ) -> Result<Vec<IndexMap<String, AttrValue>>> {
        let Some(store) = self.data.get(key) else {
            return Ok(Vec::new());
        };
        let effective: Vec<&Query> = queries
            .iter()
            .filter(|q| {
                !matches!(
                    q.get_method(),
                    TYPE_SELECT
                        | TYPE_ORDER_ASC
                        | TYPE_ORDER_DESC
                        | TYPE_ORDER_RANDOM
                        | TYPE_LIMIT
                        | TYPE_OFFSET
                        | TYPE_CURSOR_AFTER
                        | TYPE_CURSOR_BEFORE
                )
            })
            .collect();
        let tenant_check = self.state.shared_tables;
        let tenant = if tenant_check {
            self.state.tenant.clone()
        } else {
            None
        };
        let allow_null_tenant = tenant_check && collection_id == METADATA;
        let allow_set = self.build_permission_allow_set(key, for_permission);
        let mut output = Vec::new();
        for row in store.documents.values() {
            if tenant_check {
                let row_tenant = row.get("_tenant").cloned().unwrap_or(AttrValue::Null);
                if !(allow_null_tenant && row_tenant.is_null()) && !tenants_eq(&row_tenant, &tenant)
                {
                    continue;
                }
            }
            if let Some(allow) = &allow_set {
                let uid = row.get("_uid").and_then(AttrValue::as_str).unwrap_or("");
                if !allow.contains(&uid.to_owned()) {
                    continue;
                }
            }
            let mut matched = true;
            for query in &effective {
                if !self.matches(row, query)? {
                    matched = false;
                    break;
                }
            }
            if matched {
                output.push(row.clone());
            }
        }
        Ok(output)
    }

    fn build_permission_allow_set(&self, _key: &str, _for_permission: &str) -> Option<Vec<String>> {
        if !self.state.authorization.get_status() {
            return None;
        }
        None
    }

    fn apply_operators(
        attrs: IndexMap<String, AttrValue>,
        existing: &IndexMap<String, AttrValue>,
    ) -> IndexMap<String, AttrValue> {
        let mut out = IndexMap::new();
        for (key, value) in attrs {
            if let AttrValue::Operator(op) = &value {
                out.insert(
                    key.clone(),
                    apply_operator(op, existing.get(&filter_key(&key))),
                );
            } else {
                out.insert(key, value);
            }
        }
        out
    }
}

fn tenants_eq(row: &AttrValue, tenant: &Option<AttrValue>) -> bool {
    match tenant {
        None => row.is_null(),
        Some(t) => loose_equals(row, t),
    }
}

fn value_to_string(value: &AttrValue) -> String {
    match value {
        AttrValue::String(s) => s.clone(),
        AttrValue::Number(n) => n.to_string(),
        _ => value.to_json().to_string(),
    }
}

fn resolve_attribute(row: &IndexMap<String, AttrValue>, attribute: &str) -> AttrValue {
    if attribute.contains('.') {
        let mut current = AttrValue::Array(row.clone());
        for part in attribute.split('.') {
            current = match current {
                AttrValue::Array(map) => map.get(part).cloned().unwrap_or(AttrValue::Null),
                AttrValue::Document(d) => d.get_attribute(part).clone(),
                _ => AttrValue::Null,
            };
        }
        return current;
    }
    let filtered = filter_key(attribute);
    if let Some(v) = row.get(attribute).or_else(|| row.get(&filtered)) {
        return v.clone();
    }
    match attribute {
        "$id" => row.get("_uid").cloned().unwrap_or(AttrValue::Null),
        "$sequence" => row.get("_id").cloned().unwrap_or(AttrValue::Null),
        "$createdAt" => row.get("_createdAt").cloned().unwrap_or(AttrValue::Null),
        "$updatedAt" => row.get("_updatedAt").cloned().unwrap_or(AttrValue::Null),
        "$permissions" => row.get("_permissions").cloned().unwrap_or(AttrValue::Null),
        "$tenant" => row.get("_tenant").cloned().unwrap_or(AttrValue::Null),
        _ => AttrValue::Null,
    }
}

fn cmp_attr(left: &AttrValue, right: &AttrValue) -> Option<Ordering> {
    if left.is_null() || right.is_null() {
        return None;
    }
    if let (Some(a), Some(b)) = (left.as_f64(), right.as_f64()) {
        return a.partial_cmp(&b);
    }
    if let (Some(a), Some(b)) = (left.as_str(), right.as_str()) {
        return Some(a.cmp(b));
    }
    None
}

fn decode_array(value: &AttrValue) -> Option<Vec<AttrValue>> {
    match value {
        AttrValue::Array(items) => Some(items.values().cloned().collect()),
        AttrValue::String(s) => serde_json::from_str::<Vec<serde_json::Value>>(s)
            .ok()
            .map(|v| v.into_iter().map(AttrValue::from_json).collect()),
        _ => None,
    }
}

fn contains_match(value: &AttrValue, needles: &[AttrValue]) -> bool {
    if let Some(haystack) = decode_array(value) {
        return needles
            .iter()
            .any(|needle| haystack.iter().any(|item| loose_equals(item, needle)));
    }
    if let (Some(hay), _) = (value.as_str(), needles) {
        return needles.iter().any(|n| {
            n.as_str().is_some_and(|needle| {
                hay.to_ascii_lowercase()
                    .contains(&needle.to_ascii_lowercase())
            })
        });
    }
    false
}

fn matches_fulltext(haystack: &str, needle: &str) -> bool {
    let trimmed = needle.trim();
    if trimmed.starts_with('"') && trimmed.ends_with('"') && trimmed.len() >= 2 {
        let phrase = trimmed[1..trimmed.len() - 1].to_ascii_lowercase();
        if phrase.is_empty() {
            return false;
        }
        return haystack.to_ascii_lowercase().contains(&phrase);
    }
    let hay_tokens = tokenize(haystack);
    let needle_tokens = tokenize(needle);
    if hay_tokens.is_empty() || needle_tokens.is_empty() {
        return false;
    }
    for token in needle_tokens {
        if let Some(prefix) = token.strip_suffix('*') {
            if prefix.is_empty() {
                continue;
            }
            if hay_tokens.iter().any(|h| h.starts_with(prefix)) {
                return true;
            }
        } else if hay_tokens.contains(&token) {
            return true;
        }
    }
    false
}

fn tokenize(text: &str) -> Vec<String> {
    text.to_ascii_lowercase()
        .split(|c: char| !c.is_alphanumeric() && c != '*')
        .filter(|s| !s.is_empty())
        .map(str::to_owned)
        .collect()
}

fn apply_operator(op: &Operator, current: Option<&AttrValue>) -> AttrValue {
    let current = current.cloned().unwrap_or(AttrValue::Null);
    match op.get_method() {
        TYPE_INCREMENT => {
            let by = op.get_value().as_f64().unwrap_or(0.0);
            let next = current.as_f64().unwrap_or(0.0) + by;
            if let Some(max) = op.get_values().get(1).and_then(AttrValue::as_f64) {
                AttrValue::from(next.min(max))
            } else if next.fract() == 0.0 {
                AttrValue::from(next as i64)
            } else {
                AttrValue::from(next)
            }
        }
        TYPE_DECREMENT => {
            let by = op.get_value().as_f64().unwrap_or(0.0);
            let next = current.as_f64().unwrap_or(0.0) - by;
            if let Some(min) = op.get_values().get(1).and_then(AttrValue::as_f64) {
                AttrValue::from(next.max(min))
            } else if next.fract() == 0.0 {
                AttrValue::from(next as i64)
            } else {
                AttrValue::from(next)
            }
        }
        TYPE_TOGGLE => AttrValue::Bool(!current.as_bool().unwrap_or(false)),
        TYPE_STRING_CONCAT => {
            let suffix = op.get_value().as_str().unwrap_or("");
            AttrValue::from(format!("{}{suffix}", current.as_str().unwrap_or("")))
        }
        TYPE_STRING_REPLACE => {
            let search = op
                .get_values()
                .first()
                .and_then(AttrValue::as_str)
                .unwrap_or("");
            let replace = op
                .get_values()
                .get(1)
                .and_then(AttrValue::as_str)
                .unwrap_or("");
            AttrValue::from(current.as_str().unwrap_or("").replace(search, replace))
        }
        TYPE_ARRAY_APPEND => {
            let mut arr = current;
            for v in op.get_values() {
                arr.push(v.clone());
            }
            arr
        }
        TYPE_ARRAY_PREPEND => {
            let mut arr = current;
            for v in op.get_values().iter().rev() {
                arr.prepend(v.clone());
            }
            arr
        }
        TYPE_ARRAY_REMOVE => {
            if let AttrValue::Array(mut items) = current {
                let needle = op.get_value();
                items.retain(|_, v| !loose_equals(v, needle));
                AttrValue::Array(items)
            } else {
                current
            }
        }
        TYPE_ARRAY_UNIQUE => {
            if let AttrValue::Array(items) = current {
                let mut seen = Vec::new();
                let mut out = IndexMap::new();
                let mut i = 0i64;
                for v in items.into_values() {
                    if seen.iter().any(|s| loose_equals(s, &v)) {
                        continue;
                    }
                    seen.push(v.clone());
                    out.insert(i.to_string(), v);
                    i += 1;
                }
                AttrValue::Array(out)
            } else {
                current
            }
        }
        TYPE_ARRAY_DIFF => current,
        TYPE_ARRAY_INSERT => current,
        _ => current,
    }
}

fn min_datetime() -> NaiveDateTime {
    chrono::NaiveDate::from_ymd_opt(1, 1, 1)
        .unwrap()
        .and_hms_opt(0, 0, 0)
        .unwrap()
}

impl Adapter for Memory {
    fn state(&self) -> &AdapterState {
        &self.state
    }
    fn state_mut(&mut self) -> &mut AdapterState {
        &mut self.state
    }

    fn set_timeout(&mut self, _milliseconds: i64, _event: &str) {}
    fn ping(&mut self) -> bool {
        true
    }
    fn reconnect(&mut self) -> Result<()> {
        Ok(())
    }
    fn start_transaction(&mut self) -> Result<bool> {
        self.journals.push(Vec::new());
        self.state.in_transaction += 1;
        Ok(true)
    }
    fn commit_transaction(&mut self) -> Result<bool> {
        if self.state.in_transaction == 0 {
            return Ok(false);
        }
        let frame = self.journals.pop().unwrap_or_default();
        self.state.in_transaction -= 1;
        if !frame.is_empty() && self.state.in_transaction > 0 {
            if let Some(outer) = self.journals.last_mut() {
                outer.extend(frame);
            }
        }
        Ok(true)
    }
    fn rollback_transaction(&mut self) -> Result<bool> {
        if self.state.in_transaction == 0 {
            return Ok(false);
        }
        let frame = self.journals.pop().unwrap_or_default();
        for inverse in frame.into_iter().rev() {
            self.apply_inverse(inverse);
        }
        self.state.in_transaction -= 1;
        Ok(true)
    }

    fn create(&mut self, name: &str) -> Result<bool> {
        if !self.databases.contains_key(name) {
            self.databases.insert(name.to_owned(), IndexMap::new());
            self.journal(Inverse::DropDatabase(name.to_owned()));
        }
        Ok(true)
    }

    fn exists(&mut self, database: &str, collection: Option<&str>) -> Result<bool> {
        let Some(collection) = collection else {
            return Ok(self.databases.contains_key(database));
        };
        let key = format!(
            "{}.{}_{}",
            filter_key(database),
            self.state.namespace,
            filter_key(collection)
        );
        Ok(self.data.contains_key(&key) || {
            let k = format!(
                "{}.{}_{}",
                self.state.database,
                self.state.namespace,
                filter_key(collection)
            );
            self.data.contains_key(&k)
        })
    }

    fn list(&mut self) -> Result<Vec<String>> {
        Ok(self.databases.keys().cloned().collect())
    }

    fn delete(&mut self, name: &str) -> Result<bool> {
        self.databases.shift_remove(name);
        let prefix = format!("{name}.");
        self.data.retain(|k, _| !k.starts_with(&prefix));
        Ok(true)
    }

    fn create_collection(
        &mut self,
        name: &str,
        attributes: &[Document],
        indexes: &[Document],
    ) -> Result<bool> {
        let key = self.key(name);
        if self.data.contains_key(&key) {
            return Err(DatabaseError::duplicate("Collection already exists"));
        }
        let mut store = CollectionStore::default();
        for attribute in attributes {
            let attr_id = filter_key(&attribute.get_id());
            let mut meta = IndexMap::new();
            meta.insert("type".into(), attribute.get_attribute("type").clone());
            meta.insert("size".into(), attribute.get_attribute("size").clone());
            meta.insert("signed".into(), attribute.get_attribute("signed").clone());
            meta.insert("array".into(), attribute.get_attribute("array").clone());
            meta.insert(
                "required".into(),
                attribute.get_attribute("required").clone(),
            );
            store.attributes.insert(attr_id, meta);
        }
        for index in indexes {
            let index_id = filter_key(&index.get_id());
            let mut meta = IndexMap::new();
            meta.insert("type".into(), index.get_attribute("type").clone());
            meta.insert(
                "attributes".into(),
                index.get_attribute("attributes").clone(),
            );
            meta.insert("lengths".into(), index.get_attribute("lengths").clone());
            meta.insert("orders".into(), index.get_attribute("orders").clone());
            store.indexes.insert(index_id, meta);
        }
        self.data.insert(key.clone(), store);
        let database = self.state.database.clone();
        let slot = filter_key(name);
        if !database.is_empty() {
            self.databases
                .entry(database.clone())
                .or_default()
                .insert(slot.clone(), key.clone());
        }
        self.journal(Inverse::DropCollection {
            key,
            database,
            slot,
        });
        Ok(true)
    }

    fn delete_collection(&mut self, id: &str) -> Result<bool> {
        let key = self.key(id);
        let store = self.data.shift_remove(&key);
        let slot = filter_key(id);
        let database = self.state.database.clone();
        if let Some(db) = self.databases.get_mut(&database) {
            db.shift_remove(&slot);
        }
        if let Some(store) = store {
            self.journal(Inverse::RestoreCollection {
                key,
                store,
                database,
                slot,
            });
        }
        Ok(true)
    }

    fn analyze_collection(&mut self, _collection: &str) -> Result<bool> {
        Ok(false)
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
        let key = self.key(collection);
        let store = self
            .data
            .get_mut(&key)
            .ok_or_else(|| DatabaseError::not_found("Collection not found"))?;
        let id = filter_key(id);
        let mut meta = IndexMap::new();
        meta.insert("type".into(), AttrValue::from(type_));
        meta.insert("size".into(), AttrValue::from(size));
        meta.insert("signed".into(), AttrValue::from(signed));
        meta.insert("array".into(), AttrValue::from(array));
        meta.insert("required".into(), AttrValue::from(required));
        store.attributes.insert(id, meta);
        Ok(true)
    }

    fn create_attributes(&mut self, collection: &str, attributes: &[Document]) -> Result<bool> {
        for attribute in attributes {
            self.create_attribute(
                collection,
                &attribute.get_id(),
                attribute.get_attribute("type").as_str().unwrap_or(""),
                attribute.get_attribute("size").as_i64().unwrap_or(0),
                attribute.get_attribute("signed").as_bool().unwrap_or(true),
                attribute.get_attribute("array").as_bool().unwrap_or(false),
                attribute
                    .get_attribute("required")
                    .as_bool()
                    .unwrap_or(false),
            )?;
        }
        Ok(true)
    }

    fn update_attribute(
        &mut self,
        collection: &str,
        id: &str,
        type_: &str,
        size: i64,
        signed: bool,
        array: bool,
        new_key: Option<&str>,
        required: bool,
    ) -> Result<bool> {
        let mut id = filter_key(id);
        if let Some(new_key) = new_key {
            if !new_key.is_empty() && new_key != id {
                self.rename_attribute(collection, &id, new_key)?;
                id = filter_key(new_key);
            }
        }
        self.create_attribute(collection, &id, type_, size, signed, array, required)
    }

    fn delete_attribute(&mut self, collection: &str, id: &str) -> Result<bool> {
        let key = self.key(collection);
        if let Some(store) = self.data.get_mut(&key) {
            store.attributes.shift_remove(&filter_key(id));
        }
        Ok(true)
    }

    fn rename_attribute(&mut self, collection: &str, old: &str, new: &str) -> Result<bool> {
        let key = self.key(collection);
        let store = self
            .data
            .get_mut(&key)
            .ok_or_else(|| DatabaseError::not_found("Collection not found"))?;
        if let Some(meta) = store.attributes.shift_remove(&filter_key(old)) {
            store.attributes.insert(filter_key(new), meta);
        }
        for doc in store.documents.values_mut() {
            if let Some(v) = doc.shift_remove(&filter_key(old)) {
                doc.insert(filter_key(new), v);
            }
        }
        Ok(true)
    }

    fn create_relationship(
        &mut self,
        collection: &str,
        _related_collection: &str,
        _type_: &str,
        _two_way: bool,
        id: &str,
        _two_way_key: &str,
    ) -> Result<bool> {
        self.create_attribute(collection, id, VAR_RELATIONSHIP, 0, true, false, false)
    }

    fn update_relationship(
        &mut self,
        _collection: &str,
        _related_collection: &str,
        _type_: &str,
        _two_way: bool,
        _key: &str,
        _two_way_key: &str,
        _side: &str,
        _new_key: Option<&str>,
        _new_two_way_key: Option<&str>,
    ) -> Result<bool> {
        Ok(true)
    }

    fn delete_relationship(
        &mut self,
        collection: &str,
        _related_collection: &str,
        _type_: &str,
        _two_way: bool,
        key: &str,
        _two_way_key: &str,
        _side: &str,
    ) -> Result<bool> {
        self.delete_attribute(collection, key)
    }

    fn rename_index(&mut self, collection: &str, old: &str, new: &str) -> Result<bool> {
        let key = self.key(collection);
        let store = self
            .data
            .get_mut(&key)
            .ok_or_else(|| DatabaseError::not_found("Collection not found"))?;
        if let Some(meta) = store.indexes.shift_remove(&filter_key(old)) {
            store.indexes.insert(filter_key(new), meta);
        }
        Ok(true)
    }

    fn create_index(
        &mut self,
        collection: &str,
        id: &str,
        type_: &str,
        attributes: &[String],
        lengths: &[i64],
        orders: &[String],
        _index_attribute_types: &[String],
        _collation: &[String],
        _ttl: i64,
    ) -> Result<bool> {
        let key = self.key(collection);
        let store = self
            .data
            .get_mut(&key)
            .ok_or_else(|| DatabaseError::not_found("Collection not found"))?;
        let mut meta = IndexMap::new();
        meta.insert("type".into(), AttrValue::from(type_));
        meta.insert("attributes".into(), AttrValue::from(attributes.to_vec()));
        meta.insert(
            "lengths".into(),
            AttrValue::list_from_iter(lengths.iter().copied().map(AttrValue::from)),
        );
        meta.insert("orders".into(), AttrValue::from(orders.to_vec()));
        store.indexes.insert(filter_key(id), meta);
        Ok(true)
    }

    fn delete_index(&mut self, collection: &str, id: &str) -> Result<bool> {
        let key = self.key(collection);
        if let Some(store) = self.data.get_mut(&key) {
            store.indexes.shift_remove(&filter_key(id));
        }
        Ok(true)
    }

    fn get_document(
        &mut self,
        collection: &Document,
        id: &str,
        queries: &[Query],
        _for_update: bool,
    ) -> Result<Document> {
        let key = self.key(&collection.get_id());
        let Some(store) = self.data.get(&key) else {
            return Ok(Document::new());
        };
        let doc_key = self.document_key(id, None);
        let Some(row) = store.documents.get(&doc_key).cloned() else {
            if self.state.shared_tables && collection.get_id() == METADATA {
                let lower = id.to_ascii_lowercase();
                if let Some(row) = store.documents.values().find(|candidate| {
                    candidate
                        .get("_uid")
                        .and_then(AttrValue::as_str)
                        .is_some_and(|s| s.eq_ignore_ascii_case(&lower))
                        && candidate.get("_tenant").map_or(true, AttrValue::is_null)
                }) {
                    let selections = Self::extract_selections(queries);
                    let mut map = self.row_to_document(row, &selections, Some(&key));
                    map.insert("$collection".into(), AttrValue::from(collection.get_id()));
                    return Document::from_map(map);
                }
            }
            return Ok(Document::new());
        };
        let selections = Self::extract_selections(queries);
        let mut map = self.row_to_document(&row, &selections, Some(&key));
        map.insert("$collection".into(), AttrValue::from(collection.get_id()));
        Document::from_map(map)
    }

    fn create_document(
        &mut self,
        collection: &Document,
        mut document: Document,
    ) -> Result<Document> {
        let key = self.key(&collection.get_id());
        if !self.data.contains_key(&key) {
            return Err(DatabaseError::not_found("Collection not found"));
        }
        let doc_key = self.document_key(&document.get_id(), document.get_tenant().as_ref());
        if self
            .data
            .get(&key)
            .is_some_and(|s| s.documents.contains_key(&doc_key))
        {
            if self.state.skip_duplicates {
                if let Some(existing) = self.data.get(&key).and_then(|s| s.documents.get(&doc_key))
                {
                    if let Some(id) = existing.get("_id") {
                        document.set_attribute("$sequence", AttrValue::from(value_to_string(id)));
                    }
                }
                return Ok(document);
            }
            return Err(DatabaseError::duplicate("Document already exists"));
        }
        let sequence_before = self.data.get(&key).map_or(0, |s| s.sequence);
        let sequence = {
            let store = self.data.get_mut(&key).expect("collection");
            if document.get_sequence().map_or(true, |s| s.is_empty()) {
                store.sequence += 1;
                store.sequence
            } else {
                let seq = document
                    .get_sequence()
                    .and_then(|s| s.parse::<i64>().ok())
                    .unwrap_or(0);
                if seq > store.sequence {
                    store.sequence = seq;
                }
                seq
            }
        };
        let mut row = self.document_to_row(&document);
        row.insert("_id".into(), AttrValue::from(sequence));
        self.data
            .get_mut(&key)
            .expect("collection")
            .documents
            .insert(doc_key.clone(), row);
        self.journal(Inverse::RestoreDocument {
            key,
            doc_key,
            row: None,
            sequence: sequence_before,
        });
        document.set_attribute("$sequence", AttrValue::from(sequence.to_string()));
        Ok(document)
    }

    fn create_documents(
        &mut self,
        collection: &Document,
        documents: Vec<Document>,
    ) -> Result<Vec<Document>> {
        let mut created = Vec::new();
        for document in documents {
            created.push(self.create_document(collection, document)?);
        }
        Ok(created)
    }

    fn update_document(
        &mut self,
        collection: &Document,
        id: &str,
        mut document: Document,
        _skip_permissions: bool,
    ) -> Result<Document> {
        let key = self.key(&collection.get_id());
        let doc_key = self.document_key(id, None);
        let existing = self
            .data
            .get(&key)
            .and_then(|s| s.documents.get(&doc_key).cloned())
            .ok_or_else(|| DatabaseError::not_found("Document not found"))?;
        let resolved = Self::apply_operators(document.get_attributes(), &existing);
        document.set_attributes(resolved);
        let mut update = self.document_to_row(&document);
        for (k, v) in existing {
            update.entry(k).or_insert(v);
        }
        let sequence_before = self.data.get(&key).map_or(0, |s| s.sequence);
        let previous = self
            .data
            .get_mut(&key)
            .and_then(|s| s.documents.insert(doc_key.clone(), update));
        self.journal(Inverse::RestoreDocument {
            key,
            doc_key,
            row: previous,
            sequence: sequence_before,
        });
        Ok(document)
    }

    fn update_documents(
        &mut self,
        collection: &Document,
        updates: &Document,
        documents: &[Document],
    ) -> Result<i64> {
        let mut n = 0i64;
        for document in documents {
            let mut merged = document.clone();
            for (k, v) in updates.as_map() {
                merged.set_attribute(k.clone(), v.clone());
            }
            self.update_document(collection, &document.get_id(), merged, false)?;
            n += 1;
        }
        Ok(n)
    }

    fn upsert_documents(
        &mut self,
        collection: &Document,
        documents: Vec<Document>,
    ) -> Result<Vec<Document>> {
        let mut out = Vec::new();
        for document in documents {
            let id = document.get_id();
            let existing = self.get_document(collection, &id, &[], false)?;
            if existing.is_empty() {
                out.push(self.create_document(collection, document)?);
            } else {
                out.push(self.update_document(collection, &id, document, false)?);
            }
        }
        Ok(out)
    }

    fn get_sequences(&mut self, collection: &str, documents: &[Document]) -> Result<Vec<String>> {
        let key = self.key(collection);
        let store = match self.data.get(&key) {
            Some(s) => s,
            None => return Ok(Vec::new()),
        };
        Ok(documents
            .iter()
            .map(|d| {
                let dk = self.document_key(&d.get_id(), d.get_tenant().as_ref());
                store
                    .documents
                    .get(&dk)
                    .and_then(|r| r.get("_id"))
                    .map(value_to_string)
                    .unwrap_or_default()
            })
            .collect())
    }

    fn delete_document(&mut self, collection: &str, id: &str) -> Result<bool> {
        let key = self.key(collection);
        let doc_key = self.document_key(id, None);
        let sequence = self.data.get(&key).map_or(0, |s| s.sequence);
        let previous = self
            .data
            .get_mut(&key)
            .and_then(|s| s.documents.shift_remove(&doc_key));
        self.journal(Inverse::RestoreDocument {
            key,
            doc_key,
            row: previous,
            sequence,
        });
        Ok(true)
    }

    fn delete_documents(
        &mut self,
        collection: &str,
        sequences: &[String],
        _permission_ids: &[String],
    ) -> Result<i64> {
        let key = self.key(collection);
        let Some(store) = self.data.get_mut(&key) else {
            return Ok(0);
        };
        let mut n = 0i64;
        store.documents.retain(|_, row| {
            let seq = row.get("_id").map(value_to_string).unwrap_or_default();
            if sequences.contains(&seq) {
                n += 1;
                false
            } else {
                true
            }
        });
        Ok(n)
    }

    fn find(
        &mut self,
        collection: &Document,
        queries: &[Query],
        limit: Option<i64>,
        offset: Option<i64>,
        order_attributes: &[String],
        order_types: &[String],
        cursor: Option<&Document>,
        cursor_direction: &str,
        for_permission: &str,
    ) -> Result<Vec<Document>> {
        let key = self.key(&collection.get_id());
        if !self.data.contains_key(&key) {
            return Err(DatabaseError::not_found("Collection not found"));
        }
        let mut rows = self.fused_filter(&key, &collection.get_id(), queries, for_permission)?;
        rows.sort_by(|a, b| {
            for (attr, ty) in order_attributes.iter().zip(
                order_types
                    .iter()
                    .chain(std::iter::repeat(&ORDER_ASC.to_string())),
            ) {
                if ty == ORDER_RANDOM {
                    return Ordering::Equal;
                }
                let av = resolve_attribute(a, attr);
                let bv = resolve_attribute(b, attr);
                let ord = cmp_attr(&av, &bv).unwrap_or(Ordering::Equal);
                let ord = if ty == ORDER_DESC { ord.reverse() } else { ord };
                if ord != Ordering::Equal {
                    return ord;
                }
            }
            Ordering::Equal
        });
        if let Some(cursor) = cursor {
            let cursor_id = cursor.get_id();
            if let Some(pos) = rows
                .iter()
                .position(|r| r.get("_uid").and_then(AttrValue::as_str) == Some(cursor_id.as_str()))
            {
                if cursor_direction == CURSOR_AFTER {
                    rows = rows.split_off(pos.saturating_add(1));
                } else {
                    rows.truncate(pos);
                }
            }
        }
        if let Some(offset) = offset {
            let o = offset.max(0) as usize;
            if o < rows.len() {
                rows = rows.split_off(o);
            } else {
                rows.clear();
            }
        }
        if let Some(limit) = limit {
            rows.truncate(limit.max(0) as usize);
        }
        if cursor_direction == CURSOR_BEFORE {
            rows.reverse();
        }
        let selections = Self::extract_selections(queries);
        let mut results = Vec::new();
        for row in rows {
            let mut map = self.row_to_document(&row, &selections, Some(&key));
            map.insert("$collection".into(), AttrValue::from(collection.get_id()));
            results.push(Document::from_map(map)?);
        }
        Ok(results)
    }

    fn sum(
        &mut self,
        collection: &Document,
        attribute: &str,
        queries: &[Query],
        max: Option<i64>,
    ) -> Result<f64> {
        let key = self.key(&collection.get_id());
        let mut rows = self.fused_filter(&key, &collection.get_id(), queries, PERMISSION_READ)?;
        if let Some(max) = max {
            rows.truncate(max.max(0) as usize);
        }
        let column = filter_key(attribute);
        let mut sum = 0.0;
        for row in rows {
            if let Some(v) = row.get(&column).and_then(AttrValue::as_f64) {
                sum += v;
            }
        }
        Ok(sum)
    }

    fn count(&mut self, collection: &Document, queries: &[Query], max: Option<i64>) -> Result<i64> {
        let key = self.key(&collection.get_id());
        let mut rows = self.fused_filter(&key, &collection.get_id(), queries, PERMISSION_READ)?;
        if let Some(max) = max {
            rows.truncate(max.max(0) as usize);
        }
        Ok(rows.len() as i64)
    }

    fn get_size_of_collection(&mut self, collection: &str) -> Result<i64> {
        let key = self.key(collection);
        Ok(self.data.get(&key).map_or(0, |s| s.documents.len() as i64))
    }
    fn get_size_of_collection_on_disk(&mut self, collection: &str) -> Result<i64> {
        self.get_size_of_collection(collection)
    }

    fn increase_document_attribute(
        &mut self,
        collection: &str,
        id: &str,
        attribute: &str,
        value: f64,
        updated_at: &str,
        min: Option<f64>,
        max: Option<f64>,
    ) -> Result<bool> {
        let key = self.key(collection);
        let doc_key = self.document_key(id, None);
        let store = self
            .data
            .get_mut(&key)
            .ok_or_else(|| DatabaseError::not_found("Document not found"))?;
        let row = store
            .documents
            .get_mut(&doc_key)
            .ok_or_else(|| DatabaseError::not_found("Document not found"))?;
        let column = filter_key(attribute);
        let current = row.get(&column).and_then(AttrValue::as_f64).unwrap_or(0.0);
        if min.is_some_and(|m| current < m) || max.is_some_and(|m| current > m) {
            return Ok(true);
        }
        let next = current + value;
        row.insert(
            column,
            if next.fract() == 0.0 {
                AttrValue::from(next as i64)
            } else {
                AttrValue::from(next)
            },
        );
        row.insert("_updatedAt".into(), AttrValue::from(updated_at));
        Ok(true)
    }

    fn get_limit_for_string(&self) -> i64 {
        4_294_967_295
    }
    fn get_limit_for_int(&self) -> i64 {
        4_294_967_295
    }
    fn get_limit_for_big_int(&self) -> i64 {
        MAX_BIG_INT
    }
    fn get_limit_for_attributes(&self) -> i64 {
        1017
    }
    fn get_limit_for_indexes(&self) -> i64 {
        64
    }
    fn get_max_index_length(&self) -> i64 {
        1024
    }
    fn get_max_varchar_length(&self) -> i64 {
        16381
    }
    fn get_max_uid_length(&self) -> i64 {
        255
    }
    fn get_min_date_time(&self) -> NaiveDateTime {
        min_datetime()
    }
    fn get_id_attribute_type(&self) -> &'static str {
        VAR_INTEGER
    }
    fn get_support_for_schemas(&self) -> bool {
        true
    }
    fn get_support_for_attributes(&self) -> bool {
        self.support_for_attributes
    }
    fn set_support_for_attributes(&mut self, support: bool) -> bool {
        self.support_for_attributes = support;
        support
    }
    fn get_support_for_schema_attributes(&self) -> bool {
        false
    }
    fn get_support_for_schema_indexes(&self) -> bool {
        false
    }
    fn get_support_for_index(&self) -> bool {
        true
    }
    fn get_support_for_index_array(&self) -> bool {
        false
    }
    fn get_support_for_cast_index_array(&self) -> bool {
        false
    }
    fn get_support_for_unique_index(&self) -> bool {
        true
    }
    fn get_support_for_fulltext_index(&self) -> bool {
        true
    }
    fn get_support_for_fulltext_wildcard_index(&self) -> bool {
        false
    }
    fn get_support_for_casting(&self) -> bool {
        true
    }
    fn get_support_for_query_contains(&self) -> bool {
        true
    }
    fn get_support_for_timeouts(&self) -> bool {
        false
    }
    fn get_support_for_relationships(&self) -> bool {
        true
    }
    fn get_support_for_update_lock(&self) -> bool {
        false
    }
    fn get_support_for_batch_operations(&self) -> bool {
        true
    }
    fn get_support_for_attribute_resizing(&self) -> bool {
        true
    }
    fn get_support_for_get_connection_id(&self) -> bool {
        false
    }
    fn get_support_for_upserts(&self) -> bool {
        false
    }
    fn get_support_for_upsert_on_unique_index(&self) -> bool {
        false
    }
    fn get_support_for_vectors(&self) -> bool {
        false
    }
    fn get_support_for_cache_skip_on_failure(&self) -> bool {
        false
    }
    fn get_support_for_reconnection(&self) -> bool {
        false
    }
    fn get_support_for_hostname(&self) -> bool {
        false
    }
    fn get_support_for_batch_create_attributes(&self) -> bool {
        true
    }
    fn get_support_for_spatial_attributes(&self) -> bool {
        false
    }
    fn get_support_for_object(&self) -> bool {
        true
    }
    fn get_support_for_object_indexes(&self) -> bool {
        true
    }
    fn get_support_for_spatial_index_null(&self) -> bool {
        false
    }
    fn get_support_for_operators(&self) -> bool {
        true
    }
    fn get_support_for_optional_spatial_attribute_with_existing_rows(&self) -> bool {
        false
    }
    fn get_support_for_spatial_index_order(&self) -> bool {
        false
    }
    fn get_support_for_spatial_axis_order(&self) -> bool {
        false
    }
    fn get_support_for_boundary_inclusive_contains(&self) -> bool {
        false
    }
    fn get_support_for_distance_between_multi_dimension_geometry_in_meters(&self) -> bool {
        false
    }
    fn get_support_for_multiple_fulltext_indexes(&self) -> bool {
        false
    }
    fn get_support_for_identical_indexes(&self) -> bool {
        false
    }
    fn get_support_for_order_random(&self) -> bool {
        true
    }
    fn get_support_for_internal_casting(&self) -> bool {
        false
    }
    fn get_support_for_utc_casting(&self) -> bool {
        false
    }
    fn get_support_for_integer_booleans(&self) -> bool {
        false
    }
    fn get_support_for_alter_locks(&self) -> bool {
        false
    }
    fn get_support_non_utf_characters(&self) -> bool {
        true
    }
    fn get_support_for_trigram_index(&self) -> bool {
        false
    }
    fn get_support_for_pcre_regex(&self) -> bool {
        true
    }
    fn get_support_for_posix_regex(&self) -> bool {
        false
    }
    fn get_support_for_transaction_retries(&self) -> bool {
        true
    }
    fn get_support_for_nested_transactions(&self) -> bool {
        true
    }
    fn get_count_of_attributes(&self, collection: &Document) -> i64 {
        let n = match collection.get_attribute("attributes") {
            AttrValue::Array(a) => a.len() as i64,
            _ => 0,
        };
        n + self.get_count_of_default_attributes()
    }
    fn get_count_of_indexes(&self, collection: &Document) -> i64 {
        let n = match collection.get_attribute("indexes") {
            AttrValue::Array(a) => a.len() as i64,
            _ => 0,
        };
        n + self.get_count_of_default_indexes()
    }
    fn get_count_of_default_attributes(&self) -> i64 {
        INTERNAL_ATTRIBUTES.len() as i64
    }
    fn get_count_of_default_indexes(&self) -> i64 {
        INTERNAL_INDEXES.len() as i64
    }
    fn get_document_size_limit(&self) -> i64 {
        0
    }
    fn get_attribute_width(&self, _collection: &Document) -> i64 {
        0
    }
    fn get_keywords(&self) -> Vec<String> {
        Vec::new()
    }
    fn get_connection_id(&self) -> String {
        "0".into()
    }
    fn get_internal_indexes_keys(&self) -> Vec<String> {
        Vec::new()
    }
    fn get_schema_attributes(&mut self, _collection: &str) -> Result<Vec<Document>> {
        Ok(Vec::new())
    }
    fn get_schema_indexes(&mut self, _collection: &str) -> Result<Vec<Document>> {
        Ok(Vec::new())
    }
    fn get_tenant_query(&self, _collection: &str, _alias: &str) -> String {
        String::new()
    }
    fn decode_point(&self, _wkb: &str) -> Result<AttrValue> {
        Err(DatabaseError::database(
            "Spatial types are not implemented in the Memory adapter",
        ))
    }
    fn decode_linestring(&self, _wkb: &str) -> Result<AttrValue> {
        Err(DatabaseError::database(
            "Spatial types are not implemented in the Memory adapter",
        ))
    }
    fn decode_polygon(&self, _wkb: &str) -> Result<AttrValue> {
        Err(DatabaseError::database(
            "Spatial types are not implemented in the Memory adapter",
        ))
    }
    fn get_driver(&self) -> AttrValue {
        AttrValue::from("memory")
    }
}
