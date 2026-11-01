//! The rows a read returns, as documents name their values.
//!
//! PHP: `SQL::remapRow()`.

use std::collections::{HashMap, VecDeque};

use php_std::types::KeyRef;

use crate::document;
use crate::query::DEFAULT_ALIAS;
use crate::storage;
use crate::value::{Array, ArrayKey, Value};

use super::{Engine, Sql};

/// The prefix of the columns an emulated full outer join is ordered by,
/// dropped from every row (`SQL::FOJ_ORDER_ALIAS_PREFIX`).
pub(crate) const FOJ_ORDER_ALIAS_PREFIX: &str = "$foj_ord_";

/// The prefix of the label a joined column is read under when its
/// `alias.column` label is longer than the engine keeps; a join alias cannot
/// hold `$` (`SQL::JOIN_LABEL_PREFIX`).
const JOIN_LABEL_PREFIX: &str = "$join_";

/// How many digest labels an adapter remembers (`SQL::JOIN_LABELS_KEPT`).
const JOIN_LABELS_KEPT: usize = 1024;

/// The `alias.column` label each digest label stands for, oldest first
/// (`SQL::$joinLabels`). Join aliases are the caller's, so only the newest
/// [`JOIN_LABELS_KEPT`] are kept.
#[derive(Debug, Default)]
pub(crate) struct JoinLabels {
    labels: HashMap<String, String>,
    order: VecDeque<String>,
}

impl JoinLabels {
    fn remember(&mut self, digest: String, label: String) {
        if self.labels.remove(&digest).is_some() {
            self.order.retain(|d| *d != digest);
        }
        self.order.push_back(digest.clone());
        self.labels.insert(digest, label);
        if self.labels.len() > JOIN_LABELS_KEPT
            && let Some(oldest) = self.order.pop_front()
        {
            self.labels.remove(&oldest);
        }
    }

    fn get(&self, digest: &str) -> Option<&str> {
        self.labels.get(digest).map(String::as_str)
    }
}

impl Sql {
    /// The longest column label the engine keeps (`MAX_LABEL_LENGTH`):
    /// PostgreSQL's identifier limit, 256 characters elsewhere.
    fn max_label_length(&self) -> usize {
        match self.engine {
            Engine::Postgres => super::tables::MAX_IDENTIFIER_NAME,
            _ => 256,
        }
    }

    /// The label a joined column is read under (`joinLabel()`):
    /// `alias.column`, or a digest of it when that is longer than the engine
    /// keeps a label, so the engine cannot cut it short.
    pub(crate) fn join_label(&self, alias: &str, column: &str) -> String {
        let label = format!("{alias}.{column}");
        if label.len() <= self.max_label_length() {
            return label;
        }
        let digest = format!("{JOIN_LABEL_PREFIX}{}", crate::query::md5_hex(label.as_bytes()));
        self.engine_state().join_labels.remember(digest.clone(), label);
        digest
    }

    /// [`remap_row`] with the digest labels this adapter handed out.
    pub(crate) fn remap_row(&self, row: &mut Array) {
        remap_row(row, &self.engine_state().join_labels);
    }
}

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
pub(crate) fn remap_row(row: &mut Array, labels: &JoinLabels) {
    let keys: Vec<ArrayKey> = row.keys().map(KeyRef::to_owned).collect();
    for mut k in keys {
        let mut name = match &k {
            ArrayKey::Int(_) => {
                row.remove(&k);
                continue;
            }
            ArrayKey::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
        };
        if let Some(label) = labels.get(&name) {
            let value = row.remove(&k).unwrap_or(Value::Null);
            name = label.to_owned();
            k = key(&name);
            row.set(k.clone(), value);
        }
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

#[cfg(test)]
mod tests {
    use super::*;

    // PHP 8.3.10: JoinLabelRetentionTest (an anonymous SQLite subclass and
    // reflection, so no compat case carries it).
    #[test]
    fn join_labels_keep_only_the_newest() {
        let mut labels = JoinLabels::default();
        for read in 0..1100 {
            labels.remember(format!("$join_{read}"), format!("customer_{read}.name"));
        }
        assert_eq!(labels.labels.len(), JOIN_LABELS_KEPT);
        assert_eq!(labels.get("$join_0"), None);
        assert_eq!(labels.get("$join_1099"), Some("customer_1099.name"));

        labels.remember("$join_76".to_owned(), "customer_76.name".to_owned());
        labels.remember("$join_new".to_owned(), "new.name".to_owned());
        assert_eq!(labels.get("$join_76"), Some("customer_76.name"), "a label read again is the newest");
        assert_eq!(labels.get("$join_77"), None);
    }

    #[test]
    fn a_digest_label_reads_back_as_its_alias_and_column() {
        let mut labels = JoinLabels::default();
        labels.remember("$join_abc".to_owned(), "customer.name".to_owned());
        let mut row = Array::new();
        row.set(key("$join_abc"), Value::from("Alice"));
        remap_row(&mut row, &labels);
        assert_eq!(row.get(&key("customer.name")), Some(&Value::from("Alice")));
        assert_eq!(row.get(&key("$join_abc")), None);
    }
}
