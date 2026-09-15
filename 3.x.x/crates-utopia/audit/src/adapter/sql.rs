//! Base SQL adapter. PHP `Utopia\Audit\Adapter\SQL`.

use serde_json::{json, Map, Value};
use utopia_database::constants::{INDEX_KEY, LENGTH_KEY, VAR_DATETIME, VAR_STRING};

use crate::adapter::Adapter;

/// Shared schema helpers for SQL-backed audit adapters.
pub trait SqlAdapter: Adapter {
    fn get_collection_name(&self) -> &'static str {
        COLLECTION
    }

    fn get_attributes(&self) -> Vec<Map<String, Value>> {
        default_attributes()
    }

    fn get_indexes(&self) -> Vec<Map<String, Value>> {
        default_indexes()
    }

    fn get_attribute_documents(
        &self,
    ) -> crate::error::Result<Vec<utopia_database::document::Document>> {
        self.get_attributes()
            .into_iter()
            .map(|m| {
                utopia_database::document::Document::try_from_json_object(m)
                    .map_err(|e| crate::error::AuditError::message(e.to_string()))
            })
            .collect()
    }

    fn get_index_documents(
        &self,
    ) -> crate::error::Result<Vec<utopia_database::document::Document>> {
        self.get_indexes()
            .into_iter()
            .map(|m| {
                utopia_database::document::Document::try_from_json_object(m)
                    .map_err(|e| crate::error::AuditError::message(e.to_string()))
            })
            .collect()
    }

    fn get_attribute(&self, id: &str) -> Option<Map<String, Value>> {
        self.get_attributes()
            .into_iter()
            .find(|a| a.get("$id").and_then(Value::as_str) == Some(id))
    }

    fn get_column_definition(&self, id: &str) -> crate::error::Result<String>;

    fn parse_resource(&self, resource: &str) -> ParsedResource {
        parse_resource(resource)
    }
}

pub const COLLECTION: &str = "audit";

/// Parsed `type/id[/type/id…]` resource path.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ParsedResource {
    pub resource_id: String,
    pub resource_type: String,
    pub resource_parent: String,
}

/// Parses alternating `<type>/<id>` resource paths.
#[must_use]
pub fn parse_resource(resource: &str) -> ParsedResource {
    let parts: Vec<&str> = resource.split('/').collect();
    let count = parts.len();
    let mut resource_id = resource.to_owned();
    let mut resource_type = String::new();
    let mut resource_parent = String::new();

    if count >= 2 && count % 2 == 0 {
        parts[count - 1].clone_into(&mut resource_id);
        parts[count - 2].clone_into(&mut resource_type);
        if count > 2 {
            resource_parent = parts[..count - 2].join("/");
        }
    }

    ParsedResource {
        resource_id,
        resource_type,
        resource_parent,
    }
}

#[must_use]
pub fn default_attributes() -> Vec<Map<String, Value>> {
    vec![
        attr("userId", VAR_STRING, LENGTH_KEY, false),
        attr("event", VAR_STRING, 255, true),
        attr("resource", VAR_STRING, 255, false),
        attr("userAgent", VAR_STRING, 65534, true),
        attr("ip", VAR_STRING, 45, true),
        {
            let mut m = attr("time", VAR_DATETIME, 0, false);
            m.insert("format".into(), json!(""));
            m.insert("filters".into(), json!(["datetime"]));
            m
        },
        {
            let mut m = attr("data", VAR_STRING, 16_777_216, false);
            m.insert("filters".into(), json!(["json"]));
            m
        },
    ]
}

#[must_use]
pub fn default_indexes() -> Vec<Map<String, Value>> {
    vec![
        index("idx_event", &["event"]),
        index("idx_userId_event", &["userId", "event"]),
        index("idx_resource_event", &["resource", "event"]),
        index("idx_time_desc", &["time"]),
    ]
}

fn attr(id: &str, type_: &str, size: i64, required: bool) -> Map<String, Value> {
    let mut m = Map::new();
    m.insert("$id".into(), json!(id));
    m.insert("type".into(), json!(type_));
    m.insert("size".into(), json!(size));
    m.insert("required".into(), json!(required));
    m.insert("signed".into(), json!(true));
    m.insert("array".into(), json!(false));
    m.insert("filters".into(), json!([]));
    m
}

fn index(id: &str, attributes: &[&str]) -> Map<String, Value> {
    let mut m = Map::new();
    m.insert("$id".into(), json!(id));
    m.insert("type".into(), json!(INDEX_KEY));
    m.insert("attributes".into(), json!(attributes));
    m
}
