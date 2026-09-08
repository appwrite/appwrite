use std::collections::BTreeMap;

use base64::engine::general_purpose::STANDARD as BASE64;
use base64::Engine;
use serde_json::{Map, Value};
use time::{OffsetDateTime, UtcOffset};

use crate::CloudEventError;

/// PHP `CloudEvent::TIME_FORMAT` (`Y-m-d\TH:i:s.v\Z`).
pub const TIME_FORMAT: &str = "Y-m-d\\TH:i:s.v\\Z";

const RESERVED: &[&str] = &[
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

/// PHP extension attribute value: boolean, integer, or string.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum ExtensionValue {
    /// PHP `bool`.
    Bool(bool),
    /// PHP `int`.
    Int(i64),
    /// PHP `string`.
    String(String),
}

impl From<bool> for ExtensionValue {
    fn from(value: bool) -> Self {
        Self::Bool(value)
    }
}

impl From<i64> for ExtensionValue {
    fn from(value: i64) -> Self {
        Self::Int(value)
    }
}

impl From<i32> for ExtensionValue {
    fn from(value: i32) -> Self {
        Self::Int(i64::from(value))
    }
}

impl From<String> for ExtensionValue {
    fn from(value: String) -> Self {
        Self::String(value)
    }
}

impl From<&str> for ExtensionValue {
    fn from(value: &str) -> Self {
        Self::String(value.to_owned())
    }
}

impl ExtensionValue {
    fn to_json(&self) -> Value {
        match self {
            Self::Bool(v) => Value::Bool(*v),
            Self::Int(v) => Value::Number((*v).into()),
            Self::String(v) => Value::String(v.clone()),
        }
    }

    fn from_json(value: &Value) -> Option<Self> {
        match value {
            Value::Bool(v) => Some(Self::Bool(*v)),
            Value::Number(n) => n.as_i64().map(Self::Int),
            Value::String(v) => Some(Self::String(v.clone())),
            _ => None,
        }
    }
}

/// PHP `Utopia\CloudEvents\CloudEvent`.
#[derive(Debug, Clone, PartialEq)]
pub struct CloudEvent {
    /// PHP `$type`.
    pub r#type: String,
    /// PHP `$source`.
    pub source: String,
    /// PHP `$id`.
    pub id: String,
    /// PHP `$specversion` (default `"1.0"`).
    pub specversion: String,
    /// PHP `$subject`.
    pub subject: Option<String>,
    /// PHP `$time`.
    pub time: Option<String>,
    /// PHP `$datacontenttype` (constructor default `"application/json"`).
    pub datacontenttype: Option<String>,
    /// PHP `$data` (JSON-compatible). Binary payloads use [`Self::data_binary`].
    pub data: Value,
    /// PHP `$dataschema`.
    pub dataschema: Option<String>,
    /// PHP `$extensions`. Insertion order is not preserved ([`BTreeMap`]).
    pub extensions: BTreeMap<String, ExtensionValue>,
    /// Non-UTF-8 PHP string `data`, carried as JSON `data_base64`.
    pub data_binary: Option<Vec<u8>>,
}

impl CloudEvent {
    /// PHP `__construct`.
    #[allow(clippy::too_many_arguments)]
    pub fn new(
        type_name: impl Into<String>,
        source: impl Into<String>,
        id: impl Into<String>,
        specversion: impl Into<String>,
        subject: Option<String>,
        time: Option<String>,
        datacontenttype: Option<String>,
        data: Value,
        dataschema: Option<String>,
        extensions: BTreeMap<String, ExtensionValue>,
    ) -> Result<Self, CloudEventError> {
        for name in extensions.keys() {
            assert_valid_extension_name(name)?;
        }
        Ok(Self {
            r#type: type_name.into(),
            source: source.into(),
            id: id.into(),
            specversion: specversion.into(),
            subject,
            time,
            datacontenttype,
            data,
            dataschema,
            extensions,
            data_binary: None,
        })
    }

    /// Convenience constructor matching PHP named args with defaults.
    pub fn create(
        type_name: impl Into<String>,
        source: impl Into<String>,
        id: impl Into<String>,
    ) -> Self {
        Self {
            r#type: type_name.into(),
            source: source.into(),
            id: id.into(),
            specversion: "1.0".into(),
            subject: None,
            time: None,
            datacontenttype: Some("application/json".into()),
            data: Value::Null,
            dataschema: None,
            extensions: BTreeMap::new(),
            data_binary: None,
        }
    }

    /// PHP constructor `data:` with a non-UTF-8 string.
    #[must_use]
    pub fn with_binary_data(mut self, bytes: Vec<u8>) -> Self {
        self.data = Value::Null;
        self.data_binary = Some(bytes);
        self
    }

    /// PHP `CloudEvent::now()`.
    #[must_use]
    pub fn now() -> String {
        let now = OffsetDateTime::now_utc();
        format_millis_z(now)
    }

    /// PHP `fromArray`.
    pub fn from_array(array: &Map<String, Value>) -> Result<Self, CloudEventError> {
        for field in ["specversion", "type", "source", "id"] {
            match array.get(field) {
                Some(Value::String(s)) if !s.is_empty() => {}
                _ => {
                    return Err(CloudEventError::invalid(format!(
                        "Missing required field: {field}"
                    )));
                }
            }
        }
        let specversion = array["specversion"].as_str().unwrap_or_default().to_owned();
        if specversion != "1.0" {
            return Err(CloudEventError::invalid(format!(
                "Unsupported CloudEvents spec version: {specversion}"
            )));
        }
        let mut extensions = BTreeMap::new();
        for (name, value) in array {
            if RESERVED.contains(&name.as_str()) || value.is_null() {
                continue;
            }
            let Some(ext) = ExtensionValue::from_json(value) else {
                return Err(CloudEventError::invalid(format!(
                    "Extension attribute \"{name}\" must be a boolean, integer or string"
                )));
            };
            assert_valid_extension_name(name)?;
            extensions.insert(name.clone(), ext);
        }
        Self::new(
            array["type"].as_str().unwrap_or_default(),
            array["source"].as_str().unwrap_or_default(),
            array["id"].as_str().unwrap_or_default(),
            specversion,
            string_opt(array.get("subject")),
            string_opt(array.get("time")),
            string_opt(array.get("datacontenttype")),
            array.get("data").cloned().unwrap_or(Value::Null),
            string_opt(array.get("dataschema")),
            extensions,
        )
    }

    /// PHP `toArray`.
    #[must_use]
    pub fn to_array(&self) -> Map<String, Value> {
        let mut array = Map::new();
        array.insert(
            "specversion".into(),
            Value::String(self.specversion.clone()),
        );
        array.insert("type".into(), Value::String(self.r#type.clone()));
        array.insert("source".into(), Value::String(self.source.clone()));
        array.insert("id".into(), Value::String(self.id.clone()));
        if let Some(subject) = &self.subject {
            array.insert("subject".into(), Value::String(subject.clone()));
        }
        if let Some(time) = &self.time {
            array.insert("time".into(), Value::String(time.clone()));
        }
        if let Some(datacontenttype) = &self.datacontenttype {
            array.insert(
                "datacontenttype".into(),
                Value::String(datacontenttype.clone()),
            );
        }
        if let Some(dataschema) = &self.dataschema {
            array.insert("dataschema".into(), Value::String(dataschema.clone()));
        }
        if self.data_binary.is_none() && !self.data.is_null() {
            array.insert("data".into(), self.data.clone());
        }
        for (name, value) in &self.extensions {
            array.insert(name.clone(), value.to_json());
        }
        array
    }

    /// PHP `fromJson`.
    pub fn from_json(json: &str) -> Result<Self, CloudEventError> {
        let raw: Value = serde_json::from_str(json)
            .map_err(|e| CloudEventError::invalid(format!("Invalid CloudEvent JSON: {e}")))?;
        let Value::Object(mut decoded) = raw else {
            return Err(CloudEventError::invalid(
                "CloudEvent JSON must decode to an object",
            ));
        };
        if decoded.contains_key("data_base64") {
            if decoded.contains_key("data") {
                return Err(CloudEventError::invalid(
                    "CloudEvent must not contain both data and data_base64",
                ));
            }
            let Some(Value::String(b64)) = decoded.remove("data_base64") else {
                return Err(CloudEventError::invalid("data_base64 must be a string"));
            };
            let binary = BASE64
                .decode(b64.as_bytes())
                .map_err(|_| CloudEventError::invalid("data_base64 must be valid Base64"))?;
            let mut event = Self::from_array(&decoded)?;
            event.data = Value::Null;
            event.data_binary = Some(binary);
            return Ok(event);
        }
        Self::from_array(&decoded)
    }

    /// PHP `toJson`.
    pub fn to_json(&self) -> Result<String, CloudEventError> {
        let mut array = self.to_array();
        if let Some(binary) = &self.data_binary {
            array.remove("data");
            array.insert("data_base64".into(), Value::String(BASE64.encode(binary)));
        } else if let Value::String(s) = &self.data {
            if !s.is_empty() && std::str::from_utf8(s.as_bytes()).is_err() {
                array.remove("data");
                array.insert(
                    "data_base64".into(),
                    Value::String(BASE64.encode(s.as_bytes())),
                );
            }
        }
        serde_json::to_string(&Value::Object(array)).map_err(|e| {
            CloudEventError::invalid(format!("Unable to encode CloudEvent as JSON: {e}"))
        })
    }

    /// PHP `validate`.
    pub fn validate(&self) -> Result<bool, CloudEventError> {
        if self.specversion != "1.0" {
            return Err(CloudEventError::invalid(format!(
                "Unsupported CloudEvents spec version: {}",
                self.specversion
            )));
        }
        if self.r#type.is_empty() {
            return Err(CloudEventError::invalid("Event type is required"));
        }
        if self.source.is_empty() {
            return Err(CloudEventError::invalid("Event source is required"));
        }
        if self.id.is_empty() {
            return Err(CloudEventError::invalid("Event id is required"));
        }
        if self.subject.as_deref() == Some("") {
            return Err(CloudEventError::invalid(
                "Event subject must not be empty when present",
            ));
        }
        if self.time.as_deref() == Some("") {
            return Err(CloudEventError::invalid(
                "Event time must not be empty when present",
            ));
        }
        if let Some(ct) = &self.datacontenttype {
            if ct.trim().is_empty() {
                return Err(CloudEventError::invalid(
                    "Event datacontenttype must not be empty when present",
                ));
            }
        }
        if self.dataschema.as_deref() == Some("") {
            return Err(CloudEventError::invalid(
                "Event dataschema must not be empty when present",
            ));
        }
        Ok(true)
    }
}

fn string_opt(value: Option<&Value>) -> Option<String> {
    match value {
        Some(Value::String(s)) => Some(s.clone()),
        _ => None,
    }
}

fn assert_valid_extension_name(name: &str) -> Result<(), CloudEventError> {
    if !name
        .chars()
        .all(|c| c.is_ascii_lowercase() || c.is_ascii_digit())
        || name.is_empty()
    {
        return Err(CloudEventError::invalid(format!(
            "Extension attribute name must contain only lowercase letters and digits: {name}"
        )));
    }
    if RESERVED.contains(&name) {
        return Err(CloudEventError::invalid(format!(
            "Extension attribute name conflicts with a core attribute: {name}"
        )));
    }
    Ok(())
}

fn format_millis_z(now: OffsetDateTime) -> String {
    let utc = now.to_offset(UtcOffset::UTC);
    format!(
        "{:04}-{:02}-{:02}T{:02}:{:02}:{:02}.{:03}Z",
        utc.year(),
        u8::from(utc.month()),
        utc.day(),
        utc.hour(),
        utc.minute(),
        utc.second(),
        utc.millisecond()
    )
}
