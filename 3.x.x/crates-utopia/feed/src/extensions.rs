use serde_json::{Map, Value};
use utopia_cloudevents::ExtensionValue;

const ATTRIBUTES: &[&str] = &[
    "specversion",
    "type",
    "source",
    "id",
    "subject",
    "time",
    "datacontenttype",
    "dataschema",
    "data",
];

/// PHP `Utopia\Feed\Extensions`.
#[derive(Debug)]
pub struct Extensions;

impl Extensions {
    /// PHP `Extensions::filter()`.
    #[must_use]
    pub fn filter(candidates: &Map<String, Value>) -> Map<String, Value> {
        let mut extensions = Map::new();
        for (name, value) in candidates {
            if ATTRIBUTES.contains(&name.as_str()) {
                continue;
            }
            if !is_extension_name(name) {
                continue;
            }
            match value {
                Value::Bool(_) | Value::String(_) => {
                    extensions.insert(name.clone(), value.clone());
                }
                Value::Number(n) if n.is_i64() => {
                    extensions.insert(name.clone(), value.clone());
                }
                _ => {}
            }
        }
        extensions
    }

    #[must_use]
    pub fn filter_value(candidates: &Value) -> Map<String, Value> {
        match candidates {
            Value::Object(map) => Self::filter(map),
            _ => Map::new(),
        }
    }

    #[must_use]
    pub fn to_extension_map(
        map: &Map<String, Value>,
    ) -> std::collections::BTreeMap<String, ExtensionValue> {
        let mut out = std::collections::BTreeMap::new();
        for (k, v) in map {
            let ext = match v {
                Value::Bool(b) => ExtensionValue::Bool(*b),
                Value::Number(n) => n
                    .as_i64()
                    .map(ExtensionValue::Int)
                    .unwrap_or(ExtensionValue::String(n.to_string())),
                Value::String(s) => ExtensionValue::String(s.clone()),
                _ => continue,
            };
            out.insert(k.clone(), ext);
        }
        out
    }
}

fn is_extension_name(name: &str) -> bool {
    !name.is_empty()
        && name
            .bytes()
            .all(|b| b.is_ascii_lowercase() || b.is_ascii_digit())
}
