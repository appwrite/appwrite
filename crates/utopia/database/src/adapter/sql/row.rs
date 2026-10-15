//! The rows a read returns, as documents name their values.
//!
//! PHP: `SQL::remapRow()`.

use php_std::types::KeyRef;

use crate::document;
use crate::query::DEFAULT_ALIAS;
use crate::storage;
use crate::value::{Array, ArrayKey, Value};

/// The prefix of the columns an emulated full outer join is ordered by,
/// dropped from every row (`SQL::FOJ_ORDER_ALIAS_PREFIX`).
pub(crate) const FOJ_ORDER_ALIAS_PREFIX: &str = "$foj_ord_";

fn key(name: &str) -> ArrayKey {
    ArrayKey::from(name)
}

/// `json_decode($value, true)` of a permissions column: a string decoded
/// (`null` when it is not JSON), anything else as `'[]'`.
fn decode_permissions(value: &Value) -> Value {
    let json: &[u8] = match value {
        Value::Str(s) => s.as_bytes(),
        _ => b"[]",
    };
    php_std::json::decode_ext(json, Some(true), 512, php_std::json::Flags::NONE).unwrap_or(Value::Null)
}

/// `remapRow()`: integer keys and full outer join order columns dropped,
/// `alias.column` keys renamed `alias.$attribute` (and copied bare for the
/// main alias), the internal columns renamed to their attributes, the
/// permissions decoded and the distance read as a float.
pub(crate) fn remap_row(row: &mut Array) {
    let keys: Vec<ArrayKey> = row.keys().map(KeyRef::to_owned).collect();
    for k in keys {
        let name = match &k {
            ArrayKey::Int(_) => {
                row.remove(&k);
                continue;
            }
            ArrayKey::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
        };
        if name.starts_with(FOJ_ORDER_ALIAS_PREFIX) {
            row.remove(&k);
            continue;
        }
        let Some(separator) = name.rfind('.') else {
            continue;
        };
        let prefix = &name[..separator];
        let bare = name[separator + 1..].trim_matches(['`', '"']).to_owned();
        let public = storage::attribute(&bare).to_owned();
        let dotted = format!("{prefix}.{public}");
        let current = row.get(&k).cloned().unwrap_or(Value::Null);
        if prefix == DEFAULT_ALIAS && !bare.is_empty() && !row.contains_key(&key(&bare)) {
            row.set(key(&bare), current.clone());
        }
        let mut value = current;
        if !matches!(value, Value::Null) && (bare == storage::PERMISSIONS || public == document::PERMISSIONS) {
            value = decode_permissions(&value);
        }
        if !row.contains_key(&key(&dotted)) || name == dotted {
            row.set(key(&dotted), value);
        }
        if name != dotted {
            row.remove(&k);
        }
    }
    for (public, internal) in storage::ATTRIBUTE_MAP {
        if internal == storage::PERMISSIONS || internal == storage::DISTANCE {
            continue;
        }
        if let Some(value) = row.remove(&key(internal)) {
            row.set(key(public), value);
        }
    }
    if let Some(permissions) = row.remove(&key(storage::PERMISSIONS)) {
        row.set(key(document::PERMISSIONS), decode_permissions(&permissions));
    }
    if let Some(distance) = row.remove(&key(storage::DISTANCE)) {
        let numeric = match &distance {
            Value::Int(_) | Value::Float(_) => true,
            Value::Str(s) => php_std::value::numeric_bytes_ex(s.as_bytes()).is_some(),
            _ => false,
        };
        let value = if numeric { Value::Float(distance.to_double()) } else { Value::Null };
        row.set(key(document::DISTANCE), value);
    }
}
