//! Audit log entry. PHP `Utopia\Audit\Log`.

use serde_json::{Map, Value};

/// Structured audit log with array-like attribute access.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct Log {
    data: Map<String, Value>,
}

impl Log {
    /// PHP `__construct(array $input = [])`.
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

    #[must_use]
    pub fn get_id(&self) -> String {
        string_attr(&self.data, "$id").unwrap_or_default()
    }

    #[must_use]
    pub fn get_user_id(&self) -> Option<String> {
        string_attr(&self.data, "userId")
    }

    #[must_use]
    pub fn get_actor_id(&self) -> Option<String> {
        string_attr(&self.data, "actorId")
    }

    #[must_use]
    pub fn get_actor_type(&self) -> Option<String> {
        string_attr(&self.data, "actorType")
    }

    #[must_use]
    pub fn get_actor_internal_id(&self) -> Option<String> {
        string_attr(&self.data, "actorInternalId")
    }

    #[must_use]
    pub fn get_event(&self) -> String {
        string_attr(&self.data, "event").unwrap_or_default()
    }

    #[must_use]
    pub fn get_resource(&self) -> String {
        string_attr(&self.data, "resource").unwrap_or_default()
    }

    #[must_use]
    pub fn get_sdk(&self) -> Option<String> {
        string_attr(&self.data, "sdk")
    }

    #[must_use]
    pub fn get_sdk_version(&self) -> Option<String> {
        string_attr(&self.data, "sdkVersion")
    }

    #[must_use]
    pub fn get_os_code(&self) -> Option<String> {
        string_attr(&self.data, "osCode")
    }

    #[must_use]
    pub fn get_os_name(&self) -> Option<String> {
        string_attr(&self.data, "osName")
    }

    #[must_use]
    pub fn get_os_version(&self) -> Option<String> {
        string_attr(&self.data, "osVersion")
    }

    #[must_use]
    pub fn get_client_type(&self) -> Option<String> {
        string_attr(&self.data, "clientType")
    }

    #[must_use]
    pub fn get_client_code(&self) -> Option<String> {
        string_attr(&self.data, "clientCode")
    }

    #[must_use]
    pub fn get_client_name(&self) -> Option<String> {
        string_attr(&self.data, "clientName")
    }

    #[must_use]
    pub fn get_client_version(&self) -> Option<String> {
        string_attr(&self.data, "clientVersion")
    }

    #[must_use]
    pub fn get_client_engine(&self) -> Option<String> {
        string_attr(&self.data, "clientEngine")
    }

    #[must_use]
    pub fn get_client_engine_version(&self) -> Option<String> {
        string_attr(&self.data, "clientEngineVersion")
    }

    #[must_use]
    pub fn get_device_name(&self) -> Option<String> {
        string_attr(&self.data, "deviceName")
    }

    #[must_use]
    pub fn get_device_brand(&self) -> Option<String> {
        string_attr(&self.data, "deviceBrand")
    }

    #[must_use]
    pub fn get_device_model(&self) -> Option<String> {
        string_attr(&self.data, "deviceModel")
    }

    #[must_use]
    pub fn get_user_agent(&self) -> String {
        string_attr(&self.data, "userAgent").unwrap_or_default()
    }

    #[must_use]
    pub fn get_ip(&self) -> String {
        string_attr(&self.data, "ip").unwrap_or_default()
    }

    #[must_use]
    pub fn get_time(&self) -> String {
        string_attr(&self.data, "time").unwrap_or_default()
    }

    #[must_use]
    pub fn get_data(&self) -> Map<String, Value> {
        match self.data.get("data") {
            Some(Value::Object(map)) => map.clone(),
            Some(Value::Null) | None => Map::new(),
            Some(other) => {
                if let Ok(Value::Object(map)) = serde_json::from_str(&other.to_string()) {
                    map
                } else {
                    Map::new()
                }
            }
        }
    }

    #[must_use]
    pub fn get_tenant(&self) -> Option<i64> {
        match self.data.get("tenant") {
            Some(Value::Number(n)) => n.as_i64(),
            Some(Value::String(s)) => s.parse().ok(),
            _ => None,
        }
    }

    #[must_use]
    pub fn get_attribute(&self, key: &str) -> Option<&Value> {
        self.data.get(key)
    }

    #[must_use]
    pub fn get_attribute_or<'a>(&'a self, key: &str, default: &'a Value) -> &'a Value {
        self.data.get(key).unwrap_or(default)
    }

    pub fn set_attribute(&mut self, key: impl Into<String>, value: Value) -> &mut Self {
        self.data.insert(key.into(), value);
        self
    }

    pub fn remove_attribute(&mut self, key: &str) -> &mut Self {
        self.data.remove(key);
        self
    }

    #[must_use]
    pub fn is_set(&self, key: &str) -> bool {
        self.data.contains_key(key)
    }

    #[must_use]
    pub fn get_array_copy(&self) -> Map<String, Value> {
        self.data.clone()
    }

    #[must_use]
    pub fn as_map(&self) -> &Map<String, Value> {
        &self.data
    }
}

impl std::ops::Index<&str> for Log {
    type Output = Value;

    fn index(&self, index: &str) -> &Self::Output {
        self.data.get(index).unwrap_or(&Value::Null)
    }
}

fn string_attr(data: &Map<String, Value>, key: &str) -> Option<String> {
    match data.get(key) {
        Some(Value::String(s)) => Some(s.clone()),
        _ => None,
    }
}
