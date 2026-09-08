//! PHP `Utopia\Database\Validator\Structure`.

use std::collections::HashMap;
use std::sync::{Arc, Mutex as StdMutex};

use chrono::NaiveDateTime;
use once_cell::sync::Lazy;
use parking_lot::Mutex;
use serde_json::Value;
use utopia_validators::{Boolean, FloatValidator, Integer, Range, Text, Validator, ValueType};

use crate::constants::{
    MAX_BIG_INT, MAX_DOUBLE, MAX_INT, MAX_LONGTEXT_BYTES, MAX_MEDIUMTEXT_BYTES, MAX_TEXT_BYTES,
    METADATA, VAR_BIGINT, VAR_BOOLEAN, VAR_DATETIME, VAR_FLOAT, VAR_ID, VAR_INTEGER,
    VAR_LINESTRING, VAR_LONGTEXT, VAR_MEDIUMTEXT, VAR_OBJECT, VAR_POINT, VAR_POLYGON,
    VAR_RELATIONSHIP, VAR_STRING, VAR_TEXT, VAR_VARCHAR, VAR_VECTOR,
};
use crate::document::Document;
use crate::error::{DatabaseError, Result};
use crate::operator::Operator;
use crate::validator::datetime::Datetime as DatetimeValidator;
use crate::validator::{BigInt, ByteLength, ObjectValidator, Sequence, Spatial, Vector};
use crate::value::AttrValue;

type FormatFn = Arc<dyn Fn(&Document) -> Box<dyn Validator> + Send + Sync>;

static FORMATS: Lazy<StdMutex<HashMap<String, (FormatFn, String)>>> =
    Lazy::new(|| StdMutex::new(HashMap::new()));

/// PHP `Utopia\Database\Validator\Structure`.
#[derive(Debug)]
pub struct Structure {
    collection: Document,
    id_attribute_type: String,
    min_allowed_date: NaiveDateTime,
    max_allowed_date: NaiveDateTime,
    support_for_attributes: bool,
    support_unsigned_big_int: bool,
    current_document: Option<Document>,
    message: Mutex<String>,
}

impl Structure {
    pub fn new(
        collection: Document,
        id_attribute_type: impl Into<String>,
        min_allowed_date: NaiveDateTime,
        max_allowed_date: NaiveDateTime,
        support_for_attributes: bool,
        support_unsigned_big_int: bool,
        current_document: Option<Document>,
    ) -> Self {
        Self {
            collection,
            id_attribute_type: id_attribute_type.into(),
            min_allowed_date,
            max_allowed_date,
            support_for_attributes,
            support_unsigned_big_int,
            current_document,
            message: Mutex::new("General Error".into()),
        }
    }

    fn set_message(&self, message: impl Into<String>) {
        *self.message.lock() = message.into();
    }

    pub fn add_format(name: impl Into<String>, callback: FormatFn, type_: impl Into<String>) {
        FORMATS
            .lock()
            .unwrap_or_else(|e| e.into_inner())
            .insert(name.into(), (callback, type_.into()));
    }

    pub fn has_format(name: &str, type_: &str) -> bool {
        FORMATS
            .lock()
            .unwrap_or_else(|e| e.into_inner())
            .get(name)
            .is_some_and(|(_, t)| t == type_)
    }

    pub fn get_format(name: &str, type_: &str) -> Result<(FormatFn, String)> {
        let formats = FORMATS.lock().unwrap_or_else(|e| e.into_inner());
        match formats.get(name) {
            Some((cb, t)) if t == type_ => Ok((cb.clone(), t.clone())),
            Some((_, _t)) => Err(DatabaseError::database(format!(
                "Format \"{name}\" not available for attribute type \"{type_}\""
            ))),
            None => Err(DatabaseError::database(format!(
                "Unknown format validator \"{name}\""
            ))),
        }
    }

    pub fn remove_format(name: &str) {
        FORMATS
            .lock()
            .unwrap_or_else(|e| e.into_inner())
            .remove(name);
    }

    pub fn get_formats() -> HashMap<String, String> {
        FORMATS
            .lock()
            .unwrap_or_else(|e| e.into_inner())
            .iter()
            .map(|(k, (_, t))| (k.clone(), t.clone()))
            .collect()
    }

    fn builtin_attributes() -> Vec<Value> {
        vec![
            serde_json::json!({"$id":"$id","type":VAR_STRING,"size":255,"required":false,"signed":true,"array":false,"filters":[]}),
            serde_json::json!({"$id":"$sequence","type":VAR_ID,"size":0,"required":false,"signed":true,"array":false,"filters":[]}),
            serde_json::json!({"$id":"$collection","type":VAR_STRING,"size":255,"required":true,"signed":true,"array":false,"filters":[]}),
            serde_json::json!({"$id":"$tenant","type":VAR_ID,"size":0,"required":false,"default":null,"signed":true,"array":false,"filters":[]}),
            serde_json::json!({"$id":"$permissions","type":VAR_STRING,"size":67000,"required":false,"signed":true,"array":true,"filters":[]}),
            serde_json::json!({"$id":"$createdAt","type":VAR_DATETIME,"size":0,"required":true,"signed":false,"array":false,"filters":[]}),
            serde_json::json!({"$id":"$updatedAt","type":VAR_DATETIME,"size":0,"required":true,"signed":false,"array":false,"filters":[]}),
        ]
    }

    pub fn is_valid_document(&self, document: &Document) -> bool {
        if document.get_collection().is_empty() {
            self.set_message("Missing collection attribute $collection");
            return false;
        }
        if self.collection.get_id().is_empty() || self.collection.get_collection() != METADATA {
            self.set_message("Collection not found");
            return false;
        }
        let mut keys: indexmap::IndexMap<String, Value> = indexmap::IndexMap::new();
        let mut attributes = Self::builtin_attributes();
        if let AttrValue::Array(items) = document.get_attribute("attributes") {
            // not used
            let _ = items;
        }
        if let AttrValue::Array(items) = self.collection.get_attribute("attributes") {
            for v in items.values() {
                match v {
                    AttrValue::Document(d) => {
                        attributes.push(Value::Object(d.get_array_copy_json(&[], &[])));
                    }
                    AttrValue::Array(map) => {
                        let mut obj = serde_json::Map::new();
                        for (k, val) in map {
                            obj.insert(k.clone(), val.to_json());
                        }
                        attributes.push(Value::Object(obj));
                    }
                    other => attributes.push(other.to_json()),
                }
            }
        }
        let structure = document.get_array_copy(&[], &[]);
        if !self.check_required(&structure, &attributes, &mut keys) {
            return false;
        }
        if !self.check_unknown(&structure, &keys) {
            return false;
        }
        self.check_values(document, &structure, &keys)
    }

    fn check_required(
        &self,
        structure: &indexmap::IndexMap<String, AttrValue>,
        attributes: &[Value],
        keys: &mut indexmap::IndexMap<String, Value>,
    ) -> bool {
        if !self.support_for_attributes {
            return true;
        }
        for attribute in attributes {
            let name = attribute.get("$id").and_then(Value::as_str).unwrap_or("");
            let required = attribute
                .get("required")
                .and_then(Value::as_bool)
                .unwrap_or(false);
            keys.insert(name.to_owned(), attribute.clone());
            if required && !structure.contains_key(name) {
                self.set_message(format!("Missing required attribute \"{name}\""));
                return false;
            }
        }
        true
    }

    fn check_unknown(
        &self,
        structure: &indexmap::IndexMap<String, AttrValue>,
        keys: &indexmap::IndexMap<String, Value>,
    ) -> bool {
        if !self.support_for_attributes {
            return true;
        }
        for key in structure.keys() {
            if !keys.contains_key(key) {
                self.set_message(format!("Unknown attribute: \"{key}\""));
                return false;
            }
        }
        true
    }

    fn check_values(
        &self,
        document: &Document,
        structure: &indexmap::IndexMap<String, AttrValue>,
        keys: &indexmap::IndexMap<String, Value>,
    ) -> bool {
        for (key, value) in structure {
            if matches!(value, AttrValue::Operator(_)) {
                continue;
            }
            let attribute = keys
                .get(key)
                .cloned()
                .unwrap_or(Value::Object(Default::default()));
            let type_ = attribute.get("type").and_then(Value::as_str).unwrap_or("");
            let array = attribute
                .get("array")
                .and_then(Value::as_bool)
                .unwrap_or(false);
            let format = attribute
                .get("format")
                .and_then(Value::as_str)
                .unwrap_or("");
            let required = attribute
                .get("required")
                .and_then(Value::as_bool)
                .unwrap_or(false);
            let size = attribute.get("size").and_then(Value::as_u64).unwrap_or(0) as usize;
            let signed = attribute
                .get("signed")
                .and_then(Value::as_bool)
                .unwrap_or(true);
            if !required && value.is_null() {
                continue;
            }
            if type_ == VAR_RELATIONSHIP {
                continue;
            }
            let mut validators: Vec<Box<dyn Validator>> = Vec::new();
            match type_ {
                VAR_ID => validators.push(Box::new(Sequence::new(
                    &self.id_attribute_type,
                    attribute.get("$id").and_then(Value::as_str) == Some("$sequence"),
                ))),
                VAR_TEXT => {
                    validators.push(Box::new(ByteLength::new(size)));
                    validators.push(Box::new(ByteLength::new(MAX_TEXT_BYTES as usize)));
                }
                VAR_MEDIUMTEXT => {
                    validators.push(Box::new(ByteLength::new(size)));
                    validators.push(Box::new(ByteLength::new(MAX_MEDIUMTEXT_BYTES as usize)));
                }
                VAR_LONGTEXT => {
                    validators.push(Box::new(ByteLength::new(size)));
                    validators.push(Box::new(ByteLength::new(MAX_LONGTEXT_BYTES as usize)));
                }
                VAR_VARCHAR | VAR_STRING => validators.push(Box::new(Text::new(size))),
                VAR_INTEGER => {
                    let bits = if size >= 8 { 64 } else { 32 };
                    let unsigned = !signed && bits < 64;
                    validators.push(Box::new(Integer::new().bits(bits).unsigned(unsigned)));
                    let max = if bits == 64 { MAX_BIG_INT } else { MAX_INT };
                    let min = if signed { -max } else { 0 };
                    validators.push(Box::new(Range::integer(min, max)));
                }
                VAR_BIGINT => {
                    validators.push(Box::new(BigInt::new(signed, self.support_unsigned_big_int)));
                }
                VAR_FLOAT => {
                    validators.push(Box::new(FloatValidator::new()));
                    let min = if signed { -MAX_DOUBLE } else { 0.0 };
                    validators.push(Box::new(Range::new(min, MAX_DOUBLE)));
                }
                VAR_BOOLEAN => validators.push(Box::new(Boolean::new())),
                VAR_DATETIME => validators.push(Box::new(
                    DatetimeValidator::new(
                        self.min_allowed_date,
                        self.max_allowed_date,
                        false,
                        crate::validator::datetime::PRECISION_ANY,
                        0,
                    )
                    .unwrap_or_else(|_| DatetimeValidator::default_range()),
                )),
                VAR_OBJECT => validators.push(Box::new(ObjectValidator)),
                VAR_POINT | VAR_LINESTRING | VAR_POLYGON => {
                    validators.push(Box::new(Spatial::new(type_)));
                }
                VAR_VECTOR => validators.push(Box::new(Vector::new(
                    attribute.get("size").and_then(Value::as_u64).unwrap_or(0) as usize,
                ))),
                _ => {
                    if self.support_for_attributes {
                        self.set_message(format!("Unknown attribute type \"{type_}\""));
                        return false;
                    }
                }
            }
            let label = if format.is_empty() { "type" } else { "format" };
            if !format.is_empty() {
                if let Ok((cb, _)) = Self::get_format(format, type_) {
                    let attr_doc = Document::try_from_json(attribute.clone()).unwrap_or_default();
                    validators.push(cb(&attr_doc));
                }
            }
            if array {
                if !required && (value.as_array().is_some_and(|a| a.is_empty()) || value.is_null())
                {
                    continue;
                }
                if !value.is_list() {
                    self.set_message(format!("Attribute \"{key}\" must be an array"));
                    return false;
                }
                if let Some(items) = value.as_array() {
                    for (x, child) in items.values().enumerate() {
                        if !required && child.is_null() {
                            continue;
                        }
                        for validator in &validators {
                            if !validator.is_valid(&child.to_json()) {
                                self.set_message(format!(
                                    "Attribute \"{key}['{x}']\" has invalid {label}. {}",
                                    validator.description()
                                ));
                                return false;
                            }
                        }
                    }
                }
            } else {
                for validator in &validators {
                    if !validator.is_valid(&value.to_json()) {
                        self.set_message(format!(
                            "Attribute \"{key}\" has invalid {label}. {}",
                            validator.description()
                        ));
                        return false;
                    }
                }
            }
        }
        let _ = (
            document,
            self.current_document.as_ref(),
            Operator::is_operator,
        );
        true
    }
}

impl Validator for Structure {
    fn description(&self) -> String {
        format!("Invalid document structure: {}", self.message.lock())
    }

    fn value_type(&self) -> ValueType {
        ValueType::Array
    }

    fn is_valid(&self, value: &Value) -> bool {
        if let Ok(doc) = Document::try_from_json(value.clone()) {
            self.is_valid_document(&doc)
        } else {
            self.set_message("Value must be an instance of Document");
            false
        }
    }
}
