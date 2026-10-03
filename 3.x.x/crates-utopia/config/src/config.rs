use crate::error::LoadError;
use crate::key::{FieldSpec, KeySpec};
use crate::parser::Parser;
use crate::source::Source;
use serde_json::{Map, Value};
use std::collections::HashMap;

/// Result of resolving a configuration key.
#[derive(Debug, Clone, PartialEq)]
pub enum ResolvedValue {
    /// Key is present (value may be JSON `null`).
    Found(Value),
    /// Key is genuinely absent.
    Missing,
}

/// Configuration loader with key resolution and optional validation.
#[derive(Debug, Clone, Copy, Default)]
pub struct Config;

impl Config {
    /// Load configuration from `source` using `parser`, returning the full map.
    pub fn load_map<S: Source + ?Sized, P: Parser + ?Sized>(
        source: &S,
        parser: &P,
    ) -> Result<Map<String, Value>, LoadError> {
        let content = source.contents().ok_or(LoadError::NullContents)?;
        parser.parse(&content, &[]).map_err(LoadError::from)
    }

    /// Load configuration and apply `keys` validation rules.
    ///
    /// Returns a map of key name to resolved value for keys that were found or required.
    /// Optional keys that are missing are omitted.
    pub fn load_with<S: Source + ?Sized, P: Parser + ?Sized>(
        source: &S,
        parser: &P,
        keys: &[KeySpec],
    ) -> Result<HashMap<String, Value>, LoadError> {
        let content = source.contents().ok_or(LoadError::NullContents)?;
        let data = parser.parse(&content, keys)?;

        if keys.is_empty() {
            return Ok(data.into_iter().collect());
        }

        let mut loaded = HashMap::new();
        for key in keys {
            match resolve_value(&data, &key.name) {
                ResolvedValue::Missing => {
                    if key.required {
                        return Err(LoadError::MissingRequired(key.name.clone()));
                    }
                }
                ResolvedValue::Found(value) => {
                    if !key.validator.is_valid(&value) {
                        return Err(LoadError::InvalidValue {
                            key: key.name.clone(),
                            description: key.validator.description(),
                        });
                    }
                    loaded.insert(key.name.clone(), value);
                }
            }
        }

        Ok(loaded)
    }

    /// Load configuration using a tree of [`FieldSpec`] values, including nested groups.
    pub fn load_struct<S: Source + ?Sized, P: Parser + ?Sized>(
        source: &S,
        parser: &P,
        fields: &[FieldSpec],
    ) -> Result<Map<String, Value>, LoadError> {
        let content = source.contents().ok_or(LoadError::NullContents)?;
        let data = parser.parse(&content, &[])?;
        load_fields(&data, fields)
    }
}

/// Resolve a configuration value by exact key or dot notation.
pub fn resolve_value(data: &Map<String, Value>, key: &str) -> ResolvedValue {
    if data.contains_key(key) {
        return ResolvedValue::Found(data[key].clone());
    }

    let parts: Vec<&str> = key.split('.').collect();
    resolve_value_recursive(data, &parts, 0)
}

fn resolve_value_recursive(
    data: &Map<String, Value>,
    parts: &[&str],
    index: usize,
) -> ResolvedValue {
    if index >= parts.len() {
        return ResolvedValue::Missing;
    }

    if index == parts.len() - 1 {
        return data
            .get(parts[index])
            .cloned()
            .map_or(ResolvedValue::Missing, ResolvedValue::Found);
    }

    for length in 1..=parts.len() - index {
        let compound = parts[index..index + length].join(".");
        if let Some(value) = data.get(&compound) {
            if index + length == parts.len() {
                return ResolvedValue::Found(value.clone());
            }
            if let Value::Object(nested) = value {
                let result = resolve_value_recursive(nested, parts, index + length);
                if result != ResolvedValue::Missing {
                    return result;
                }
            }
        }
    }

    ResolvedValue::Missing
}

fn load_fields(
    data: &Map<String, Value>,
    fields: &[FieldSpec],
) -> Result<Map<String, Value>, LoadError> {
    let mut loaded = Map::new();

    for field in fields {
        match field {
            FieldSpec::Key(key) => match resolve_value(data, &key.name) {
                ResolvedValue::Missing => {
                    if key.required {
                        return Err(LoadError::MissingRequired(key.name.clone()));
                    }
                }
                ResolvedValue::Found(value) => {
                    if !key.validator.is_valid(&value) {
                        return Err(LoadError::InvalidValue {
                            key: key.name.clone(),
                            description: key.validator.description(),
                        });
                    }
                    loaded.insert(key.name.clone(), value);
                }
            },
            FieldSpec::Nested {
                key,
                required,
                fields,
            } => match resolve_value(data, key) {
                ResolvedValue::Missing => {
                    if *required {
                        return Err(LoadError::MissingRequired(key.clone()));
                    }
                }
                ResolvedValue::Found(Value::Object(nested)) => {
                    let nested_loaded = load_fields(&nested, fields.as_slice())?;
                    loaded.insert(key.clone(), Value::Object(nested_loaded));
                }
                ResolvedValue::Found(_) => {
                    return Err(LoadError::InvalidValue {
                        key: key.clone(),
                        description: "nested config must be an object".into(),
                    });
                }
            },
        }
    }

    Ok(loaded)
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn exact_key_match() {
        let mut data = Map::new();
        data.insert("key".into(), json!("value"));
        assert_eq!(
            resolve_value(&data, "key"),
            ResolvedValue::Found(json!("value"))
        );
    }

    #[test]
    fn null_is_present() {
        let mut data = Map::new();
        data.insert("name".into(), Value::Null);
        assert_eq!(
            resolve_value(&data, "name"),
            ResolvedValue::Found(Value::Null)
        );
    }

    #[test]
    fn nested_dot_notation() {
        let data = json!({
            "db": {
                "host": "docker.internal",
                "config": { "tls": true }
            }
        })
        .as_object()
        .unwrap()
        .clone();

        assert_eq!(
            resolve_value(&data, "db.host"),
            ResolvedValue::Found(json!("docker.internal"))
        );
        assert_eq!(
            resolve_value(&data, "db.config.tls"),
            ResolvedValue::Found(json!(true))
        );
    }

    #[test]
    fn dotted_key_in_flat_map() {
        let data = json!({
            "db.host": "docker.internal",
            "db.config.tls": true
        })
        .as_object()
        .unwrap()
        .clone();

        assert_eq!(
            resolve_value(&data, "db.host"),
            ResolvedValue::Found(json!("docker.internal"))
        );
        assert_eq!(
            resolve_value(&data, "db.config.tls"),
            ResolvedValue::Found(json!(true))
        );
    }
}
