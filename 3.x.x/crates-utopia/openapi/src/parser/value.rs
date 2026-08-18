//! Shape checks against a decoded document (PHP `Utopia\OpenAPI\Parser\Value`).

use crate::error::{InvalidSpecification, OpenApiError};
use crate::json::{Json, JsonNumber};
use indexmap::IndexMap;

#[derive(Debug)]
pub struct Value;

impl Value {
    /// PHP `Value::object`. Empty PHP arrays (empty JSON object or array) are objects.
    pub fn object<'a>(
        value: &'a Json,
        location: &str,
    ) -> Result<&'a IndexMap<String, Json>, OpenApiError> {
        match value {
            Json::Object(map) => Ok(map),
            Json::Array(items) if items.is_empty() => Ok(empty_map()),
            _ => Err(InvalidSpecification(format!("Expected an object at {location}")).into()),
        }
    }

    pub fn object_owned(
        value: Json,
        location: &str,
    ) -> Result<IndexMap<String, Json>, OpenApiError> {
        match value {
            Json::Object(map) => Ok(map),
            Json::Array(items) if items.is_empty() => Ok(IndexMap::new()),
            _ => Err(InvalidSpecification(format!("Expected an object at {location}")).into()),
        }
    }

    /// PHP `Value::list`. Empty PHP arrays are lists.
    pub fn list<'a>(value: &'a Json, location: &str) -> Result<&'a [Json], OpenApiError> {
        match value {
            Json::Array(items) => Ok(items),
            Json::Object(map) if map.is_empty() => Ok(&[]),
            _ => Err(InvalidSpecification(format!("Expected a list at {location}")).into()),
        }
    }

    pub fn required_string(
        data: &IndexMap<String, Json>,
        key: &str,
        location: &str,
    ) -> Result<String, OpenApiError> {
        match data.get(key) {
            Some(Json::String(s)) => Ok(s.clone()),
            _ => Err(InvalidSpecification(format!("Expected string {location}/{key}")).into()),
        }
    }

    pub fn optional_string(
        data: &IndexMap<String, Json>,
        key: &str,
    ) -> Result<Option<String>, OpenApiError> {
        match data.get(key) {
            None | Some(Json::Null) => Ok(None),
            Some(Json::String(s)) => Ok(Some(s.clone())),
            _ => Err(InvalidSpecification(format!("Expected '{key}' to be a string")).into()),
        }
    }

    pub fn nullable_int(value: &Json, location: &str) -> Result<Option<i64>, OpenApiError> {
        match value {
            Json::Null => Ok(None),
            Json::Number(n) if n.is_int() => n.as_i64().map(Some).ok_or_else(|| {
                InvalidSpecification(format!("Expected integer at {location}")).into()
            }),
            _ => Err(InvalidSpecification(format!("Expected integer at {location}")).into()),
        }
    }

    pub fn nullable_number(
        value: &Json,
        location: &str,
    ) -> Result<Option<crate::model::JsonNumberOrInt>, OpenApiError> {
        match value {
            Json::Null => Ok(None),
            Json::Number(JsonNumber::Int(i)) => Ok(Some(crate::model::JsonNumberOrInt::Int(*i))),
            Json::Number(JsonNumber::UInt(u)) => match i64::try_from(*u) {
                Ok(i) => Ok(Some(crate::model::JsonNumberOrInt::Int(i))),
                Err(_) => {
                    Err(InvalidSpecification(format!("Expected number at {location}")).into())
                }
            },
            Json::Number(JsonNumber::Float(f)) => {
                Ok(Some(crate::model::JsonNumberOrInt::Float(*f)))
            }
            _ => Err(InvalidSpecification(format!("Expected number at {location}")).into()),
        }
    }

    /// Keys that start with `x-` (case-insensitive), original key preserved.
    pub fn extensions(data: &IndexMap<String, Json>) -> IndexMap<String, Json> {
        data.iter()
            .filter(|(k, _)| k.to_ascii_lowercase().starts_with("x-"))
            .map(|(k, v)| (k.clone(), v.clone()))
            .collect()
    }
}

fn empty_map() -> &'static IndexMap<String, Json> {
    use std::sync::OnceLock;
    static EMPTY: OnceLock<IndexMap<String, Json>> = OnceLock::new();
    EMPTY.get_or_init(IndexMap::new)
}
