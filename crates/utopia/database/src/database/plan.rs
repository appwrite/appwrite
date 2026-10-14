//! Attribute plans: the settings `decode()`, `casting()` and `encode()` read
//! from each attribute of a collection, read once per collection document
//! instead of once per document they process.
//!
//! Area: schema.

use std::collections::HashMap;
use std::sync::{Arc, LazyLock, RwLock};

use super::Database;
use super::filters::collection_attribute_refs;
use crate::document::Document;
use crate::validator::{index, index_or};
use crate::value::{Array, Value, to_text};

/// One attribute's settings.
pub(crate) struct Attribute {
    /// `$attribute['$id']`, as a string.
    pub key: String,
    /// `$attribute['type']`, as a string.
    pub kind: String,
    /// Whether `type` is a string naming a type `casting()` converts.
    pub typed: bool,
    /// Whether `type` is the relationship type.
    pub relationship: bool,
    /// `$attribute['array']`, truthy.
    pub array: bool,
    /// `$attribute['signed'] ?? true`, truthy.
    pub signed: bool,
    /// `$attribute['default']`.
    pub default: Value,
    /// `$attribute['filters']`, as strings (none when not a list).
    pub filters: Vec<String>,
    /// Whether `filters` is not a list, or an empty one.
    pub unfiltered: bool,
}

/// The types `casting()` converts.
pub(crate) const TYPED: [&str; 5] =
    [Database::VAR_ID, Database::VAR_BOOLEAN, Database::VAR_INTEGER, Database::VAR_BIGINT, Database::VAR_FLOAT];

impl Attribute {
    fn of(attribute: &Value) -> Attribute {
        let kind = index(attribute, "type");
        let filters = index(attribute, "filters");
        Attribute {
            key: to_text(index(attribute, "$id")),
            kind: to_text(kind),
            typed: matches!(kind, Value::Str(k) if TYPED.iter().any(|t| t.as_bytes() == k.as_bytes())),
            relationship: matches!(kind, Value::Str(k) if k.as_bytes() == Database::VAR_RELATIONSHIP.as_bytes()),
            array: index(attribute, "array").truthy(),
            signed: index_or(attribute, "signed", &Value::Bool(true)).truthy(),
            default: index(attribute, "default").clone(),
            filters: match filters {
                Value::Array(a) => a.values().map(to_text).collect(),
                _ => Vec::new(),
            },
            unfiltered: match filters {
                Value::Array(a) => a.is_empty(),
                _ => true,
            },
        }
    }
}

/// The plans of the collections seen, by the identity of their attribute
/// lists. Each entry keeps its list alive, so an identity is never reused
/// for another list while its plan is here.
type Plans = HashMap<usize, (Array, Arc<[Attribute]>)>;

static PLANS: LazyLock<RwLock<Plans>> = LazyLock::new(|| RwLock::new(HashMap::new()));

/// Past this many collections, the plans are dropped and made again.
const PLANS_KEPT: usize = 4096;

/// The settings of `collection`'s attributes, in order.
pub(crate) fn collection(collection: &Document) -> Arc<[Attribute]> {
    let list = match collection.get("attributes") {
        Some(Value::Array(a)) => a,
        _ => return Arc::from(Vec::new()),
    };
    let Some(id) = list.id() else {
        return collection_attribute_refs(collection).map(Attribute::of).collect();
    };
    if let Some((_, plan)) = PLANS.read().unwrap_or_else(|e| e.into_inner()).get(&id) {
        return plan.clone();
    }
    let plan: Arc<[Attribute]> = list.values().map(Attribute::of).collect();
    let mut plans = PLANS.write().unwrap_or_else(|e| e.into_inner());
    if plans.len() >= PLANS_KEPT {
        plans.clear();
    }
    plans.insert(id, (list.clone(), plan.clone()));
    plan
}

/// The settings of the internal attributes (`$id`, `$sequence`, ...), with
/// `$tenant` when tables are shared.
pub(crate) fn internal(shared_tables: bool) -> &'static [Attribute] {
    static SHARED: LazyLock<Vec<Attribute>> = LazyLock::new(|| plan_of(true));
    static UNSHARED: LazyLock<Vec<Attribute>> = LazyLock::new(|| plan_of(false));
    fn plan_of(shared: bool) -> Vec<Attribute> {
        Database::internal_attributes_const()
            .into_iter()
            .filter(|a| shared || a.id() != "$tenant")
            .map(|a| Attribute::of(&Value::from(a)))
            .collect()
    }
    if shared_tables { &SHARED } else { &UNSHARED }
}
