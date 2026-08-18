//! Usage metric. PHP `Utopia\Usage\Metric`.

use serde_json::{json, Map, Value};

use crate::error::{Result, UsageError};

/// Structured usage metric with array-like access.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct Metric {
    data: Map<String, Value>,
}

impl Metric {
    pub const EVENT_COLUMNS: &'static [&'static str] = &[
        "path",
        "method",
        "status",
        "service",
        "resourceType",
        "resourceId",
        "resourceInternalId",
        "teamId",
        "teamInternalId",
        "country",
        "region",
        "hostname",
        "ip",
        "city",
        "continentCode",
        "subdivisions",
        "isp",
        "autonomousSystemNumber",
        "autonomousSystemOrganization",
        "connectionType",
        "connectionUsageType",
        "connectionOrganization",
        "osCode",
        "osName",
        "osVersion",
        "clientType",
        "clientCode",
        "clientName",
        "clientVersion",
        "clientEngine",
        "clientEngineVersion",
        "sdk",
        "sdkVersion",
        "deviceName",
        "deviceBrand",
        "deviceModel",
    ];

    pub const GAUGE_COLUMNS: &'static [&'static str] = &[
        "service",
        "resourceType",
        "teamId",
        "teamInternalId",
        "resourceId",
        "resourceInternalId",
        "ordinal",
    ];

    #[must_use]
    pub fn new(input: Map<String, Value>) -> Self {
        Self { data: input }
    }

    #[must_use]
    pub fn from_value(value: Value) -> Self {
        match value {
            Value::Object(map) => Self { data: map },
            _ => Self::default(),
        }
    }

    fn string_attr(&self, key: &str) -> Option<String> {
        match self.data.get(key) {
            Some(Value::String(s)) => Some(s.clone()),
            _ => None,
        }
    }

    #[must_use]
    pub fn get_id(&self) -> String {
        self.string_attr("$id").unwrap_or_default()
    }
    #[must_use]
    pub fn get_metric(&self) -> String {
        self.string_attr("metric").unwrap_or_default()
    }
    #[must_use]
    pub fn get_value(&self) -> Option<f64> {
        match self.data.get("value") {
            Some(Value::Number(n)) => n.as_f64().or_else(|| n.as_i64().map(|i| i as f64)),
            _ => None,
        }
    }
    #[must_use]
    pub fn get_type(&self) -> String {
        self.string_attr("type").unwrap_or_else(|| "event".into())
    }
    #[must_use]
    pub fn get_time(&self) -> Option<String> {
        self.string_attr("time")
    }
    #[must_use]
    pub fn get_path(&self) -> Option<String> {
        self.string_attr("path")
    }
    #[must_use]
    pub fn get_method(&self) -> Option<String> {
        self.string_attr("method")
    }
    #[must_use]
    pub fn get_status(&self) -> Option<String> {
        self.string_attr("status")
    }
    #[must_use]
    pub fn get_resource_type(&self) -> Option<String> {
        self.string_attr("resourceType")
    }
    #[must_use]
    pub fn get_resource_id(&self) -> Option<String> {
        self.string_attr("resourceId")
    }
    #[must_use]
    pub fn get_country(&self) -> Option<String> {
        self.string_attr("country")
    }
    #[must_use]
    pub fn get_service(&self) -> Option<String> {
        self.string_attr("service")
    }
    #[must_use]
    pub fn get_resource_internal_id(&self) -> Option<String> {
        self.string_attr("resourceInternalId")
    }
    #[must_use]
    pub fn get_ordinal(&self) -> Option<String> {
        self.string_attr("ordinal")
    }
    #[must_use]
    pub fn get_team_id(&self) -> Option<String> {
        self.string_attr("teamId")
    }
    #[must_use]
    pub fn get_team_internal_id(&self) -> Option<String> {
        self.string_attr("teamInternalId")
    }
    #[must_use]
    pub fn get_region(&self) -> Option<String> {
        self.string_attr("region")
    }
    #[must_use]
    pub fn get_hostname(&self) -> Option<String> {
        self.string_attr("hostname")
    }
    #[must_use]
    pub fn get_ip(&self) -> Option<String> {
        self.string_attr("ip")
    }
    #[must_use]
    pub fn get_os_code(&self) -> Option<String> {
        self.string_attr("osCode")
    }
    #[must_use]
    pub fn get_os_name(&self) -> Option<String> {
        self.string_attr("osName")
    }
    #[must_use]
    pub fn get_os_version(&self) -> Option<String> {
        self.string_attr("osVersion")
    }
    #[must_use]
    pub fn get_client_type(&self) -> Option<String> {
        self.string_attr("clientType")
    }
    #[must_use]
    pub fn get_client_code(&self) -> Option<String> {
        self.string_attr("clientCode")
    }
    #[must_use]
    pub fn get_client_name(&self) -> Option<String> {
        self.string_attr("clientName")
    }
    #[must_use]
    pub fn get_client_version(&self) -> Option<String> {
        self.string_attr("clientVersion")
    }
    #[must_use]
    pub fn get_client_engine(&self) -> Option<String> {
        self.string_attr("clientEngine")
    }
    #[must_use]
    pub fn get_client_engine_version(&self) -> Option<String> {
        self.string_attr("clientEngineVersion")
    }
    #[must_use]
    pub fn get_device_name(&self) -> Option<String> {
        self.string_attr("deviceName")
    }
    #[must_use]
    pub fn get_device_brand(&self) -> Option<String> {
        self.string_attr("deviceBrand")
    }
    #[must_use]
    pub fn get_device_model(&self) -> Option<String> {
        self.string_attr("deviceModel")
    }
    #[must_use]
    pub fn get_sdk(&self) -> Option<String> {
        self.string_attr("sdk")
    }
    #[must_use]
    pub fn get_sdk_version(&self) -> Option<String> {
        self.string_attr("sdkVersion")
    }
    #[must_use]
    pub fn get_tenant(&self) -> Option<String> {
        match self.data.get("tenant") {
            Some(Value::String(s)) => Some(s.clone()),
            Some(Value::Number(n)) => Some(n.to_string()),
            _ => None,
        }
    }

    #[must_use]
    pub fn get_attribute(&self, key: &str) -> Option<&Value> {
        self.data.get(key)
    }

    #[must_use]
    pub fn get_array_copy(&self) -> Map<String, Value> {
        self.data.clone()
    }

    fn string_column(id: &str, size: i64) -> Map<String, Value> {
        let mut m = Map::new();
        m.insert("$id".into(), json!(id));
        m.insert("type".into(), json!("string"));
        m.insert("size".into(), json!(size));
        m.insert("required".into(), json!(false));
        m.insert("signed".into(), json!(true));
        m.insert("array".into(), json!(false));
        m.insert("filters".into(), json!([]));
        m
    }

    fn core_schema() -> Vec<Map<String, Value>> {
        vec![
            {
                let mut m = Map::new();
                m.insert("$id".into(), json!("metric"));
                m.insert("type".into(), json!("string"));
                m.insert("size".into(), json!(255));
                m.insert("required".into(), json!(true));
                m.insert("signed".into(), json!(true));
                m.insert("array".into(), json!(false));
                m.insert("filters".into(), json!([]));
                m
            },
            {
                let mut m = Map::new();
                m.insert("$id".into(), json!("value"));
                m.insert("type".into(), json!("integer"));
                m.insert("size".into(), json!(0));
                m.insert("required".into(), json!(true));
                m.insert("signed".into(), json!(true));
                m.insert("array".into(), json!(false));
                m.insert("filters".into(), json!([]));
                m
            },
            {
                let mut m = Map::new();
                m.insert("$id".into(), json!("time"));
                m.insert("type".into(), json!("datetime"));
                m.insert("format".into(), json!(""));
                m.insert("size".into(), json!(0));
                m.insert("signed".into(), json!(true));
                m.insert("required".into(), json!(false));
                m.insert("array".into(), json!(false));
                m.insert("filters".into(), json!(["datetime"]));
                m
            },
        ]
    }

    #[must_use]
    pub fn get_event_schema() -> Vec<Map<String, Value>> {
        let sizes: &[(&str, i64)] = &[
            ("path", 1024),
            ("method", 16),
            ("status", 16),
            ("service", 256),
            ("resourceType", 256),
            ("resourceId", 255),
            ("resourceInternalId", 255),
            ("teamId", 255),
            ("teamInternalId", 255),
            ("country", 2),
            ("region", 64),
            ("hostname", 255),
            ("ip", 45),
            ("city", 256),
            ("continentCode", 2),
            ("subdivisions", 256),
            ("isp", 256),
            ("autonomousSystemNumber", 255),
            ("autonomousSystemOrganization", 256),
            ("connectionType", 256),
            ("connectionUsageType", 256),
            ("connectionOrganization", 256),
            ("osCode", 256),
            ("osName", 256),
            ("osVersion", 255),
            ("clientType", 256),
            ("clientCode", 256),
            ("clientName", 256),
            ("clientVersion", 255),
            ("clientEngine", 256),
            ("clientEngineVersion", 255),
            ("sdk", 256),
            ("sdkVersion", 255),
            ("deviceName", 256),
            ("deviceBrand", 256),
            ("deviceModel", 255),
        ];
        let mut schema = Self::core_schema();
        for (id, size) in sizes {
            schema.push(Self::string_column(id, *size));
        }
        schema
    }

    #[must_use]
    pub fn get_gauge_schema() -> Vec<Map<String, Value>> {
        let mut schema = Self::core_schema();
        for (id, size) in [
            ("service", 256),
            ("resourceType", 256),
            ("teamId", 255),
            ("teamInternalId", 255),
            ("resourceId", 255),
            ("resourceInternalId", 255),
            ("ordinal", 255),
        ] {
            schema.push(Self::string_column(id, size));
        }
        schema
    }

    #[must_use]
    pub fn get_schema() -> Vec<Map<String, Value>> {
        Self::get_event_schema()
    }

    #[must_use]
    pub fn get_event_indexes() -> Vec<Map<String, Value>> {
        let indexed = [
            "path",
            "method",
            "status",
            "service",
            "resourceType",
            "resourceId",
            "resourceInternalId",
            "teamId",
            "teamInternalId",
            "country",
            "region",
            "hostname",
            "ip",
            "osName",
            "clientType",
            "clientName",
            "deviceName",
        ];
        let set_indexed = [
            "status",
            "method",
            "country",
            "service",
            "clientType",
            "osName",
        ];
        indexed
            .into_iter()
            .map(|col| {
                let mut m = Map::new();
                m.insert("$id".into(), json!(format!("index-{col}")));
                m.insert("type".into(), json!("key"));
                m.insert("attributes".into(), json!([col]));
                m.insert(
                    "indexType".into(),
                    json!(if set_indexed.contains(&col) {
                        "set(0)"
                    } else {
                        "bloom_filter"
                    }),
                );
                if col == "path" {
                    m.insert("lengths".into(), json!([255]));
                }
                m
            })
            .collect()
    }

    #[must_use]
    pub fn get_gauge_indexes() -> Vec<Map<String, Value>> {
        let indexed = [
            "service",
            "resourceType",
            "resourceId",
            "resourceInternalId",
            "teamId",
            "teamInternalId",
            "ordinal",
        ];
        let set_indexed = ["service", "resourceType", "ordinal"];
        indexed
            .into_iter()
            .map(|col| {
                let mut m = Map::new();
                m.insert("$id".into(), json!(format!("index-{col}")));
                m.insert("type".into(), json!("key"));
                m.insert("attributes".into(), json!([col]));
                m.insert(
                    "indexType".into(),
                    json!(if set_indexed.contains(&col) {
                        "set(0)"
                    } else {
                        "bloom_filter"
                    }),
                );
                m
            })
            .collect()
    }

    #[must_use]
    pub fn get_indexes() -> Vec<Map<String, Value>> {
        Self::get_event_indexes()
    }

    pub fn extract_columns(tags: &Map<String, Value>, type_: &str) -> Result<Map<String, Value>> {
        let allowed: &[&str] = if type_ == "gauge" {
            Self::GAUGE_COLUMNS
        } else {
            Self::EVENT_COLUMNS
        };
        let mut remaining = tags.clone();
        let mut columns = Map::new();
        for col in allowed {
            let val = remaining.remove(*col);
            let coerced = match val {
                Some(Value::String(s)) if s.is_empty() => Value::Null,
                Some(Value::String(s)) => {
                    if *col == "country" || *col == "region" {
                        json!(s.to_lowercase())
                    } else {
                        Value::String(s)
                    }
                }
                Some(Value::Number(n)) => json!(n.to_string()),
                Some(Value::Bool(b)) => json!(if b { "1" } else { "" }),
                Some(_) | None => Value::Null,
            };
            columns.insert((*col).to_owned(), coerced);
        }
        if let Some((unknown, _)) = remaining.iter().next() {
            return Err(UsageError::message(format!(
                "Unknown column '{unknown}' for {type_}"
            )));
        }
        Ok(columns)
    }

    pub fn validate(data: &Map<String, Value>, type_: &str) -> Result<()> {
        let schema = if type_ == "gauge" {
            Self::get_gauge_schema()
        } else {
            Self::get_event_schema()
        };
        for attribute in schema {
            let attr_id = attribute
                .get("$id")
                .and_then(Value::as_str)
                .unwrap_or_default();
            let required = attribute
                .get("required")
                .and_then(Value::as_bool)
                .unwrap_or(false);
            let attr_type = attribute
                .get("type")
                .and_then(Value::as_str)
                .unwrap_or("string");
            let size = attribute.get("size").and_then(Value::as_i64).unwrap_or(0);
            if required && !data.contains_key(attr_id) {
                return Err(UsageError::message(format!(
                    "Required attribute '{attr_id}' is missing"
                )));
            }
            let Some(value) = data.get(attr_id) else {
                continue;
            };
            match attr_type {
                "string" => {
                    let Some(s) = value.as_str() else {
                        return Err(UsageError::message(format!(
                            "Attribute '{attr_id}' must be a string, got {}",
                            php_gettype(value)
                        )));
                    };
                    if size > 0 && s.len() as i64 > size {
                        return Err(UsageError::message(format!(
                            "Attribute '{attr_id}' exceeds maximum size of {size} characters"
                        )));
                    }
                }
                "integer" => {
                    if !value.is_i64() && !value.is_u64() {
                        return Err(UsageError::message(format!(
                            "Attribute '{attr_id}' must be an integer, got {}",
                            php_gettype(value)
                        )));
                    }
                }
                "datetime" => {
                    if let Some(s) = value.as_str() {
                        if chrono::NaiveDateTime::parse_from_str(s, "%Y-%m-%d %H:%M:%S").is_err()
                            && chrono::NaiveDateTime::parse_from_str(s, "%Y-%m-%d %H:%M:%S%.f")
                                .is_err()
                            && chrono::DateTime::parse_from_rfc3339(s).is_err()
                        {
                            return Err(UsageError::message(format!(
                                "Attribute '{attr_id}' is not a valid datetime string"
                            )));
                        }
                    } else if !value.is_string() {
                        return Err(UsageError::message(format!(
                            "Attribute '{attr_id}' must be a DateTime object or string, got {}",
                            php_gettype(value)
                        )));
                    }
                }
                _ => {}
            }
        }
        Ok(())
    }
}

impl std::ops::Index<&str> for Metric {
    type Output = Value;
    fn index(&self, index: &str) -> &Self::Output {
        self.data.get(index).unwrap_or(&Value::Null)
    }
}

pub(crate) fn php_gettype_pub(value: &Value) -> &'static str {
    php_gettype(value)
}

fn php_gettype(value: &Value) -> &'static str {
    match value {
        Value::Null => "NULL",
        Value::Bool(_) => "boolean",
        Value::Number(n) if n.is_i64() || n.is_u64() => "integer",
        Value::Number(_) => "double",
        Value::String(_) => "string",
        Value::Array(_) | Value::Object(_) => "array",
    }
}
