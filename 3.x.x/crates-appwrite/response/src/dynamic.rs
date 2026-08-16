//! Document -> model JSON filtering.
//!
//! Rust port of the filtering behavior of `Appwrite\Utopia\Response::output()`
//! / `Response::dynamic()` for the Users API model subset registered in
//! `model.rs`.

use serde_json::{Map, Value};

use crate::model::{list_spec, spec, ListSpec, ModelSpec, Rule, RuleType};
use crate::{MODEL_ERROR, MODEL_NONE, MODEL_PREFERENCES};

/// Filter a raw document (or list of documents) down to the public fields of
/// `model`. Rust port of `Response::dynamic()` / `Response::output()`.
///
/// * [`MODEL_NONE`] always returns an empty object.
/// * [`MODEL_PREFERENCES`] and [`MODEL_ERROR`] are `Any`-typed / already-shaped
///   payloads in PHP, so they pass through unfiltered (defaulting to `{}` for
///   non-object input).
/// * A registered list model (e.g. `userList`) expects `doc` to be either a
///   JSON array of raw documents, or an object with a `"total"` field and a
///   `"documents"` (or the list's own key) array; the result is
///   `{ "total": <n>, "<key>": [ ...filtered items... ] }`.
/// * A registered scalar model (e.g. `user`) filters `doc`'s object fields
///   down to the model's rules, filling in PHP-equivalent defaults for
///   missing fields and recursing into nested models (e.g. `User::targets`).
/// * Unregistered model names pass `doc` through unchanged (best effort).
#[must_use]
pub fn dynamic(doc: &Value, model: &str) -> Value {
    match model {
        MODEL_NONE => Value::Object(Map::new()),
        MODEL_PREFERENCES | MODEL_ERROR => {
            if doc.is_object() {
                doc.clone()
            } else {
                Value::Object(Map::new())
            }
        }
        _ => {
            if let Some(list) = list_spec(model) {
                build_list(doc, list)
            } else if let Some(spec) = spec(model) {
                filter_document(doc, spec)
            } else {
                doc.clone()
            }
        }
    }
}

fn build_list(doc: &Value, list: &ListSpec) -> Value {
    let (total, items): (i64, Vec<Value>) = match doc {
        Value::Array(items) => (items.len() as i64, items.clone()),
        Value::Object(map) => {
            let items = map
                .get(list.key)
                .or_else(|| map.get("documents"))
                .and_then(Value::as_array)
                .cloned()
                .unwrap_or_default();
            let total = map
                .get("total")
                .and_then(Value::as_i64)
                .unwrap_or(items.len() as i64);
            (total, items)
        }
        _ => (0, Vec::new()),
    };

    let filtered: Vec<Value> = items
        .iter()
        .map(|item| dynamic(item, list.item_model))
        .collect();

    let mut out = Map::new();
    out.insert("total".to_string(), Value::from(total));
    out.insert(list.key.to_string(), Value::Array(filtered));
    Value::Object(out)
}

fn filter_document(doc: &Value, spec: &ModelSpec) -> Value {
    let empty = Map::new();
    let source = doc.as_object().unwrap_or(&empty);

    let mut out = Map::new();
    for rule in spec.rules {
        let value = source.get(rule.name);
        out.insert(rule.name.to_string(), filter_field(value, rule));
    }
    Value::Object(out)
}

fn filter_field(value: Option<&Value>, rule: &Rule) -> Value {
    match rule.kind {
        RuleType::Model(sub_model) if rule.array => {
            let items = value.and_then(Value::as_array).cloned().unwrap_or_default();
            Value::Array(items.iter().map(|item| dynamic(item, sub_model)).collect())
        }
        RuleType::Model(sub_model) => match value {
            Some(v) if !v.is_null() => dynamic(v, sub_model),
            _ => dynamic(&Value::Object(Map::new()), sub_model),
        },
        _ if rule.array => {
            let items = value.and_then(Value::as_array).cloned().unwrap_or_default();
            Value::Array(items)
        }
        _ => value.cloned().unwrap_or_else(|| default_scalar(rule.kind)),
    }
}

fn default_scalar(kind: RuleType) -> Value {
    match kind {
        RuleType::String | RuleType::Datetime => Value::String(String::new()),
        RuleType::Boolean => Value::Bool(false),
        RuleType::Integer => Value::from(0),
        RuleType::Json | RuleType::Model(_) => Value::Object(Map::new()),
    }
}
